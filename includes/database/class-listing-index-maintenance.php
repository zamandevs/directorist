<?php
/**
 * Automatic, resumable maintenance for derived listing indexes.
 *
 * @since 8.9.0
 */

namespace Directorist\database;

defined( 'ABSPATH' ) || exit;

class Listing_Index_Maintenance {
    const LOCK_OPTION = 'directorist_listing_index_process_lock';

    const RETRY_AFTER_OPTION = 'directorist_listing_index_retry_after';

    private static $hooks_registered = false;

    private static $background_process;

    public static function register_hooks() {
        if ( self::$hooks_registered ) {
            return;
        }

        self::$hooks_registered = true;

        if ( wp_doing_cron() || self::is_background_request() ) {
            self::background_process();
        }

        add_action( 'admin_init', [ __CLASS__, 'schedule_if_needed' ], 100 );
        add_action( 'directorist_listing_index_schema_missing', [ __CLASS__, 'schedule_if_needed' ] );
        add_action( 'delete_term', [ __CLASS__, 'delete_directory_state' ], 100, 3 );
    }

    public static function schedule_if_needed() {
        if ( ! self::has_pending_work() ) {
            return false;
        }

        return self::schedule();
    }

    public static function schedule() {
        return self::background_process()->start();
    }

    public static function background_process() {
        if ( ! self::$background_process ) {
            self::$background_process = new \Directorist\Listing_Index_Background_Process();
        }

        return self::$background_process;
    }

    public static function has_pending_work() {
        if ( Listing_Index_Schema::repair_required() ) {
            return true;
        }

        if ( ! Listing_Index_Schema::is_compatible() ) {
            return false;
        }

        return in_array( Listing_Index_Schema::status(), [ Listing_Index_Schema::STATUS_NEEDS_REBUILD, Listing_Index_Schema::STATUS_BUILDING ], true ) || Listing_Index_Directory_State::has_queued();
    }

    /**
     * Process one durable build or verification batch.
     *
     * @return bool Whether another batch remains.
     */
    public static function process_next_batch() {
        if ( ! self::has_pending_work() ) {
            delete_option( self::RETRY_AFTER_OPTION );
            return false;
        }

        if ( self::is_deferred() ) {
            return true;
        }

        $token = self::acquire_lock();

        if ( ! $token ) {
            self::defer( 5 );
            return true;
        }

        delete_option( self::RETRY_AFTER_OPTION );

        try {
            if ( Listing_Index_Schema::repair_required() && ! Listing_Index_Schema::repair_missing_tables() ) {
                self::defer( MINUTE_IN_SECONDS );
                return true;
            }

            if ( ! Listing_Index_Schema::is_compatible() ) {
                return false;
            }

            $handled_global = self::process_global_rebuild();

            if ( ! $handled_global ) {
                $state = Listing_Index_Directory_State::next_queued();

                if ( $state ) {
                    self::process_directory( $state );
                }
            }
        } finally {
            self::release_lock( $token );
        }

        if ( ! self::has_pending_work() ) {
            delete_option( self::RETRY_AFTER_OPTION );
            return false;
        }

        return true;
    }

    public static function is_deferred() {
        return time() < (int) get_option( self::RETRY_AFTER_OPTION, 0 );
    }

    public static function delete_directory_state( $term_id, $tt_id, $taxonomy ) {
        unset( $tt_id );

        if ( ATBDP_DIRECTORY_TYPE === $taxonomy && Listing_Index_Schema::is_compatible() ) {
            Listing_Index_Directory_State::delete( $term_id );
        }
    }

    private static function process_global_rebuild() {
        $status = Listing_Index_Schema::status();

        if ( Listing_Index_Schema::STATUS_NEEDS_REBUILD === $status && ! Listing_Index::rebuild_start() ) {
            return true;
        }

        if ( ! in_array( Listing_Index_Schema::status(), [ Listing_Index_Schema::STATUS_BUILDING ], true ) ) {
            return false;
        }

        $phase  = get_option( Listing_Index::REBUILD_PHASE_OPTION, 'build' );
        $cursor = (int) get_option( 'directorist_listing_index_rebuild_cursor', 0 );

        if ( 'verify' === $phase ) {
            $batch = Listing_Index::rebuild_verify_batch( $cursor, self::batch_size() );

            if ( $batch['done'] ) {
                Listing_Index::rebuild_finish_incremental();
            }

            return true;
        }

        $batch = Listing_Index::rebuild_batch( $cursor, self::batch_size() );

        if ( $batch['done'] ) {
            Listing_Index::rebuild_begin_verification();
        }

        return true;
    }

