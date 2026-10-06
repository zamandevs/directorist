<?php
/**
 * Synchronizes canonical WordPress listing data into derived lookup tables.
 *
 * @since 8.9.0
 */

namespace Directorist\database;

defined( 'ABSPATH' ) || exit;

class Listing_Index {
    const ENABLED_OPTION = 'directorist_listing_index_enabled';

    const MUTATION_OPTION = 'directorist_listing_index_mutation_version';

    const LAST_REBUILD_MUTATIONS_OPTION = 'directorist_listing_index_last_rebuild_mutations';

    const AMBIGUOUS_META_OPTION = 'directorist_listing_index_ambiguous_meta';

    const REBUILD_PHASE_OPTION = 'directorist_listing_index_rebuild_phase';

    const REBUILD_VERIFICATION_OPTION = 'directorist_listing_index_rebuild_verification';

    const REBUILD_DEPLOYMENT_OPTION = 'directorist_listing_index_rebuild_deployment';

    private static $hooks_registered = false;

    private static $field_definitions = [];

    private static $ambiguous_meta = [];

    private static $mutation_pending = false;

    private static $suspend_mutation_tracking = false;

    private static $deleting_listings = [];

    public static function register_hooks() {
        if ( self::$hooks_registered ) {
            return;
        }

        self::$hooks_registered = true;

        add_action( 'save_post_' . ATBDP_POST_TYPE, [ __CLASS__, 'sync_saved_listing' ], 100, 3 );
        add_action( 'added_post_meta', [ __CLASS__, 'sync_added_or_updated_meta' ], 100, 4 );
        add_action( 'updated_post_meta', [ __CLASS__, 'sync_added_or_updated_meta' ], 100, 4 );
        add_action( 'deleted_post_meta', [ __CLASS__, 'sync_deleted_meta' ], 100, 4 );
        add_action( 'before_delete_post', [ __CLASS__, 'mark_listing_for_deletion' ], 100, 2 );
        add_action( 'deleted_post', [ __CLASS__, 'delete_listing' ], 100, 2 );
        add_action( 'updated_term_meta', [ __CLASS__, 'invalidate_field_definitions' ], 100, 4 );
        add_action( 'added_term_meta', [ __CLASS__, 'invalidate_field_definitions' ], 100, 4 );
        add_action( 'deleted_term_meta', [ __CLASS__, 'invalidate_deleted_field_definitions' ], 100, 4 );
        add_action( 'shutdown', [ __CLASS__, 'persist_mutation_version' ], 999 );
    }

    public static function is_enabled( array $query_args = [] ) {
        if ( ! Listing_Index_Schema::is_ready() ) {
            return false;
        }

        $setting = get_option( self::ENABLED_OPTION, 'auto' );
        $enabled = ! in_array( $setting, [ false, 0, '0', 'no', 'off', 'disabled' ], true );

        return (bool) apply_filters( 'directorist_use_listing_index', $enabled, $query_args );
    }

    public static function set_enabled( $enabled ) {
        return update_option( self::ENABLED_OPTION, $enabled ? 'yes' : 'no', false );
    }

    public static function is_core_meta_ambiguous( $meta_key ) {
        global $wpdb;

        if ( ! isset( self::$ambiguous_meta[ $wpdb->prefix ] ) ) {
            $keys                                  = get_option( self::AMBIGUOUS_META_OPTION, [] );
            self::$ambiguous_meta[ $wpdb->prefix ] = is_array( $keys ) ? $keys : [];
        }

        return ! empty( self::$ambiguous_meta[ $wpdb->prefix ][ $meta_key ] );
    }

    public static function has_verification_errors( array $verification ) {
        unset( $verification['ambiguous_core_meta'] );

        return (bool) array_sum( $verification );
    }

    public static function sync_saved_listing( $post_id, $post, $update ) {
        unset( $update );

        if ( wp_is_post_revision( $post_id ) || ATBDP_POST_TYPE !== $post->post_type ) {
            return;
        }

        self::sync_post_columns( $post );
    }

    public static function sync_added_or_updated_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
        unset( $meta_id, $meta_value );

        if ( isset( self::$deleting_listings[ $object_id ] ) || ! self::is_listing( $object_id ) || ! Listing_Index_Schema::is_compatible() ) {
            return;
        }

        if ( self::is_syncable_core_meta( $meta_key ) ) {
            self::sync_core_meta( $object_id, $meta_key, get_post_meta( $object_id, $meta_key, true ), true );
            return;
        }

