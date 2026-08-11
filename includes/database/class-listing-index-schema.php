<?php
/**
 * Listing lookup table schema.
 *
 * Canonical listing data remains in WordPress posts, terms, and metadata. These
 * tables are derived indexes and can always be rebuilt from canonical data.
 *
 * @since 8.9.0
 */

namespace Directorist\database;

defined( 'ABSPATH' ) || exit;

class Listing_Index_Schema {
    const VERSION = '2.1.0';

    const DATA_VERSION = '2.1.0';

    const VERSION_OPTION = 'directorist_listing_index_schema_version';

    const DATA_VERSION_OPTION = 'directorist_listing_index_data_version';

    const STATUS_OPTION = 'directorist_listing_index_status';

    const HEALTH_OPTION = 'directorist_listing_index_schema_health';

    const REPAIR_REQUIRED_OPTION = 'directorist_listing_index_schema_repair_required';

    const STATUS_READY = 'ready';

    const STATUS_BUILDING = 'building';

    const STATUS_NEEDS_REBUILD = 'needs_rebuild';

    const STATUS_FAILED = 'failed';

    private static $compatible = [];

    private static $tables_present = [];

    public static function listing_table() {
        global $wpdb;

        return $wpdb->prefix . 'directorist_listing_index';
    }

    public static function field_table() {
        global $wpdb;

        return $wpdb->prefix . 'directorist_listing_field_index';
    }

    public static function state_table() {
        global $wpdb;

        return $wpdb->prefix . 'directorist_listing_index_state';
    }

