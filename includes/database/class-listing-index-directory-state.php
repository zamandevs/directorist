<?php
/**
 * Per-directory listing-index configuration and generation state.
 *
 * @since 8.9.0
 */

namespace Directorist\database;

defined( 'ABSPATH' ) || exit;

class Listing_Index_Directory_State {
    const STATUS_READY = 'ready';

    const STATUS_PENDING = 'pending';

    const STATUS_BUILDING = 'building';

    const CACHE_GROUP = 'directorist_listing_index';

    public static function get( $directory_id, $force = false ) {
        global $wpdb;

        $directory_id = (int) $directory_id;

        if ( ! $directory_id ) {
            return self::empty_state( 0 );
        }

        $cache_key = self::cache_key( $directory_id );
        $found     = false;
        $state     = $force ? false : wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

        if ( ! $force && $found ) {
            return is_array( $state ) ? $state : self::empty_state( $directory_id );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Listing_Index_Schema::state_table() . ' WHERE directory_id = %d',
                $directory_id
            ),
            ARRAY_A
        );

        $state = $row ? self::normalize_state( $row ) : self::empty_state( $directory_id );
        wp_cache_set( $cache_key, $state, self::CACHE_GROUP );

        return $state;
    }

    public static function active_manifest( $directory_id ) {
        $state = self::get( $directory_id );

        return self::decode_manifest( $state['active_manifest'] );
    }

    public static function pending_manifest( $directory_id ) {
        $state = self::get( $directory_id );

        return self::decode_manifest( $state['pending_manifest'] );
    }

    public static function manifest_for_generation( $directory_id, $generation ) {
        $state      = self::get( $directory_id );
        $generation = (int) $generation;

        if ( $generation && $generation === (int) $state['active_generation'] ) {
            return self::decode_manifest( $state['active_manifest'] );
        }

        if ( $generation && $generation === (int) $state['pending_generation'] ) {
            return self::decode_manifest( $state['pending_manifest'] );
        }

        return [];
    }

    public static function writable_generations( $directory_id ) {
        $state       = self::get( $directory_id );
        $generations = [];

        if ( 0 < (int) $state['active_generation'] ) {
            $generations[] = (int) $state['active_generation'];
        }

        if ( in_array( $state['status'], [ self::STATUS_PENDING, self::STATUS_BUILDING ], true ) && 0 < (int) $state['pending_generation'] ) {
            $generations[] = (int) $state['pending_generation'];
        }

        return array_values( array_unique( $generations ) );
    }

    public static function is_query_ready( $directory_id ) {
        $state = self::get( $directory_id );

        return 0 < (int) $state['active_generation']
            && in_array( $state['status'], [ self::STATUS_READY, self::STATUS_PENDING, self::STATUS_BUILDING ], true );
    }

    public static function query_generation( $directory_id ) {
        $state = self::get( $directory_id );

        return 0 < (int) $state['active_generation']
            && in_array( $state['status'], [ self::STATUS_READY, self::STATUS_PENDING, self::STATUS_BUILDING ], true )
            ? (int) $state['active_generation']
            : 0;
    }

    public static function handle_configuration_change( $directory_id ) {
        global $wpdb;

        $directory_id = (int) $directory_id;

        if ( ! $directory_id || ! Listing_Index_Schema::is_compatible() || ! term_exists( $directory_id, ATBDP_DIRECTORY_TYPE ) ) {
            return false;
        }

        $manifest = Listing_Index::compile_indexed_field_definitions( $directory_id );
        $encoded  = self::encode_manifest( $manifest );
        $hash     = md5( $encoded );
        $state    = self::get( $directory_id, true );

        if ( $hash === $state['active_config_hash'] && ! $state['pending_generation'] ) {
            return false;
        }

        if ( $hash === $state['pending_config_hash'] && $state['pending_generation'] ) {
            return false;
        }

        if ( ! self::directory_has_listings( $directory_id ) ) {
            self::activate_configuration( $directory_id, $manifest );
            return true;
        }

        $generation = max( (int) $state['active_generation'], (int) $state['pending_generation'] ) + 1;
        $target_id  = self::directory_max_listing_id( $directory_id );

        if ( $state['pending_generation'] ) {
            foreach ( Listing_Index_Schema::field_tables() as $field_table ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->delete(
                    $field_table,
                    [
                        'directory_id' => $directory_id,
                        'generation'   => (int) $state['pending_generation'],
                    ],
                    [ '%d', '%d' ]
                );
            }
        }

        self::write(
            $directory_id,
            [
                'status'              => self::STATUS_PENDING,
                'pending_generation'  => $generation,
                'pending_config_hash' => $hash,
                'pending_manifest'    => $encoded,
                'phase'               => 'build',
                'last_listing_id'     => 0,
                'target_id'           => $target_id,
                'processed'           => 0,
                'errors'              => 0,
            ],
            $state
        );

        Listing_Index_Maintenance::schedule();

        return true;
    }

    public static function activate_current_configuration( $directory_id ) {
        $manifest = Listing_Index::compile_indexed_field_definitions( $directory_id );

        return self::activate_configuration( $directory_id, $manifest );
    }

    public static function initialize_for_global_rebuild( $directory_id ) {
        return self::activate_configuration( $directory_id, Listing_Index::compile_indexed_field_definitions( $directory_id ), false );
    }

    public static function mark_building( $directory_id, $generation ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Listing_Index_Schema::state_table() . ' SET status = %s, updated_at = %s WHERE directory_id = %d AND pending_generation = %d AND status IN (%s, %s)',
                self::STATUS_BUILDING,
                current_time( 'mysql', true ),
                (int) $directory_id,
                (int) $generation,
                self::STATUS_PENDING,
                self::STATUS_BUILDING
            )
        );

        self::flush( $directory_id );

        return false !== $updated;
    }

    public static function advance( $directory_id, $generation, $cursor, $processed ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Listing_Index_Schema::state_table() . ' SET last_listing_id = %d, processed = processed + %d, updated_at = %s WHERE directory_id = %d AND pending_generation = %d AND status = %s',
                (int) $cursor,
                (int) $processed,
                current_time( 'mysql', true ),
                (int) $directory_id,
                (int) $generation,
                self::STATUS_BUILDING
            )
        );

        self::flush( $directory_id );

        return 1 === $updated;
    }

    public static function begin_verification( $directory_id, $generation ) {
        global $wpdb;

        $target_id = self::directory_max_listing_id( $directory_id );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Listing_Index_Schema::state_table() . ' SET phase = %s, last_listing_id = 0, target_id = %d, processed = 0, errors = 0, updated_at = %s WHERE directory_id = %d AND pending_generation = %d AND status = %s AND phase = %s',
                'verify',
                $target_id,
                current_time( 'mysql', true ),
                (int) $directory_id,
                (int) $generation,
                self::STATUS_BUILDING,
                'build'
            )
        );

        self::flush( $directory_id );

        return 1 === $updated;
    }

    public static function restart_build( $directory_id, $generation ) {
        global $wpdb;

        $target_id = self::directory_max_listing_id( $directory_id );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Listing_Index_Schema::state_table() . ' SET status = %s, phase = %s, last_listing_id = 0, target_id = %d, processed = 0, errors = 0, updated_at = %s WHERE directory_id = %d AND pending_generation = %d',
                self::STATUS_PENDING,
                'build',
                $target_id,
                current_time( 'mysql', true ),
                (int) $directory_id,
                (int) $generation
            )
        );

        self::flush( $directory_id );

        return 1 === $updated;
    }

    public static function advance_verification( $directory_id, $generation, $cursor, $processed, $errors ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Listing_Index_Schema::state_table() . ' SET last_listing_id = %d, processed = processed + %d, errors = errors + %d, updated_at = %s WHERE directory_id = %d AND pending_generation = %d AND status = %s AND phase = %s',
                (int) $cursor,
                (int) $processed,
                (int) $errors,
                current_time( 'mysql', true ),
                (int) $directory_id,
                (int) $generation,
                self::STATUS_BUILDING,
                'verify'
            )
        );

        self::flush( $directory_id );

        return 1 === $updated;
    }

    public static function activate_pending( $directory_id, $generation ) {
        global $wpdb;

        $state = self::get( $directory_id, true );

        if ( (int) $state['pending_generation'] !== (int) $generation || self::STATUS_BUILDING !== $state['status'] ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Listing_Index_Schema::state_table() . ' SET status = %s, active_generation = pending_generation, active_config_hash = pending_config_hash, active_manifest = pending_manifest, pending_generation = 0, pending_config_hash = %s, pending_manifest = %s, phase = %s, last_listing_id = 0, target_id = 0, processed = 0, errors = 0, updated_at = %s WHERE directory_id = %d AND pending_generation = %d AND status = %s AND phase = %s AND errors = 0',
                self::STATUS_READY,
                '',
                '',
                '',
                current_time( 'mysql', true ),
                (int) $directory_id,
                (int) $generation,
                self::STATUS_BUILDING,
                'verify'
            )
        );

        if ( 1 !== $updated ) {
            self::flush( $directory_id );
            return false;
        }

        self::flush( $directory_id );

        if ( $state['active_generation'] && (int) $state['active_generation'] !== (int) $generation ) {
            foreach ( Listing_Index_Schema::field_tables() as $field_table ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->delete(
                    $field_table,
                    [
                        'directory_id' => (int) $directory_id,
                        'generation'   => (int) $state['active_generation'],
                    ],
                    [ '%d', '%d' ]
                );
            }
        }

        do_action( 'directorist_listing_index_directory_activated', (int) $directory_id, (int) $generation );

        return true;
    }

    public static function next_queued() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $directory_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT directory_id FROM ' . Listing_Index_Schema::state_table() . ' WHERE status IN (%s, %s) AND pending_generation > 0 ORDER BY updated_at ASC, directory_id ASC LIMIT 1',
                self::STATUS_PENDING,
                self::STATUS_BUILDING
            )
        );

        return $directory_id ? self::get( (int) $directory_id, true ) : null;
    }

    public static function has_queued() {
        return null !== self::next_queued();
    }

    public static function delete( $directory_id ) {
        global $wpdb;

        $directory_id = (int) $directory_id;

        foreach ( Listing_Index_Schema::field_tables() as $field_table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->delete( $field_table, [ 'directory_id' => $directory_id ], [ '%d' ] );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( Listing_Index_Schema::state_table(), [ 'directory_id' => $directory_id ], [ '%d' ] );
        self::flush( $directory_id );
    }

    public static function flush( $directory_id ) {
        wp_cache_delete( self::cache_key( $directory_id ), self::CACHE_GROUP );
    }

    private static function activate_configuration( $directory_id, array $manifest, $delete_other_generations = true ) {
        global $wpdb;

        $directory_id = (int) $directory_id;
        $state        = self::get( $directory_id, true );
        $encoded      = self::encode_manifest( $manifest );
        $hash         = md5( $encoded );

        if ( $hash === $state['active_config_hash'] && $state['active_generation'] ) {
            return (int) $state['active_generation'];
        }

        $generation = max( (int) $state['active_generation'], (int) $state['pending_generation'] ) + 1;

        self::write(
            $directory_id,
            [
                'status'              => self::STATUS_READY,
                'active_generation'   => $generation,
                'pending_generation'  => 0,
                'active_config_hash'  => $hash,
                'pending_config_hash' => '',
                'active_manifest'     => $encoded,
                'pending_manifest'    => '',
                'phase'               => '',
                'last_listing_id'     => 0,
                'target_id'           => 0,
                'processed'           => 0,
                'errors'              => 0,
            ],
            $state
        );

        if ( $delete_other_generations ) {
            foreach ( Listing_Index_Schema::field_tables() as $field_table ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$field_table} WHERE directory_id = %d AND generation <> %d",
                        $directory_id,
                        $generation
                    )
                );
            }
        }

        return $generation;
    }

    private static function write( $directory_id, array $changes, array $state = [] ) {
        global $wpdb;

        $state = array_merge( self::empty_state( $directory_id ), $state, $changes );
        $row   = [
            'directory_id'        => (int) $directory_id,
            'status'              => $state['status'],
            'active_generation'   => (int) $state['active_generation'],
            'pending_generation'  => (int) $state['pending_generation'],
            'active_config_hash'  => $state['active_config_hash'],
            'pending_config_hash' => $state['pending_config_hash'],
            'active_manifest'     => $state['active_manifest'],
            'pending_manifest'    => $state['pending_manifest'],
            'phase'               => $state['phase'],
            'last_listing_id'     => (int) $state['last_listing_id'],
            'target_id'           => (int) $state['target_id'],
            'processed'           => (int) $state['processed'],
            'errors'              => (int) $state['errors'],
            'updated_at'          => current_time( 'mysql', true ),
        ];

        $columns      = array_keys( $row );
        $placeholders = [ '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' ];
        $updates      = array_map(
            static function( $column ) {
                return $column . '=VALUES(' . $column . ')';
            },
            array_slice( $columns, 1 )
        );
        $sql          = 'INSERT INTO ' . Listing_Index_Schema::state_table();
        $sql         .= ' (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $placeholders ) . ')';
        $sql         .= ' ON DUPLICATE KEY UPDATE ' . implode( ',', $updates );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->query( $wpdb->prepare( $sql, array_values( $row ) ) );

        self::flush( $directory_id );

        return false !== $result;
    }

    private static function directory_has_listings( $directory_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = %s AND pm.meta_key = '_directory_type' AND pm.meta_value = %s LIMIT 1",
                ATBDP_POST_TYPE,
                (string) (int) $directory_id
            )
        );
    }

    private static function directory_max_listing_id( $directory_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = %s AND pm.meta_key = '_directory_type' AND pm.meta_value = %s",
                ATBDP_POST_TYPE,
                (string) (int) $directory_id
            )
        );
    }

    private static function normalize_state( array $state ) {
        foreach ( [ 'directory_id', 'active_generation', 'pending_generation', 'last_listing_id', 'target_id', 'processed', 'errors' ] as $key ) {
            $state[ $key ] = (int) $state[ $key ];
        }

        return $state;
    }

    private static function empty_state( $directory_id ) {
        return [
            'directory_id'        => (int) $directory_id,
            'status'              => 'missing',
            'active_generation'   => 0,
            'pending_generation'  => 0,
            'active_config_hash'  => '',
            'pending_config_hash' => '',
            'active_manifest'     => '',
            'pending_manifest'    => '',
            'phase'               => '',
            'last_listing_id'     => 0,
            'target_id'           => 0,
            'processed'           => 0,
            'errors'              => 0,
            'updated_at'          => '',
        ];
    }

    private static function encode_manifest( array $manifest ) {
        ksort( $manifest );

        return wp_json_encode( $manifest );
    }

    private static function decode_manifest( $manifest ) {
        $decoded = json_decode( (string) $manifest, true );

        return is_array( $decoded ) ? $decoded : [];
    }

    private static function cache_key( $directory_id ) {
        global $wpdb;

        return $wpdb->prefix . Listing_Index_Schema::VERSION . ':directory:' . (int) $directory_id;
    }
}