        if ( self::is_indexed_field( $object_id, $meta_key ) ) {
            self::sync_field( $object_id, $meta_key );
        }
    }

    public static function sync_deleted_meta( $meta_ids, $object_id, $meta_key, $meta_value ) {
        unset( $meta_ids, $meta_value );

        if ( isset( self::$deleting_listings[ $object_id ] ) || ! self::is_listing( $object_id ) || ! Listing_Index_Schema::is_compatible() ) {
            return;
        }

        if ( self::is_syncable_core_meta( $meta_key ) ) {
            $exists        = metadata_exists( 'post', $object_id, $meta_key );
            $current_value = $exists ? get_post_meta( $object_id, $meta_key, true ) : null;
            self::sync_core_meta( $object_id, $meta_key, $current_value, $exists );
            return;
        }

        if ( self::is_indexed_field( $object_id, $meta_key ) ) {
            self::sync_field( $object_id, $meta_key );
        }
    }

    public static function mark_listing_for_deletion( $post_id, $post ) {
        if ( $post && ATBDP_POST_TYPE === $post->post_type ) {
            self::$deleting_listings[ $post_id ] = true;
        }
    }

    public static function delete_listing( $post_id, $post ) {
        if ( ATBDP_POST_TYPE !== $post->post_type || ! Listing_Index_Schema::is_compatible() ) {
            return;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        self::delete_field_rows( [ 'listing_id' => $post_id ], [ '%d' ] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( Listing_Index_Schema::listing_table(), [ 'listing_id' => $post_id ], [ '%d' ] );

        unset( self::$deleting_listings[ $post_id ] );
        self::mark_mutation();
    }

    public static function invalidate_field_definitions( $meta_id, $term_id, $meta_key, $meta_value ) {
        unset( $meta_id, $meta_value );

        if ( ! in_array( $meta_key, [ 'search_form_fields', 'submission_form_fields' ], true ) ) {
            return;
        }

        global $wpdb;

        unset( self::$field_definitions[ $wpdb->prefix . ':' . (int) $term_id ] );

        if ( Listing_Index_Schema::is_compatible() ) {
            Listing_Index_Directory_State::handle_configuration_change( $term_id );
        }
    }

    public static function invalidate_deleted_field_definitions( $meta_ids, $term_id, $meta_key, $meta_value ) {
        self::invalidate_field_definitions( $meta_ids, $term_id, $meta_key, $meta_value );
    }

    public static function sync_listing( $listing_id ) {
        if ( ! Listing_Index_Schema::is_compatible() ) {
            return false;
        }

        $post = get_post( $listing_id );

        if ( ! $post || ATBDP_POST_TYPE !== $post->post_type ) {
            return false;
        }

        global $wpdb;

        $row = self::build_listing_row( $post );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->replace(
            Listing_Index_Schema::listing_table(),
            $row,
            [
                '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%f', '%d', '%d', '%s',
                '%d', '%s', '%d', '%d', '%d', '%.7f', '%d', '%.7f', '%d', '%.4f', '%d', '%d',
                '%d', '%d', '%d', '%d', '%s', '%s', '%s',
            ]
        );

        if ( false === $result ) {
            return false;
        }

        self::ensure_directory_state_for_write( (int) $row['directory_id'] );
        self::sync_all_fields( $listing_id, (int) $row['directory_id'] );
        self::mark_mutation();

        do_action( 'directorist_listing_index_synced', $listing_id, $row );

        return true;
    }

    public static function sync_post_columns( $post ) {
        if ( ! $post || ATBDP_POST_TYPE !== $post->post_type || ! Listing_Index_Schema::is_compatible() ) {
            return false;
        }

        if ( ! self::get_listing_row( $post->ID ) ) {
            return self::sync_listing( $post->ID );
        }

        global $wpdb;

        $data = [
            'post_status'    => (string) $post->post_status,
            'listing_status' => (string) ( get_post_meta( $post->ID, '_listing_status', true ) ?: $post->post_status ),
            'author_id'      => (int) $post->post_author,
            'post_date'      => (string) $post->post_date,
            'post_modified'  => (string) $post->post_modified,
            'indexed_at'     => current_time( 'mysql', true ),
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            Listing_Index_Schema::listing_table(),
            $data,
            [ 'listing_id' => $post->ID ],
            [ '%s', '%s', '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( false !== $result ) {
            self::mark_mutation();
        }

        return false !== $result;
    }

    public static function get_listing_row( $listing_id ) {
        global $wpdb;

        if ( ! Listing_Index_Schema::is_compatible() ) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Listing_Index_Schema::listing_table() . ' WHERE listing_id = %d',
                $listing_id
            ),
            ARRAY_A
        );
    }

    public static function get_indexed_field_definitions( $directory_id ) {
        if ( Listing_Index_Directory_State::is_query_ready( $directory_id ) ) {
            return Listing_Index_Directory_State::active_manifest( $directory_id );
        }

        return self::compile_indexed_field_definitions( $directory_id );
    }

    public static function compile_indexed_field_definitions( $directory_id ) {
        global $wpdb;

        $directory_id = (int) $directory_id;
        $cache_key    = $wpdb->prefix . ':' . $directory_id;

        if ( isset( self::$field_definitions[ $cache_key ] ) ) {
            return self::$field_definitions[ $cache_key ];
        }

        $config            = get_term_meta( $directory_id, 'search_form_fields', true );
        $submission_config = get_term_meta( $directory_id, 'submission_form_fields', true );
        $fields            = ! empty( $config['fields'] ) && is_array( $config['fields'] ) ? $config['fields'] : [];
        $submission_fields = ! empty( $submission_config['fields'] ) && is_array( $submission_config['fields'] ) ? $submission_config['fields'] : [];
        $definitions       = [];
        $supported         = [ 'select', 'radio', 'switch', 'checkbox', 'number', 'date', 'time', 'text', 'textarea', 'url' ];

        foreach ( $fields as $field_key => $field ) {
            $widget_key       = ! empty( $field['widget_key'] ) ? $field['widget_key'] : $field_key;
            $submission_key   = ! empty( $field['original_widget_key'] ) ? $field['original_widget_key'] : $widget_key;
            $submission_field = ! empty( $submission_fields[ $submission_key ] ) ? $submission_fields[ $submission_key ] : [];
            $field_type       = isset( $field['widget_name'] ) ? sanitize_key( $field['widget_name'] ) : '';

            if ( ! $field_type && ! empty( $submission_field['widget_name'] ) ) {
                $field_type = sanitize_key( $submission_field['widget_name'] );
            }

            if ( ! in_array( $field_type, $supported, true ) ) {
                continue;
            }

            $canonical_key = ! empty( $field['field_key'] ) ? $field['field_key'] : ( $submission_field['field_key'] ?? $widget_key );
            $meta_key      = '_' . ltrim( sanitize_key( $canonical_key ), '_' );

            if ( strlen( $meta_key ) > 128 ) {
                continue;
            }

            $definitions[ $meta_key ] = [
                'field_key'  => $meta_key,
                'field_type' => $field_type,
            ];
        }

        $definitions = apply_filters( 'directorist_listing_indexed_field_definitions', $definitions, $directory_id, $fields );

        self::$field_definitions[ $cache_key ] = is_array( $definitions ) ? $definitions : [];

        return self::$field_definitions[ $cache_key ];
    }

    public static function get_field_definitions_for_generation( $directory_id, $generation ) {
        $definitions = Listing_Index_Directory_State::manifest_for_generation( $directory_id, $generation );

        return $definitions ?: [];
    }

    public static function sync_listing_generation( $listing_id, $directory_id, $generation ) {
        if ( ! Listing_Index_Schema::is_compatible() || ! self::is_listing( $listing_id ) ) {
            return false;
        }

        $definitions = self::get_field_definitions_for_generation( $directory_id, $generation );

        return self::sync_fields_for_generation( $listing_id, $directory_id, $generation, $definitions );
    }

    public static function listing_generation_matches( $listing_id, $directory_id, $generation ) {
        $definitions = self::get_field_definitions_for_generation( $directory_id, $generation );

        return self::field_rows_match( $listing_id, $directory_id, $generation, $definitions );
    }

    public static function rebuild_start() {
        if ( ! Listing_Index_Schema::create() ) {
            return false;
        }

        global $wpdb;

        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_BUILDING );
        self::$suspend_mutation_tracking = true;

        foreach ( Listing_Index_Schema::field_tables() as $field_table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( "DELETE FROM {$field_table}" );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::listing_table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::state_table() );

        $directory_ids = get_terms(
            [
                'taxonomy'   => ATBDP_DIRECTORY_TYPE,
                'hide_empty' => false,
                'fields'     => 'ids',
            ]
        );

        if ( ! is_wp_error( $directory_ids ) ) {
            foreach ( $directory_ids as $directory_id ) {
                Listing_Index_Directory_State::initialize_for_global_rebuild( (int) $directory_id );
            }
        }

        update_option( 'directorist_listing_index_rebuild_cursor', 0, false );
        update_option( self::REBUILD_PHASE_OPTION, 'build', false );
        delete_option( self::REBUILD_VERIFICATION_OPTION );
        update_option( self::REBUILD_DEPLOYMENT_OPTION, Listing_Index_Lifecycle::deployment_token(), false );
        update_option( 'directorist_listing_index_rebuild_started_mutation', (int) get_option( self::MUTATION_OPTION, 0 ), false );

        return true;
    }

    public static function rebuild_batch( $after_id = 0, $limit = 500 ) {
        global $wpdb;

        $after_id = max( 0, (int) $after_id );
        $limit    = max( 1, min( 5000, (int) $limit ) );

        self::$suspend_mutation_tracking = true;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $listing_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
                ATBDP_POST_TYPE,
                $after_id,
                $limit
            )
        );

        foreach ( $listing_ids as $listing_id ) {
            self::sync_listing( (int) $listing_id );
            clean_post_cache( (int) $listing_id );
        }

        $next = empty( $listing_ids ) ? $after_id : (int) end( $listing_ids );
        $done = count( $listing_ids ) < $limit;

        update_option( 'directorist_listing_index_rebuild_cursor', $next, false );

        return [
            'processed' => count( $listing_ids ),
            'next'      => $next,
            'done'      => $done,
        ];
    }

    public static function rebuild_finish() {
        self::$suspend_mutation_tracking = false;

        $verification = self::verify();

        return self::complete_rebuild( $verification );
    }

    public static function rebuild_begin_verification() {
        if ( Listing_Index_Schema::STATUS_BUILDING !== Listing_Index_Schema::status() ) {
            return false;
        }

        update_option( self::REBUILD_PHASE_OPTION, 'verify', false );
        update_option( 'directorist_listing_index_rebuild_cursor', 0, false );
        update_option(
            self::REBUILD_VERIFICATION_OPTION,
            [
                'mismatched'        => 0,
                'mismatched_fields' => 0,
                'ambiguous_meta'    => [],
            ],
            false
        );

        return true;
    }

    public static function rebuild_verify_batch( $after_id = 0, $limit = 500 ) {
        global $wpdb;

        $after_id = max( 0, (int) $after_id );
        $limit    = max( 1, min( 5000, (int) $limit ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $listing_ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT listing_id FROM ' . Listing_Index_Schema::listing_table() . ' WHERE listing_id > %d ORDER BY listing_id ASC LIMIT %d',
                $after_id,
                $limit
            )
        );
        $results     = self::verify_listing_ids( $listing_ids );
        $stored      = get_option( self::REBUILD_VERIFICATION_OPTION, [] );
        $stored      = is_array( $stored ) ? $stored : [];
        $next        = $listing_ids ? (int) end( $listing_ids ) : $after_id;

        $stored['mismatched']        = (int) ( $stored['mismatched'] ?? 0 ) + $results['mismatched'];
        $stored['mismatched_fields'] = (int) ( $stored['mismatched_fields'] ?? 0 ) + $results['mismatched_fields'];
        $stored['ambiguous_meta']    = self::merge_ambiguous_meta_counts( $stored['ambiguous_meta'] ?? [], $results['ambiguous_meta'] );

        update_option( self::REBUILD_VERIFICATION_OPTION, $stored, false );
        update_option( 'directorist_listing_index_rebuild_cursor', $next, false );

        return [
            'processed'         => count( $listing_ids ),
            'next'              => $next,
            'done'              => count( $listing_ids ) < $limit,
            'mismatched'        => $results['mismatched'],
            'mismatched_fields' => $results['mismatched_fields'],
            'ambiguous_meta'    => $results['ambiguous_meta'],
        ];
    }

    public static function rebuild_finish_incremental() {
        $stored         = get_option( self::REBUILD_VERIFICATION_OPTION, [] );
        $stored         = is_array( $stored ) ? $stored : [];
        $coverage       = self::verify_coverage();
        $ambiguous_meta = is_array( $stored['ambiguous_meta'] ?? null ) ? $stored['ambiguous_meta'] : [];
        $verification   = [
            'missing'             => $coverage['missing'],
            'orphaned'            => $coverage['orphaned'],
            'mismatched'          => (int) ( $stored['mismatched'] ?? 0 ),
            'orphaned_fields'     => $coverage['orphaned_fields'],
            'mismatched_fields'   => (int) ( $stored['mismatched_fields'] ?? 0 ),
            'ambiguous_core_meta' => array_sum( $ambiguous_meta ),
        ];
        self::set_ambiguous_core_meta( $ambiguous_meta );

        return self::complete_rebuild( $verification );
    }

    private static function complete_rebuild( array $verification ) {
        self::$suspend_mutation_tracking = false;

        $started = (int) get_option( 'directorist_listing_index_rebuild_started_mutation', 0 );
        $current = (int) get_option( self::MUTATION_OPTION, 0 );

        $verification['configuration_changed'] = (int) ( Listing_Index_Schema::STATUS_BUILDING !== Listing_Index_Schema::status() );
        $verification['deployment_changed']    = (int) ( (string) get_option( self::REBUILD_DEPLOYMENT_OPTION, '' ) !== Listing_Index_Lifecycle::deployment_token() );

        update_option( self::LAST_REBUILD_MUTATIONS_OPTION, max( 0, $current - $started ), false );
        delete_option( 'directorist_listing_index_rebuild_started_mutation' );
        delete_option( self::REBUILD_PHASE_OPTION );
        delete_option( self::REBUILD_VERIFICATION_OPTION );
        delete_option( self::REBUILD_DEPLOYMENT_OPTION );

        if ( self::has_verification_errors( $verification ) ) {
            $status = $verification['configuration_changed'] || $verification['deployment_changed'] ? Listing_Index_Schema::STATUS_NEEDS_REBUILD : Listing_Index_Schema::STATUS_FAILED;
            Listing_Index_Schema::mark_status( $status );
            return $verification;
        }

        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_READY );
        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, Listing_Index_Schema::DATA_VERSION, false );
        Listing_Index_Lifecycle::trust_current_deployment();
        delete_option( 'directorist_listing_index_rebuild_cursor' );

        return $verification;
    }

    public static function get_last_rebuild_mutations() {
        return (int) get_option( self::LAST_REBUILD_MUTATIONS_OPTION, 0 );
    }

    public static function verify() {
        global $wpdb;

        $coverage      = self::verify_coverage();
        $listing_table = Listing_Index_Schema::listing_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $indexed_ids = $wpdb->get_col( "SELECT listing_id FROM {$listing_table} ORDER BY listing_id ASC" );
        $rows        = self::verify_listing_ids( $indexed_ids );

        $verification = [
            'missing'             => $coverage['missing'],
            'orphaned'            => $coverage['orphaned'],
            'mismatched'          => $rows['mismatched'],
            'orphaned_fields'     => $coverage['orphaned_fields'],
            'mismatched_fields'   => $rows['mismatched_fields'],
            'ambiguous_core_meta' => array_sum( $rows['ambiguous_meta'] ),
        ];
        self::set_ambiguous_core_meta( $rows['ambiguous_meta'] );

        return $verification;
    }

    private static function verify_coverage() {
        global $wpdb;

        $listing_table = Listing_Index_Schema::listing_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $missing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(p.ID) FROM {$wpdb->posts} p LEFT JOIN {$listing_table} i ON i.listing_id = p.ID WHERE p.post_type = %s AND i.listing_id IS NULL",
                ATBDP_POST_TYPE
            )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $orphaned        = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(i.listing_id) FROM {$listing_table} i LEFT JOIN {$wpdb->posts} p ON p.ID = i.listing_id AND p.post_type = %s WHERE p.ID IS NULL",
                ATBDP_POST_TYPE
            )
        );
        $orphaned_fields = 0;

        foreach ( Listing_Index_Schema::field_tables() as $field_table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
            $orphaned_fields += (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(f.listing_id) FROM {$field_table} f LEFT JOIN {$wpdb->posts} p ON p.ID = f.listing_id AND p.post_type = %s WHERE p.ID IS NULL",
                    ATBDP_POST_TYPE
                )
            );
        }

        return [
            'missing'         => $missing,
            'orphaned'        => $orphaned,
            'orphaned_fields' => $orphaned_fields,
        ];
    }

    private static function verify_listing_ids( array $listing_ids ) {
        $mismatched        = 0;
        $mismatched_fields = 0;
        $ambiguous_meta    = [];
        $core_meta_keys    = self::core_meta_keys();

        foreach ( $listing_ids as $listing_id ) {
            $stored = self::get_listing_row( $listing_id );
            $post   = get_post( $listing_id );

            if ( ! $post || ! self::rows_match( self::build_listing_row( $post ), $stored ) ) {
                ++$mismatched;
            }

            $directory_id = $post ? (int) $stored['directory_id'] : 0;
            $generation   = $directory_id ? Listing_Index_Directory_State::query_generation( $directory_id ) : 0;

            if ( $post && $generation && ! self::field_rows_match( $listing_id, $directory_id, $generation ) ) {
                ++$mismatched_fields;
            }

            foreach ( $core_meta_keys as $meta_key ) {
                $values = array_map( 'maybe_serialize', get_post_meta( $listing_id, $meta_key, false ) );

                if ( 1 < count( array_unique( $values, SORT_STRING ) ) ) {
                    $ambiguous_meta[ $meta_key ] = (int) ( $ambiguous_meta[ $meta_key ] ?? 0 ) + 1;
                }
            }

            clean_post_cache( (int) $listing_id );
        }

        return [
            'mismatched'        => $mismatched,
            'mismatched_fields' => $mismatched_fields,
            'ambiguous_meta'    => $ambiguous_meta,
        ];
    }

    private static function merge_ambiguous_meta_counts( $stored, array $batch ) {
        $stored = is_array( $stored ) ? $stored : [];

        foreach ( $batch as $meta_key => $count ) {
            $stored[ $meta_key ] = (int) ( $stored[ $meta_key ] ?? 0 ) + (int) $count;
        }

        return $stored;
    }

    public static function persist_mutation_version() {
        if ( ! self::$mutation_pending || self::$suspend_mutation_tracking ) {
            return;
        }

        update_option( self::MUTATION_OPTION, (int) get_option( self::MUTATION_OPTION, 0 ) + 1, false );
        self::$mutation_pending = false;
    }

    private static function build_listing_row( $post ) {
        $rating_key = function_exists( 'directorist_get_rating_field_meta_key' ) ? directorist_get_rating_field_meta_key() : '_directorist_listing_rating';
        $views_key  = function_exists( 'directorist_get_listing_views_count_meta_key' ) ? directorist_get_listing_views_count_meta_key() : '_atbdp_post_views_count';

        $price       = get_post_meta( $post->ID, '_price', true );
        $latitude    = get_post_meta( $post->ID, '_manual_lat', true );
        $longitude   = get_post_meta( $post->ID, '_manual_lng', true );
        $rating      = get_post_meta( $post->ID, $rating_key, true );
        $expiry_date = self::normalize_datetime( get_post_meta( $post->ID, '_expiry_date', true ) );

        return [
            'listing_id'        => (int) $post->ID,
            'directory_id'      => (int) get_post_meta( $post->ID, '_directory_type', true ),
            'directory_set'     => (int) metadata_exists( 'post', $post->ID, '_directory_type' ),
            'post_status'       => (string) $post->post_status,
            'listing_status'    => (string) ( get_post_meta( $post->ID, '_listing_status', true ) ?: $post->post_status ),
            'author_id'         => (int) $post->post_author,
            'featured'          => (int) (bool) get_post_meta( $post->ID, '_featured', true ),
            'featured_set'      => (int) metadata_exists( 'post', $post->ID, '_featured' ),
            'price'             => metadata_exists( 'post', $post->ID, '_price' ) ? round( (float) $price, 6 ) : null,
            'price_signed'      => metadata_exists( 'post', $post->ID, '_price' ) ? self::normalize_signed( $price ) : null,
            'price_set'         => (int) metadata_exists( 'post', $post->ID, '_price' ),
            'price_range'       => (string) get_post_meta( $post->ID, '_price_range', true ),
            'price_range_set'   => (int) metadata_exists( 'post', $post->ID, '_price_range' ),
            'expiry_date'       => $expiry_date,
            'expiry_date_set'   => (int) metadata_exists( 'post', $post->ID, '_expiry_date' ),
            'never_expire'      => (int) (bool) get_post_meta( $post->ID, '_never_expire', true ),
            'never_expire_set'  => (int) metadata_exists( 'post', $post->ID, '_never_expire' ),
            'latitude'          => metadata_exists( 'post', $post->ID, '_manual_lat' ) ? round( (float) $latitude, 7 ) : null,
            'latitude_set'      => (int) metadata_exists( 'post', $post->ID, '_manual_lat' ),
            'longitude'         => metadata_exists( 'post', $post->ID, '_manual_lng' ) ? round( (float) $longitude, 7 ) : null,
            'longitude_set'     => (int) metadata_exists( 'post', $post->ID, '_manual_lng' ),
            'rating'            => metadata_exists( 'post', $post->ID, $rating_key ) ? round( (float) $rating, 4 ) : null,
            'rating_signed'     => metadata_exists( 'post', $post->ID, $rating_key ) ? self::normalize_signed( $rating ) : null,
            'rating_set'        => (int) metadata_exists( 'post', $post->ID, $rating_key ),
            'review_count'      => (int) get_post_meta( $post->ID, '_directorist_listing_review_count', true ),
            'view_count'        => (int) get_post_meta( $post->ID, $views_key, true ),
            'view_count_signed' => metadata_exists( 'post', $post->ID, $views_key ) ? self::normalize_signed( get_post_meta( $post->ID, $views_key, true ) ) : null,
            'view_count_set'    => (int) metadata_exists( 'post', $post->ID, $views_key ),
            'post_date'         => (string) $post->post_date,
            'post_modified'     => (string) $post->post_modified,
            'indexed_at'        => current_time( 'mysql', true ),
        ];
    }

    private static function sync_core_meta( $listing_id, $meta_key, $meta_value, $exists ) {
        $rating_key = function_exists( 'directorist_get_rating_field_meta_key' ) ? directorist_get_rating_field_meta_key() : '_directorist_listing_rating';
        $views_key  = function_exists( 'directorist_get_listing_views_count_meta_key' ) ? directorist_get_listing_views_count_meta_key() : '_atbdp_post_views_count';
        $data       = [];
        $formats    = [];

        switch ( $meta_key ) {
            case '_directory_type':
                $data['directory_id']  = $exists ? (int) $meta_value : 0;
                $data['directory_set'] = (int) $exists;
                $formats               = [ '%d', '%d' ];
                break;
            case '_listing_status':
                $data['listing_status'] = $exists ? (string) $meta_value : (string) get_post_status( $listing_id );
                $formats[]              = '%s';
                break;
            case '_featured':
                $data['featured']     = $exists ? (int) (bool) $meta_value : 0;
                $data['featured_set'] = (int) $exists;
                $formats              = [ '%d', '%d' ];
                break;
            case '_price':
                $data['price']        = $exists ? round( (float) $meta_value, 6 ) : null;
                $data['price_signed'] = $exists ? self::normalize_signed( $meta_value ) : null;
                $data['price_set']    = (int) $exists;
                $formats              = [ '%f', '%d', '%d' ];
                break;
            case '_price_range':
                $data['price_range']     = $exists ? (string) $meta_value : '';
                $data['price_range_set'] = (int) $exists;
                $formats                 = [ '%s', '%d' ];
                break;
            case '_expiry_date':
                $data['expiry_date']     = $exists ? self::normalize_datetime( $meta_value ) : null;
                $data['expiry_date_set'] = (int) $exists;
                $formats                 = [ '%s', '%d' ];
                break;
            case '_never_expire':
                $data['never_expire']     = $exists ? (int) (bool) $meta_value : 0;
                $data['never_expire_set'] = (int) $exists;
                $formats                  = [ '%d', '%d' ];
                break;
            case '_manual_lat':
                $data['latitude']     = $exists ? round( (float) $meta_value, 7 ) : null;
                $data['latitude_set'] = (int) $exists;
                $formats              = [ '%.7f', '%d' ];
                break;
            case '_manual_lng':
                $data['longitude']     = $exists ? round( (float) $meta_value, 7 ) : null;
                $data['longitude_set'] = (int) $exists;
                $formats               = [ '%.7f', '%d' ];
                break;
            case '_directorist_listing_review_count':
                $data['review_count'] = $exists ? (int) $meta_value : 0;
                $formats[]            = '%d';
                break;
            default:
                if ( $rating_key === $meta_key ) {
                    $data['rating']        = $exists ? round( (float) $meta_value, 4 ) : null;
                    $data['rating_signed'] = $exists ? self::normalize_signed( $meta_value ) : null;
                    $data['rating_set']    = (int) $exists;
                    $formats               = [ '%.4f', '%d', '%d' ];
                } elseif ( $views_key === $meta_key ) {
                    $data['view_count']        = $exists ? (int) $meta_value : 0;
                    $data['view_count_signed'] = $exists ? self::normalize_signed( $meta_value ) : null;
                    $data['view_count_set']    = (int) $exists;
                    $formats                   = [ '%d', '%d', '%d' ];
                } else {
                    return false;
                }
        }

        if ( in_array( $meta_key, self::core_meta_keys(), true ) ) {
            $values = array_map( 'maybe_serialize', get_post_meta( $listing_id, $meta_key, false ) );

            if ( 1 < count( array_unique( $values, SORT_STRING ) ) ) {
                self::mark_core_meta_ambiguous( $meta_key );
            }
        }

        if ( ! self::get_listing_row( $listing_id ) ) {
            return self::sync_listing( $listing_id );
        }

        global $wpdb;

        $data['indexed_at'] = current_time( 'mysql', true );
        $formats[]          = '%s';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            Listing_Index_Schema::listing_table(),
            $data,
            [ 'listing_id' => $listing_id ],
            $formats,
            [ '%d' ]
        );

        if ( '_directory_type' === $meta_key ) {
            self::delete_field_rows( [ 'listing_id' => $listing_id ], [ '%d' ] );
            self::ensure_directory_state_for_write( (int) $data['directory_id'] );
            self::sync_all_fields( $listing_id, (int) $data['directory_id'] );
        }

        self::mark_mutation();

        return true;
    }

    private static function sync_all_fields( $listing_id, $directory_id ) {
        foreach ( Listing_Index_Directory_State::writable_generations( $directory_id ) as $generation ) {
            self::sync_fields_for_generation(
                $listing_id,
                $directory_id,
                $generation,
                self::get_field_definitions_for_generation( $directory_id, $generation )
            );
        }
    }

    private static function sync_fields_for_generation( $listing_id, $directory_id, $generation, array $definitions ) {
        global $wpdb;

        $deleted = self::delete_field_rows(
            [
                'listing_id' => (int) $listing_id,
                'generation' => (int) $generation,
            ],
            [ '%d', '%d' ]
        );

        if ( false === $deleted ) {
            return false;
        }

        return self::insert_field_rows(
            self::build_all_field_rows( $listing_id, $directory_id, $generation, $definitions )
        );
    }

    private static function sync_field( $listing_id, $meta_key, $directory_id = null ) {
        global $wpdb;

        $directory_id = null === $directory_id ? (int) get_post_meta( $listing_id, '_directory_type', true ) : (int) $directory_id;
        self::ensure_directory_state_for_write( $directory_id );

        foreach ( Listing_Index_Directory_State::writable_generations( $directory_id ) as $generation ) {
            $definitions = self::get_field_definitions_for_generation( $directory_id, $generation );

            self::delete_field_rows(
                [
                    'listing_id' => $listing_id,
                    'generation' => $generation,
                    'field_key'  => $meta_key,
                ],
                [ '%d', '%d', '%s' ]
            );

            if ( ! isset( $definitions[ $meta_key ] ) || ! metadata_exists( 'post', $listing_id, $meta_key ) ) {
                continue;
            }

            $rows = [];

            foreach ( self::normalize_field_values( get_post_meta( $listing_id, $meta_key, false ), $definitions[ $meta_key ]['field_type'] ) as $value ) {
                $row = self::build_field_row( $listing_id, $directory_id, $generation, $definitions[ $meta_key ], $value );

                if ( $row ) {
                    $rows[] = $row;
                }
            }

            self::insert_field_rows( $rows );
        }

        self::mark_mutation();
    }

    private static function build_field_row( $listing_id, $directory_id, $generation, array $definition, $value ) {
        $field_type = $definition['field_type'];
        $normalized = is_bool( $value ) ? (string) (int) $value : (string) $value;
        $is_text    = in_array( $field_type, [ 'text', 'textarea', 'url' ], true );
        $storage    = $is_text ? 'text' : ( in_array( $field_type, [ 'number', 'date' ], true ) ? $field_type : 'exact' );

        if ( 'exact' === $storage && strlen( $normalized ) > 191 ) {
            return null;
        }

        $search_document = $is_text ? Listing_Index_Text::document( $directory_id, $generation, $definition['field_key'], $normalized ) : null;

        if ( $is_text && ! $search_document ) {
            return null;
        }

        $row = [
            'storage'         => $storage,
            'listing_id'      => (int) $listing_id,
            'directory_id'    => (int) $directory_id,
            'generation'      => (int) $generation,
            'field_key'       => $definition['field_key'],
            'field_type'      => $field_type,
            'value_string'    => $is_text ? '' : $normalized,
            'value_hash'      => md5( $field_type . "\0" . $normalized ),
            'search_document' => $search_document,
            'value_num'       => null,
            'value_signed'    => null,
            'value_date'      => null,
            'indexed_at'      => current_time( 'mysql', true ),
        ];

        if ( 'number' === $field_type ) {
            $row['value_num']    = round( (float) $normalized, 10 );
            $row['value_signed'] = self::normalize_signed( $normalized );
        } elseif ( 'date' === $field_type ) {
            $row['value_date'] = self::normalize_datetime( $normalized );
        }

        return apply_filters( 'directorist_listing_index_field_row', $row, $listing_id, $definition, $value );
    }

    private static function insert_field_rows( array $rows ) {
        if ( ! $rows ) {
            return true;
        }

        global $wpdb;

        $grouped = [];

        foreach ( $rows as $row ) {
            if ( isset( Listing_Index_Schema::field_tables()[ $row['storage'] ] ) ) {
                $grouped[ $row['storage'] ][] = $row;
            }
        }

        foreach ( $grouped as $storage => $storage_rows ) {
            foreach ( array_chunk( $storage_rows, 200 ) as $chunk ) {
                $placeholders = [];
                $values       = [];

                foreach ( $chunk as $row ) {
                    $common = [
                        $row['listing_id'],
                        $row['directory_id'],
                        $row['generation'],
                        $row['field_key'],
                        $row['field_type'],
                        $row['value_hash'],
                    ];

                    $values = array_merge( $values, $common );

                    if ( 'exact' === $storage ) {
                        $placeholders[] = '(%d,%d,%d,%s,%s,%s,%s,%s)';
                        $values[]       = $row['value_string'];
                    } elseif ( 'number' === $storage ) {
                        $placeholders[] = '(%d,%d,%d,%s,%s,%s,%s,%.10f,%d,%s)';
                        $values[]       = $row['value_string'];
                        $values[]       = $row['value_num'];
                        $values[]       = $row['value_signed'];
                    } elseif ( 'date' === $storage ) {
                        $date_placeholder = null === $row['value_date'] ? 'NULL' : '%s';
                        $placeholders[]   = "(%d,%d,%d,%s,%s,%s,%s,{$date_placeholder},%s)";
                        $values[]         = $row['value_string'];

                        if ( null !== $row['value_date'] ) {
                            $values[] = $row['value_date'];
                        }
                    } else {
                        $placeholders[] = '(%d,%d,%d,%s,%s,%s,%s,%s)';
                        $values[]       = $row['search_document'];
                    }

                    $values[] = $row['indexed_at'];
                }

                $columns = 'listing_id,directory_id,generation,field_key,field_type,value_hash,';

                if ( 'exact' === $storage ) {
                    $columns .= 'value_string,indexed_at';
                } elseif ( 'number' === $storage ) {
                    $columns .= 'value_string,value_num,value_signed,indexed_at';
                } elseif ( 'date' === $storage ) {
                    $columns .= 'value_string,value_date,indexed_at';
                } else {
                    $columns .= 'search_document,indexed_at';
                }

                $sql  = 'INSERT INTO ' . Listing_Index_Schema::field_tables()[ $storage ];
                $sql .= " ({$columns}) VALUES " . implode( ',', $placeholders );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
                if ( false === $wpdb->query( $wpdb->prepare( $sql, $values ) ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function build_all_field_rows( $listing_id, $directory_id, $generation, array $definitions = [] ) {
        $rows = [];

        if ( ! $definitions ) {
            $definitions = self::get_field_definitions_for_generation( $directory_id, $generation );
        }

        foreach ( $definitions as $meta_key => $definition ) {
            if ( ! metadata_exists( 'post', $listing_id, $meta_key ) ) {
                continue;
            }

            foreach ( self::normalize_field_values( get_post_meta( $listing_id, $meta_key, false ), $definition['field_type'] ) as $value ) {
                $row = self::build_field_row( $listing_id, $directory_id, $generation, $definition, $value );

                if ( $row ) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    private static function field_rows_match( $listing_id, $directory_id, $generation, array $definitions = [] ) {
        global $wpdb;

        $expected = array_map(
            static function( $row ) {
                return $row['storage'] . ':' . $row['field_key'] . ':' . $row['value_hash'];
            },
            self::build_all_field_rows( $listing_id, $directory_id, $generation, $definitions )
        );

        $stored = [];

        foreach ( Listing_Index_Schema::field_tables() as $storage => $field_table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT field_key, value_hash FROM {$field_table} WHERE listing_id = %d AND generation = %d",
                    $listing_id,
                    $generation
                ),
                ARRAY_A
            );

            foreach ( $rows as $row ) {
                $stored[] = $storage . ':' . $row['field_key'] . ':' . $row['value_hash'];
            }
        }

        sort( $expected );
        sort( $stored );

        return $expected === $stored;
    }

    private static function delete_field_rows( array $where, array $formats ) {
        global $wpdb;

        $deleted = true;

        foreach ( Listing_Index_Schema::field_tables() as $field_table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( false === $wpdb->delete( $field_table, $where, $formats ) ) {
                $deleted = false;
            }
        }

        return $deleted;
    }

    private static function normalize_field_values( $value, $field_type = '' ) {
        $values = is_array( $value ) ? $value : [ $value ];
        $flat   = [];

        foreach ( $values as $value ) {
            if ( 'checkbox' === $field_type && is_string( $value ) && false !== strpos( $value, ',' ) ) {
                $flat = array_merge( $flat, array_filter( explode( ',', $value ) ) );
                continue;
            }

            $walk_values = [ $value ];

            array_walk_recursive(
                $walk_values,
                static function( $item ) use ( &$flat ) {
                    if ( is_scalar( $item ) ) {
                        $flat[] = $item;
                    }
                }
            );
        }

        return array_values( array_unique( $flat, SORT_REGULAR ) );
    }

    private static function is_indexed_field( $listing_id, $meta_key ) {
        $directory_id = (int) get_post_meta( $listing_id, '_directory_type', true );

        self::ensure_directory_state_for_write( $directory_id );

        foreach ( Listing_Index_Directory_State::writable_generations( $directory_id ) as $generation ) {
            if ( isset( self::get_field_definitions_for_generation( $directory_id, $generation )[ $meta_key ] ) ) {
                return true;
            }
        }

        return false;
    }

    private static function ensure_directory_state_for_write( $directory_id ) {
        if ( ! $directory_id ) {
            return;
        }

        $state = Listing_Index_Directory_State::get( $directory_id );

        if ( $state['active_generation'] || $state['pending_generation'] ) {
            return;
        }

        if ( Listing_Index_Schema::STATUS_BUILDING === Listing_Index_Schema::status() ) {
            Listing_Index_Directory_State::initialize_for_global_rebuild( $directory_id );
            return;
        }

        Listing_Index_Directory_State::handle_configuration_change( $directory_id );
    }

    private static function core_meta_keys() {
        $rating_key = function_exists( 'directorist_get_rating_field_meta_key' ) ? directorist_get_rating_field_meta_key() : '_directorist_listing_rating';
        $views_key  = function_exists( 'directorist_get_listing_views_count_meta_key' ) ? directorist_get_listing_views_count_meta_key() : '_atbdp_post_views_count';

        return [
            '_directory_type',
            '_featured',
            '_price',
            '_price_range',
            '_expiry_date',
            '_never_expire',
            '_manual_lat',
            '_manual_lng',
            $rating_key,
            $views_key,
        ];
    }

    private static function is_syncable_core_meta( $meta_key ) {
        return in_array( $meta_key, array_merge( self::core_meta_keys(), [ '_listing_status', '_directorist_listing_review_count' ] ), true );
    }

    private static function mark_core_meta_ambiguous( $meta_key ) {
        global $wpdb;

        $keys              = get_option( self::AMBIGUOUS_META_OPTION, [] );
        $keys              = is_array( $keys ) ? $keys : [];
        $keys[ $meta_key ] = max( 1, (int) ( $keys[ $meta_key ] ?? 0 ) );

        self::$ambiguous_meta[ $wpdb->prefix ] = $keys;
        update_option( self::AMBIGUOUS_META_OPTION, $keys, false );
    }

    private static function set_ambiguous_core_meta( array $keys ) {
        global $wpdb;

        self::$ambiguous_meta[ $wpdb->prefix ] = $keys;
        update_option( self::AMBIGUOUS_META_OPTION, $keys, false );
    }

    private static function normalize_datetime( $value ) {
        if ( empty( $value ) ) {
            return null;
        }

        if ( is_numeric( $value ) ) {
            return gmdate( 'Y-m-d H:i:s', (int) $value );
        }

        $timestamp = strtotime( $value );

        return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
    }

    private static function normalize_signed( $value ) {
        $value = maybe_serialize( $value );

        if ( ! preg_match( '/^\s*([+-]?\d+)/', (string) $value, $matches ) ) {
            return 0;
        }

        return (int) $matches[1];
    }

    private static function is_listing( $listing_id ) {
        return ATBDP_POST_TYPE === get_post_type( $listing_id );
    }

    private static function mark_mutation() {
        if ( ! self::$suspend_mutation_tracking ) {
            self::$mutation_pending = true;
        }
    }

    private static function rows_match( array $expected, array $stored ) {
        unset( $expected['indexed_at'], $stored['indexed_at'] );

        foreach ( $expected as $key => $value ) {
            if ( null === $value && null === $stored[ $key ] ) {
                continue;
            }

            if ( is_numeric( $value ) && is_numeric( $stored[ $key ] ) ) {
                if ( abs( (float) $value - (float) $stored[ $key ] ) < 0.0000001 ) {
                    continue;
                }
            }

            if ( (string) $value !== (string) $stored[ $key ] ) {
                return false;
            }
        }

        return true;
    }
}