    public static function create() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $listing_table   = self::listing_table();
        $field_table     = self::field_table();
        $state_table     = self::state_table();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $listing_sql = "CREATE TABLE {$listing_table} (
            listing_id bigint(20) unsigned NOT NULL,
            directory_id bigint(20) unsigned NOT NULL DEFAULT 0,
            directory_set tinyint(1) NOT NULL DEFAULT 0,
            post_status varchar(20) NOT NULL DEFAULT '',
            listing_status varchar(20) NOT NULL DEFAULT '',
            author_id bigint(20) unsigned NOT NULL DEFAULT 0,
            featured tinyint(1) NOT NULL DEFAULT 0,
            featured_set tinyint(1) NOT NULL DEFAULT 0,
            price decimal(20,6) DEFAULT NULL,
            price_signed bigint(20) DEFAULT NULL,
            price_set tinyint(1) NOT NULL DEFAULT 0,
            price_range varchar(50) NOT NULL DEFAULT '',
            price_range_set tinyint(1) NOT NULL DEFAULT 0,
            expiry_date datetime DEFAULT NULL,
            expiry_date_set tinyint(1) NOT NULL DEFAULT 0,
            never_expire tinyint(1) NOT NULL DEFAULT 0,
            never_expire_set tinyint(1) NOT NULL DEFAULT 0,
            latitude decimal(10,7) DEFAULT NULL,
            latitude_set tinyint(1) NOT NULL DEFAULT 0,
            longitude decimal(10,7) DEFAULT NULL,
            longitude_set tinyint(1) NOT NULL DEFAULT 0,
            rating decimal(8,4) DEFAULT NULL,
            rating_signed bigint(20) DEFAULT NULL,
            rating_set tinyint(1) NOT NULL DEFAULT 0,
            review_count bigint(20) unsigned NOT NULL DEFAULT 0,
            view_count bigint(20) unsigned NOT NULL DEFAULT 0,
            view_count_signed bigint(20) DEFAULT NULL,
            view_count_set tinyint(1) NOT NULL DEFAULT 0,
            post_date datetime NOT NULL,
            post_modified datetime NOT NULL,
            indexed_at datetime NOT NULL,
            PRIMARY KEY  (listing_id),
            KEY status_price (post_status,price_set,listing_id),
            KEY directory_listing (directory_id,listing_id),
            KEY directory_featured (directory_id,featured_set,featured,post_date,listing_id),
            KEY directory_price (directory_id,price_set,price,listing_id),
            KEY directory_price_signed (directory_id,price_set,price_signed,listing_id),
            KEY directory_rating (directory_id,rating_set,rating,listing_id),
            KEY directory_rating_signed (directory_id,rating_set,rating_signed,listing_id),
            KEY directory_views (directory_id,view_count_set,view_count,listing_id),
            KEY directory_views_signed (directory_id,view_count_set,view_count_signed,listing_id),
            KEY directory_expiry (directory_id,expiry_date_set,expiry_date,listing_id),
            KEY directory_never_expire (directory_id,never_expire_set,never_expire,listing_id),
            KEY directory_geo (directory_id,latitude,longitude,listing_id)
        ) {$charset_collate};";

        $field_sql = "CREATE TABLE {$field_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            listing_id bigint(20) unsigned NOT NULL,
            directory_id bigint(20) unsigned NOT NULL DEFAULT 0,
            generation bigint(20) unsigned NOT NULL DEFAULT 0,
            field_key varchar(128) NOT NULL,
            field_type varchar(32) NOT NULL DEFAULT '',
            value_string varchar(191) NOT NULL DEFAULT '',
            value_hash char(32) NOT NULL,
            search_scope varchar(34) NOT NULL DEFAULT '',
            search_document longtext NULL,
            value_num decimal(30,10) DEFAULT NULL,
            value_signed bigint(20) DEFAULT NULL,
            value_date datetime DEFAULT NULL,
            indexed_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY listing_generation_field_value (listing_id,generation,field_key,value_hash),
            KEY directory_generation_field_exact (directory_id,generation,field_key(64),value_string(64),listing_id),
            KEY directory_generation_field_num (directory_id,generation,field_key(64),value_num,listing_id),
            KEY directory_generation_field_signed (directory_id,generation,field_key(64),value_signed,listing_id),
            KEY directory_generation_field_date (directory_id,generation,field_key(64),value_date,listing_id),
            FULLTEXT KEY directory_generation_field_search (search_scope,search_document)
        ) {$charset_collate};";

        $state_sql = "CREATE TABLE {$state_table} (
            directory_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'ready',
            active_generation bigint(20) unsigned NOT NULL DEFAULT 0,
            pending_generation bigint(20) unsigned NOT NULL DEFAULT 0,
            active_config_hash char(32) NOT NULL DEFAULT '',
            pending_config_hash char(32) NOT NULL DEFAULT '',
            active_manifest longtext NULL,
            pending_manifest longtext NULL,
            phase varchar(12) NOT NULL DEFAULT '',
            last_listing_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_id bigint(20) unsigned NOT NULL DEFAULT 0,
            processed bigint(20) unsigned NOT NULL DEFAULT 0,
            errors bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (directory_id),
            KEY queue_status (status,directory_id)
        ) {$charset_collate};";

        dbDelta( $listing_sql );
        dbDelta( $field_sql );
        dbDelta( $state_sql );

        self::reset_request_cache();

        if ( ! self::ensure_index_shapes() || ! self::verify_schema() ) {
            update_option( self::STATUS_OPTION, self::STATUS_FAILED, false );
            return false;
        }

        update_option( self::VERSION_OPTION, self::VERSION, false );
        update_option(
            self::HEALTH_OPTION,
            [
                'version' => self::VERSION,
                'prefix'  => $wpdb->prefix,
                'checked' => time(),
            ],
            false
        );
        self::$compatible[ $wpdb->prefix ]     = true;
        self::$tables_present[ $wpdb->prefix ] = true;

        if ( self::DATA_VERSION !== get_option( self::DATA_VERSION_OPTION, '' ) ) {
            self::refresh_status_after_schema_change();
        }

        delete_option( self::REPAIR_REQUIRED_OPTION );

        return true;
    }

    public static function tables_exist() {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $tables   = [ self::listing_table(), self::field_table(), self::state_table() ];
        $exist    = true;

        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( ! $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table, 0 ) ) {
                $exist = false;
                break;
            }
        }

        $wpdb->suppress_errors( $suppress );

        return $exist;
    }

    public static function is_compatible() {
        global $wpdb;

        if ( isset( self::$compatible[ $wpdb->prefix ] ) ) {
            return self::$compatible[ $wpdb->prefix ];
        }

        if ( self::VERSION !== get_option( self::VERSION_OPTION, '' ) ) {
            self::$compatible[ $wpdb->prefix ] = false;
            return false;
        }

        if ( ! self::required_tables_exist() ) {
            self::mark_missing_tables();
            return false;
        }

        $health = get_option( self::HEALTH_OPTION, [] );
        $age    = is_array( $health ) ? time() - (int) ( $health['checked'] ?? 0 ) : PHP_INT_MAX;
        $fresh  = is_array( $health )
            && self::VERSION === ( $health['version'] ?? '' )
            && $wpdb->prefix === ( $health['prefix'] ?? '' )
            && 0 <= $age
            && $age < HOUR_IN_SECONDS;

        if ( $fresh ) {
            self::$compatible[ $wpdb->prefix ] = true;
            return true;
        }

        self::$compatible[ $wpdb->prefix ] = self::verify_schema();

        if ( self::$compatible[ $wpdb->prefix ] ) {
            update_option(
                self::HEALTH_OPTION,
                [
                    'version' => self::VERSION,
                    'prefix'  => $wpdb->prefix,
                    'checked' => time(),
                ],
                false
            );
        }

        return self::$compatible[ $wpdb->prefix ];
    }

    public static function verify_schema() {
        global $wpdb;

        if ( ! self::tables_exist() ) {
            self::$tables_present[ $wpdb->prefix ] = false;
            self::$compatible[ $wpdb->prefix ]     = false;
            return false;
        }

        $required_columns = [
            self::listing_table() => [
                'listing_id', 'directory_id', 'directory_set', 'post_status', 'listing_status', 'author_id', 'featured', 'featured_set',
                'price', 'price_signed', 'price_set', 'price_range', 'price_range_set', 'expiry_date', 'expiry_date_set',
                'never_expire', 'never_expire_set', 'latitude', 'latitude_set', 'longitude', 'longitude_set',
                'rating', 'rating_signed', 'rating_set', 'review_count', 'view_count', 'view_count_signed', 'view_count_set',
                'post_date', 'post_modified', 'indexed_at',
            ],
            self::field_table()   => [
                'id', 'listing_id', 'directory_id', 'generation', 'field_key', 'field_type', 'value_string', 'value_hash',
                'search_scope', 'search_document', 'value_num', 'value_signed', 'value_date', 'indexed_at',
            ],
            self::state_table()   => [
                'directory_id', 'status', 'active_generation', 'pending_generation', 'active_config_hash', 'pending_config_hash',
                'active_manifest', 'pending_manifest', 'phase', 'last_listing_id', 'target_id', 'processed', 'errors', 'updated_at',
            ],
        ];
        $required_indexes = self::required_indexes();

        foreach ( $required_columns as $table => $columns ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $stored_columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table, 0 );

            if ( array_diff( $columns, $stored_columns ) ) {
                self::$compatible[ $wpdb->prefix ] = false;
                return false;
            }
        }

        foreach ( $required_indexes as $table => $indexes ) {
            $types  = [];
            $stored = self::read_index_shapes( $table, $types );

            foreach ( $indexes as $name => $definition ) {
                $type_matches = 'directory_generation_field_search' !== $name || 'FULLTEXT' === ( $types[ $name ] ?? '' );

                if ( ! isset( $stored[ $name ] ) || self::normalize_index_definition( $definition ) !== $stored[ $name ] || ! $type_matches ) {
                    self::$compatible[ $wpdb->prefix ] = false;
                    return false;
                }
            }
        }

        self::$compatible[ $wpdb->prefix ]     = true;
        self::$tables_present[ $wpdb->prefix ] = true;

        return true;
    }

    /**
     * Clear request-local schema decisions.
     *
     * Persistent health remains cached, but every PHP request must confirm that
     * all derived tables still physically exist before it can use them.
     */
    public static function reset_request_cache() {
        global $wpdb;

        unset( self::$compatible[ $wpdb->prefix ], self::$tables_present[ $wpdb->prefix ] );
    }

    public static function repair_required() {
        return (bool) get_option( self::REPAIR_REQUIRED_OPTION, false );
    }

    public static function repair_missing_tables() {
        if ( ! self::repair_required() ) {
            return true;
        }

        return self::create();
    }

    public static function status() {
        return get_option( self::STATUS_OPTION, self::STATUS_NEEDS_REBUILD );
    }

    public static function is_ready() {
        if ( ! self::is_compatible() ) {
            return false;
        }

        if ( ! Listing_Index_Lifecycle::is_current_deployment_trusted() ) {
            Listing_Index_Lifecycle::invalidate_current_site( true );
            return false;
        }

        return self::DATA_VERSION === get_option( self::DATA_VERSION_OPTION, '' )
            && self::STATUS_READY === self::status();
    }

    /**
     * Stop derived reads until canonical data has been rebuilt and verified.
     */
    public static function invalidate_data() {
        delete_option( self::DATA_VERSION_OPTION );
        self::mark_status( self::STATUS_NEEDS_REBUILD );
    }

    public static function mark_status( $status ) {
        $allowed = [ self::STATUS_READY, self::STATUS_BUILDING, self::STATUS_NEEDS_REBUILD, self::STATUS_FAILED ];

        if ( ! in_array( $status, $allowed, true ) ) {
            return false;
        }

        return update_option( self::STATUS_OPTION, $status, false );
    }

    private static function required_tables_exist() {
        global $wpdb;

        if ( isset( self::$tables_present[ $wpdb->prefix ] ) ) {
            return self::$tables_present[ $wpdb->prefix ];
        }

        $suppress = $wpdb->suppress_errors();
        // This checks only physical presence. Full column and index validation
        // remains governed by the persisted schema-health window.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (%s, %s, %s)',
                self::listing_table(),
                self::field_table(),
                self::state_table()
            )
        );
        $error = $wpdb->last_error;
        $wpdb->suppress_errors( $suppress );

        if ( null === $count && $error ) {
            self::$tables_present[ $wpdb->prefix ] = self::tables_exist();
        } else {
            self::$tables_present[ $wpdb->prefix ] = 3 === (int) $count;
        }

        return self::$tables_present[ $wpdb->prefix ];
    }

    private static function mark_missing_tables() {
        global $wpdb;

        self::$compatible[ $wpdb->prefix ]     = false;
        self::$tables_present[ $wpdb->prefix ] = false;

        delete_option( self::HEALTH_OPTION );
        delete_option( self::DATA_VERSION_OPTION );
        self::mark_status( self::STATUS_NEEDS_REBUILD );

        if ( add_option( self::REPAIR_REQUIRED_OPTION, time(), '', 'no' ) ) {
            do_action( 'directorist_listing_index_schema_missing' );
        }
    }

    private static function refresh_status_after_schema_change() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $listing_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s",
                ATBDP_POST_TYPE
            )
        );

        self::mark_status( $listing_count ? self::STATUS_NEEDS_REBUILD : self::STATUS_READY );

        if ( ! $listing_count ) {
            update_option( self::DATA_VERSION_OPTION, self::DATA_VERSION, false );
            Listing_Index_Lifecycle::trust_current_deployment();
        }
    }

    private static function required_indexes() {
        return [
            self::listing_table() => [
                'PRIMARY'                 => 'listing_id',
                'status_price'            => 'post_status,price_set,listing_id',
                'directory_listing'       => 'directory_id,listing_id',
                'directory_featured'      => 'directory_id,featured_set,featured,post_date,listing_id',
                'directory_price'         => 'directory_id,price_set,price,listing_id',
                'directory_price_signed'  => 'directory_id,price_set,price_signed,listing_id',
                'directory_rating'        => 'directory_id,rating_set,rating,listing_id',
                'directory_rating_signed' => 'directory_id,rating_set,rating_signed,listing_id',
                'directory_views'         => 'directory_id,view_count_set,view_count,listing_id',
                'directory_views_signed'  => 'directory_id,view_count_set,view_count_signed,listing_id',
                'directory_expiry'        => 'directory_id,expiry_date_set,expiry_date,listing_id',
                'directory_never_expire'  => 'directory_id,never_expire_set,never_expire,listing_id',
                'directory_geo'           => 'directory_id,latitude,longitude,listing_id',
            ],
            self::field_table()   => [
                'PRIMARY'                           => 'id',
                'listing_generation_field_value'    => 'listing_id,generation,field_key,value_hash',
                'directory_generation_field_exact'  => 'directory_id,generation,field_key(64),value_string(64),listing_id',
                'directory_generation_field_num'    => 'directory_id,generation,field_key(64),value_num,listing_id',
                'directory_generation_field_signed' => 'directory_id,generation,field_key(64),value_signed,listing_id',
                'directory_generation_field_date'   => 'directory_id,generation,field_key(64),value_date,listing_id',
                'directory_generation_field_search' => 'search_scope,search_document',
            ],
            self::state_table()   => [
                'PRIMARY'      => 'directory_id',
                'queue_status' => 'status,directory_id',
            ],
        ];
    }

    private static function ensure_index_shapes() {
        global $wpdb;

        $obsolete = [
            self::listing_table() => [
                'directory_status', 'status_date', 'author_status',
                'directory_featured_v1', 'directory_price_v1', 'directory_price_signed_v1', 'directory_rating_v1',
                'directory_rating_signed_v1', 'directory_views_v1', 'directory_views_signed_v1',
            ],
            self::field_table()   => [
                'listing_field_value', 'directory_field_exact', 'directory_field_num', 'directory_field_signed',
                'directory_field_date', 'listing_field', 'directory_field_string', 'directory_field_hash',
            ],
        ];

        foreach ( $obsolete as $table => $indexes ) {
            $stored = self::read_index_shapes( $table );

            foreach ( $indexes as $name ) {
                if ( isset( $stored[ $name ] ) ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
                    $wpdb->query( "ALTER TABLE {$table} DROP INDEX {$name}" );
                }
            }
        }

        foreach ( self::required_indexes() as $table => $indexes ) {
            $types  = [];
            $stored = self::read_index_shapes( $table, $types );

            foreach ( $indexes as $name => $definition ) {
                $type_matches = 'directory_generation_field_search' !== $name || 'FULLTEXT' === ( $types[ $name ] ?? '' );

                if ( 'PRIMARY' === $name || ( isset( $stored[ $name ] ) && self::normalize_index_definition( $definition ) === $stored[ $name ] && $type_matches ) ) {
                    continue;
                }

                if ( isset( $stored[ $name ] ) ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
                    $wpdb->query( "ALTER TABLE {$table} DROP INDEX {$name}" );
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
                $index_type = 'directory_generation_field_search' === $name ? 'FULLTEXT KEY' : 'KEY';

                if ( false === $wpdb->query( "ALTER TABLE {$table} ADD {$index_type} {$name} ({$definition})" ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function read_index_shapes( $table, &$types = [] ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows   = $wpdb->get_results( 'SHOW INDEX FROM ' . $table, ARRAY_A );
        $shapes = [];

        foreach ( $rows as $row ) {
            $part                      = $row['Column_name'];
            $types[ $row['Key_name'] ] = strtoupper( $row['Index_type'] );

            if ( ! empty( $row['Sub_part'] ) ) {
                $part .= '(' . (int) $row['Sub_part'] . ')';
            }

            $shapes[ $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = $part;
        }

        foreach ( $shapes as $name => $parts ) {
            ksort( $parts );
            $shapes[ $name ] = implode( ',', $parts );
        }

        return $shapes;
    }

    private static function normalize_index_definition( $definition ) {
        return preg_replace( '/\s+/', '', strtolower( $definition ) );
    }
}