    private static function process_directory( array $state ) {
        $directory_id = (int) $state['directory_id'];
        $generation   = (int) $state['pending_generation'];

        if ( ! $directory_id || ! $generation ) {
            return;
        }

        if ( Listing_Index_Directory_State::STATUS_PENDING === $state['status'] ) {
            Listing_Index_Directory_State::mark_building( $directory_id, $generation );
            $state = Listing_Index_Directory_State::get( $directory_id, true );
        }

        if ( Listing_Index_Directory_State::STATUS_BUILDING !== $state['status'] || $generation !== (int) $state['pending_generation'] ) {
            return;
        }

        if ( 'build' === $state['phase'] ) {
            $batch = self::directory_listing_ids( $directory_id, $state['last_listing_id'], $state['target_id'] );

            foreach ( $batch as $listing_id ) {
                Listing_Index::sync_listing_generation( $listing_id, $directory_id, $generation );
                clean_post_cache( $listing_id );
            }

            $cursor = $batch ? (int) end( $batch ) : (int) $state['last_listing_id'];

            if ( ! Listing_Index_Directory_State::advance( $directory_id, $generation, $cursor, count( $batch ) ) ) {
                return;
            }

            if ( count( $batch ) >= self::batch_size() && $cursor < (int) $state['target_id'] ) {
                return;
            }

            if ( ! Listing_Index_Directory_State::begin_verification( $directory_id, $generation ) ) {
                return;
            }

            $state = Listing_Index_Directory_State::get( $directory_id, true );
        }

        if ( 'verify' !== $state['phase'] ) {
            return;
        }

        $batch  = self::directory_listing_ids( $directory_id, $state['last_listing_id'], $state['target_id'] );
        $errors = 0;

        foreach ( $batch as $listing_id ) {
            if ( ! Listing_Index::listing_generation_matches( $listing_id, $directory_id, $generation ) ) {
                $repaired = Listing_Index::sync_listing_generation( $listing_id, $directory_id, $generation );

                if ( ! $repaired || ! Listing_Index::listing_generation_matches( $listing_id, $directory_id, $generation ) ) {
                    ++$errors;
                }
            }

            clean_post_cache( $listing_id );
        }

        $cursor = $batch ? (int) end( $batch ) : (int) $state['last_listing_id'];

        if ( ! Listing_Index_Directory_State::advance_verification( $directory_id, $generation, $cursor, count( $batch ), $errors ) ) {
            return;
        }

        if ( count( $batch ) >= self::batch_size() && $cursor < (int) $state['target_id'] ) {
            return;
        }

        $verified = Listing_Index_Directory_State::get( $directory_id, true );

        if ( $verified['errors'] ) {
            Listing_Index_Directory_State::restart_build( $directory_id, $generation );
            self::defer( 30 );
            return;
        }

        Listing_Index_Directory_State::activate_pending( $directory_id, $generation );
    }

    private static function directory_listing_ids( $directory_id, $after_id, $target_id ) {
        global $wpdb;

        // Canonical postmeta remains authoritative while the derived generation is built.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = %s AND pm.meta_key = '_directory_type' AND pm.meta_value = %s AND p.ID > %d AND p.ID <= %d ORDER BY p.ID ASC LIMIT %d",
                    ATBDP_POST_TYPE,
                    (string) (int) $directory_id,
                    max( 0, (int) $after_id ),
                    max( 0, (int) $target_id ),
                    self::batch_size()
                )
            )
        );
    }

    private static function batch_size() {
        return max( 10, min( 500, (int) apply_filters( 'directorist_listing_index_maintenance_batch_size', 100 ) ) );
    }

    private static function defer( $seconds ) {
        update_option( self::RETRY_AFTER_OPTION, time() + max( 1, (int) $seconds ), false );
    }

    private static function is_background_request() {
        if ( ! wp_doing_ajax() || empty( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return false;
        }

        $action   = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $expected = 'wp_' . get_current_blog_id() . '_' . \Directorist\Listing_Index_Background_Process::ACTION;

        return $expected === $action;
    }

    private static function acquire_lock() {
        $token = wp_generate_uuid4();
        $lock  = [
            'token'   => $token,
            'expires' => time() + 5 * MINUTE_IN_SECONDS,
        ];

        if ( add_option( self::LOCK_OPTION, $lock, '', 'no' ) ) {
            return $token;
        }

        $current = get_option( self::LOCK_OPTION, [] );

        if ( ! is_array( $current ) || time() <= (int) ( $current['expires'] ?? 0 ) ) {
            return false;
        }

        delete_option( self::LOCK_OPTION );

        return add_option( self::LOCK_OPTION, $lock, '', 'no' ) ? $token : false;
    }

    private static function release_lock( $token ) {
        $lock = get_option( self::LOCK_OPTION, [] );

        if ( is_array( $lock ) && hash_equals( (string) ( $lock['token'] ?? '' ), (string) $token ) ) {
            delete_option( self::LOCK_OPTION );
        }
    }
}
