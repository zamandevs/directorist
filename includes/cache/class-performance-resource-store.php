<?php

namespace Directorist\Cache;

/**
 * Rebuildable admin catalog for public Directorist resource variants.
 *
 * Public cache delivery never depends on this table.
 */
final class Performance_Resource_Store {
    const VERSION        = '1.0.4';
    const VERSION_OPTION = 'directorist_performance_resource_schema_version';
    const STATUS_OPTION  = 'directorist_performance_resource_catalog_status';
    const COVERAGE_GROUP = 'directorist-performance';
    const COVERAGE_KEY   = 'resource-coverage';

    /** @var array<string,bool> */
    private static $table_cache = [];

    public function table_name() {
        global $wpdb;

        return $wpdb->prefix . 'directorist_performance_resources';
    }

    public function create() {
        global $wpdb;

        $table           = $this->table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql             = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            logical_key varchar(191) NOT NULL,
            url_hash char(64) NOT NULL,
            resource_type varchar(20) NOT NULL,
            route_type varchar(64) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            language varchar(32) NOT NULL DEFAULT '',
            title text NOT NULL,
            url text NOT NULL,
            directory_ids text NULL,
            category_ids text NULL,
            location_ids text NULL,
            modified_at bigint(20) unsigned NOT NULL DEFAULT 0,
            discovered_at bigint(20) unsigned NOT NULL DEFAULT 0,
            scan_generation bigint(20) unsigned NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 1,
            cache_state varchar(20) NOT NULL DEFAULT 'uncached',
            cache_failure_code varchar(64) NOT NULL DEFAULT '',
            cache_created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            cache_expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
            cache_stale_until bigint(20) unsigned NOT NULL DEFAULT 0,
            cache_refresh_requested_at bigint(20) unsigned NOT NULL DEFAULT 0,
            cache_checked_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY resource_variant (url_hash,resource_type,scan_generation),
            KEY logical_type (resource_type,logical_key),
            KEY type_active_modified (resource_type,active,modified_at),
            KEY type_active_state (resource_type,active,cache_state),
            KEY cache_refresh_due (active,cache_state,cache_expires_at,cache_refresh_requested_at),
            KEY scan_generation (scan_generation,active)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $this->drop_mismatched_index( 'resource_variant', [ 'url_hash', 'resource_type', 'scan_generation' ] );
        dbDelta( $sql );
        self::$table_cache[ $table ] = $this->exists( true );

        if ( ! self::$table_cache[ $table ] ) {
            return false;
        }

        update_option( self::VERSION_OPTION, self::VERSION, false );
        $this->clear_coverage_cache();

        return true;
    }

    public function exists( $refresh = false ) {
        global $wpdb;

        $table = $this->table_name();

        $suppress = $wpdb->suppress_errors();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        $wpdb->suppress_errors( $suppress );

        self::$table_cache[ $table ] = $table === $found;

        return self::$table_cache[ $table ];
    }

    /** @return array */
    public function status() {
        $status = get_option( self::STATUS_OPTION, [] );

        return is_array( $status ) ? $status : [];
    }

    public function begin_generation( $generation = 0 ) {
        $requested = 0 < (int) $generation ? (int) $generation : time();

        for ( $attempt = 0; $attempt < 5; ++$attempt ) {
            $previous   = $this->status();
            $prior      = isset( $previous['generation'] ) ? max( 0, (int) $previous['generation'] ) : 0;
            $generation = max( $requested, $prior + 1 );
            $active     = isset( $previous['active_generation'] ) ? max( 0, (int) $previous['active_generation'] ) : ( 'ready' === ( isset( $previous['state'] ) ? $previous['state'] : '' ) ? $prior : 0 );
            $status     = [
                'state'             => 'building',
                'generation'        => $generation,
                'active_generation' => $active,
                'phase'             => 'listing',
                'cursor'            => 0,
                'processed'         => 0,
                'errors'            => 0,
                'started_at'        => time(),
                'updated_at'        => time(),
                'completed_at'      => 0,
            ];

            if ( $this->compare_and_swap_status( $previous, $status ) ) {
                $this->clear_coverage_cache();

                return $status;
            }
        }

        return false;
    }

    public function update_status( array $changes ) {
        $status               = array_merge( $this->status(), $changes );
        $status['updated_at'] = time();
        update_option( self::STATUS_OPTION, $status, false );

        return $status;
    }

    /** @return bool */
    public function is_building_generation( $generation ) {
        $status = $this->status();

        return 'building' === ( isset( $status['state'] ) ? $status['state'] : '' )
            && max( 1, (int) $generation ) === ( isset( $status['generation'] ) ? (int) $status['generation'] : 0 );
    }

    /** @return array|false */
    public function update_generation_status( $generation, array $changes ) {
        $generation = max( 1, (int) $generation );
        $current    = $this->status();

        if ( 'building' !== ( isset( $current['state'] ) ? $current['state'] : '' ) || $generation !== ( isset( $current['generation'] ) ? (int) $current['generation'] : 0 ) ) {
            return false;
        }

        $next               = array_merge( $current, $changes );
        $next['generation'] = $generation;
        $next['updated_at'] = time();
        if ( ! $this->compare_and_swap_status( $current, $next ) ) {
            return false;
        }

        return $next;
    }

    private function compare_and_swap_status( array $current, array $next ) {
        global $wpdb;

        if ( empty( $current ) && false === get_option( self::STATUS_OPTION, false ) ) {
            return add_option( self::STATUS_OPTION, $next, '', false );
        }

        // Compare the complete serialized value so a superseding worker cannot
        // be overwritten between the generation check and the status write.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                maybe_serialize( $next ),
                self::STATUS_OPTION,
                maybe_serialize( $current )
            )
        );

        if ( 1 !== (int) $updated ) {
            return false;
        }

        wp_cache_delete( self::STATUS_OPTION, 'options' );

        return true;
    }

    public function complete_generation( $generation ) {
        global $wpdb;

        if ( ! $this->exists() ) {
            return false;
        }

        $generation = max( 1, (int) $generation );
        $status     = $this->update_generation_status(
            $generation,
            [
                'state'             => 'ready',
                'active_generation' => $generation,
                'phase'             => 'complete',
                'cursor'            => 0,
                'completed_at'      => time(),
                'code'              => 'completed',
            ]
        );

        if ( false === $status ) {
            return false;
        }

        $table = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE scan_generation < %d", $generation ) );
        $this->clear_coverage_cache();

        return true;
    }

    /**
     * @param array[] $resources Resource variants.
     * @param int     $generation Scan generation.
     * @return int Stored rows.
     */
    public function upsert( array $resources, $generation ) {
        global $wpdb;

        if ( ! $this->exists() && ! $this->create() ) {
            return 0;
        }

        $stored = 0;
        $table  = $this->table_name();
        $now    = time();

        foreach ( $resources as $resource ) {
            $row = $this->normalize_row( $resource, $generation, $now );

            if ( empty( $row ) ) {
                continue;
            }

            $columns = array_keys( $row );
            $formats = [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ];
            $updates = [
                'logical_key = VALUES(logical_key)',
                'route_type = VALUES(route_type)',
                'object_id = VALUES(object_id)',
                'language = VALUES(language)',
                'title = VALUES(title)',
                'url = VALUES(url)',
                'directory_ids = VALUES(directory_ids)',
                'category_ids = VALUES(category_ids)',
                'location_ids = VALUES(location_ids)',
                'modified_at = VALUES(modified_at)',
                'discovered_at = VALUES(discovered_at)',
                'scan_generation = VALUES(scan_generation)',
                'active = 1',
            ];
            $sql     = "INSERT INTO {$table} (" . implode( ',', $columns ) . ') VALUES (' . implode( ',', $formats ) . ') ON DUPLICATE KEY UPDATE ' . implode( ',', $updates );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
            $result = $wpdb->query( $wpdb->prepare( $sql, array_values( $row ) ) );

            if ( false !== $result ) {
                ++$stored;
            }
        }

        if ( 0 < $stored ) {
            $this->clear_coverage_cache();
        }

        return $stored;
    }

    /**
     * Return cache coverage for logical resources, grouped by content type.
     *
     * A multilingual resource is cached only when every active URL variant is
     * current. Expired rows are evaluated at read time so summary polling does
     * not write to the resource catalog.
     *
     * @return array
     */
    public function coverage() {
        global $wpdb;

        $empty      = $this->empty_coverage();
        $status     = $this->status();
        $state      = isset( $status['state'] ) ? $status['state'] : '';
        $generation = 'ready' === $state
            ? ( isset( $status['generation'] ) ? max( 0, (int) $status['generation'] ) : 0 )
            : ( isset( $status['active_generation'] ) ? max( 0, (int) $status['active_generation'] ) : 0 );

        if ( ! $this->exists() || 1 > $generation ) {
            return $empty;
        }

        $cache_key = self::COVERAGE_KEY . '-' . $generation;
        $cached    = wp_cache_get( $cache_key, self::COVERAGE_GROUP );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $table      = $this->table_name();
        $now        = time();
        $state_rank = "CASE WHEN cache_state = 'current' AND cache_expires_at > 0 AND cache_expires_at < %d AND (cache_stale_until = 0 OR cache_stale_until < %d) THEN 2 WHEN cache_state = 'current' AND cache_expires_at > 0 AND cache_expires_at < %d THEN 1 WHEN cache_state = 'current' THEN 0 WHEN cache_state = 'stale' THEN 1 WHEN cache_state = 'expired' THEN 2 WHEN cache_state = 'invalidated' THEN 3 WHEN cache_state = 'uncached' THEN 4 WHEN cache_state = 'failed' THEN 5 WHEN cache_state = 'invalid' THEN 6 ELSE 7 END";
        $groups_sql = "SELECT resources.resource_type,resources.logical_key,MAX({$state_rank}) AS group_state_rank FROM {$table} AS resources WHERE resources.active = 1 AND resources.scan_generation = %d AND " . $this->public_post_condition( 'resources' ) . ' GROUP BY resources.resource_type,resources.logical_key';
        $sql        = "SELECT resource_type,COUNT(*) AS total,SUM(group_state_rank = 0) AS cached FROM ({$groups_sql}) AS resource_groups GROUP BY resource_type";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $now, $now, $now, $generation ), ARRAY_A );

        $coverage          = $empty;
        $coverage['ready'] = true;

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $type = isset( $row['resource_type'] ) ? sanitize_key( (string) $row['resource_type'] ) : '';

            if ( ! isset( $coverage['types'][ $type ] ) ) {
                continue;
            }

            $total                                       = max( 0, (int) $row['total'] );
            $current                                     = min( $total, max( 0, (int) $row['cached'] ) );
            $coverage['types'][ $type ]['total']         = $total;
            $coverage['types'][ $type ]['cached']        = $current;
            $coverage['types'][ $type ]['needs_refresh'] = $total - $current;
            $coverage['total']                          += $total;
            $coverage['cached']                         += $current;
        }

        $coverage['needs_refresh'] = $coverage['total'] - $coverage['cached'];
        wp_cache_set( $cache_key, $coverage, self::COVERAGE_GROUP, 5 );

        return $coverage;
    }

    /** @return array */
    public function query( array $args ) {
        global $wpdb;

        $status            = $this->status();
        $state             = isset( $status['state'] ) ? $status['state'] : '';
        $active_generation = 'ready' === $state
            ? ( isset( $status['generation'] ) ? max( 0, (int) $status['generation'] ) : 0 )
            : ( isset( $status['active_generation'] ) ? max( 0, (int) $status['active_generation'] ) : 0 );

        if ( ! $this->exists() || 1 > $active_generation ) {
            return [ 'items' => [], 'total' => 0, 'ready' => false, 'state_applied' => false ];
        }

        $this->refresh_expired_states();

        $type        = isset( $args['type'] ) ? sanitize_key( (string) $args['type'] ) : 'all';
        $search      = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';
        $page        = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
        $per_page    = isset( $args['per_page'] ) ? min( 5000, max( 1, absint( $args['per_page'] ) ) ) : 20;
        $orderby     = isset( $args['orderby'] ) ? sanitize_key( (string) $args['orderby'] ) : 'id';
        $order       = isset( $args['order'] ) && 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
        $cache_state = isset( $args['cache_state'] ) ? sanitize_key( (string) $args['cache_state'] ) : '';
        $where       = [ 'catalog.active = 1', 'catalog.scan_generation = %d', $this->public_post_condition( 'catalog' ) ];
        $values      = [ $active_generation ];

        if ( in_array( $type, [ 'listing', 'archive', 'page', 'search' ], true ) ) {
            $where[]  = 'catalog.resource_type = %s';
            $values[] = $type;
        }

        if ( '' !== $search ) {
            $where[]  = 'catalog.title LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        foreach ( [ 'directory_id' => 'directory_ids', 'category_id' => 'category_ids', 'location_id' => 'location_ids' ] as $arg => $column ) {
            if ( ! empty( $args[ $arg ] ) ) {
                $where[]  = "catalog.{$column} LIKE %s";
                $values[] = '%,' . absint( $args[ $arg ] ) . ',%';
            }
        }

        $where_sql    = implode( ' AND ', $where );
        $table        = $this->table_name();
        $state_rank   = "CASE resources.cache_state WHEN 'current' THEN 0 WHEN 'stale' THEN 1 WHEN 'expired' THEN 2 WHEN 'invalidated' THEN 3 WHEN 'uncached' THEN 4 WHEN 'failed' THEN 5 WHEN 'invalid' THEN 6 ELSE 7 END";
        $group_state  = "CASE MAX({$state_rank}) WHEN 0 THEN 'current' WHEN 1 THEN 'stale' WHEN 2 THEN 'expired' WHEN 3 THEN 'invalidated' WHEN 4 THEN 'uncached' WHEN 5 THEN 'failed' ELSE 'invalid' END";
        $matching_sql = "SELECT DISTINCT catalog.resource_type,catalog.logical_key FROM {$table} AS catalog WHERE {$where_sql}";
        $group_sql    = "SELECT MIN(resources.id) AS representative_id, resources.logical_key, resources.resource_type, MAX(resources.modified_at) AS group_modified, {$group_state} AS group_state, MAX({$state_rank}) AS group_state_rank, MAX(resources.cache_created_at) AS group_cache_created_at, MIN(NULLIF(resources.cache_expires_at, 0)) AS group_cache_expires_at, MIN(NULLIF(resources.cache_stale_until, 0)) AS group_cache_stale_until, MAX(resources.cache_checked_at) AS group_cache_checked_at, MAX(resources.scan_generation) AS active_generation FROM {$table} AS resources INNER JOIN ({$matching_sql}) AS matches ON matches.resource_type = resources.resource_type AND matches.logical_key = resources.logical_key WHERE resources.active = 1 AND resources.scan_generation = %d AND " . $this->public_post_condition( 'resources' ) . ' GROUP BY resources.resource_type,resources.logical_key';
        $group_values = array_merge( $values, [ $active_generation ] );
        $state_ranks  = [
            'current'     => 0,
            'stale'       => 1,
            'expired'     => 2,
            'invalidated' => 3,
            'uncached'    => 4,
            'failed'      => 5,
            'invalid'     => 6,
        ];

        if ( 'needs-refresh' === $cache_state ) {
            $group_sql .= ' HAVING group_state_rank > 0';
        } elseif ( isset( $state_ranks[ $cache_state ] ) ) {
            $group_sql .= ' HAVING group_state_rank = ' . (int) $state_ranks[ $cache_state ];
        }

        $order_sql = 'cache_state' === $orderby ? 'group_state_rank' : ( 'modified' === $orderby ? 'group_modified' : 'representative_id' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        $groups    = $wpdb->get_results( $wpdb->prepare( $group_sql . " ORDER BY {$order_sql} {$order}, representative_id {$order} LIMIT %d OFFSET %d", array_merge( $group_values, [ $per_page, ( $page - 1 ) * $per_page ] ) ), ARRAY_A );
        $total_sql = "SELECT COUNT(*) FROM ({$group_sql}) AS directorist_resource_groups";

        if ( ! empty( $group_values ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total_sql = $wpdb->prepare( $total_sql, $group_values );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $total_sql );

        return [ 'items' => $this->hydrate_groups( is_array( $groups ) ? $groups : [] ), 'total' => $total, 'ready' => true, 'state_applied' => true ];
    }

    /**
     * Resolve every active URL belonging to the same logical resource.
     *
     * @param string $url Primary or translated resource URL.
     * @param string $route_type Directorist route type.
     * @return array<int,array{url:string,language:string}>
     */
    public function get_variants( $url, $route_type ) {
        global $wpdb;

        $url        = esc_url_raw( (string) $url );
        $route_type = substr( sanitize_key( (string) $route_type ), 0, 64 );
        $status     = $this->status();
        $state      = isset( $status['state'] ) ? $status['state'] : '';
        $generation = 'ready' === $state
            ? ( isset( $status['generation'] ) ? max( 0, (int) $status['generation'] ) : 0 )
            : ( isset( $status['active_generation'] ) ? max( 0, (int) $status['active_generation'] ) : 0 );

        if ( ! $this->exists() || 1 > $generation || '' === $url || '' === $route_type ) {
            return [];
        }

        $table = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $resource = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT resources.resource_type,resources.logical_key FROM {$table} AS resources WHERE resources.active = 1 AND resources.scan_generation = %d AND resources.route_type = %s AND resources.url_hash = %s AND " . $this->public_post_condition( 'resources' ) . ' LIMIT 1',
                $generation,
                $route_type,
                hash( 'sha256', $url )
            ),
            ARRAY_A
        );

        if ( ! is_array( $resource ) ) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $variants = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT resources.url,resources.language FROM {$table} AS resources WHERE resources.active = 1 AND resources.scan_generation = %d AND resources.resource_type = %s AND resources.logical_key = %s AND " . $this->public_post_condition( 'resources' ) . ' ORDER BY resources.id ASC LIMIT 50',
                $generation,
                $resource['resource_type'],
                $resource['logical_key']
            ),
            ARRAY_A
        );

        return is_array( $variants ) ? $variants : [];
    }

    public function update_cache_state( $url, array $state ) {
        global $wpdb;

        if ( ! $this->exists() ) {
            return false;
        }

        $url     = esc_url_raw( (string) $url );
        $value   = isset( $state['state'] ) ? sanitize_key( (string) $state['state'] ) : 'uncached';
        $value   = in_array( $value, [ 'uncached', 'current', 'stale', 'expired', 'invalidated', 'invalid', 'failed' ], true ) ? $value : 'uncached';
        $data    = [
            'cache_state'        => $value,
            'cache_failure_code' => 'failed' === $value && isset( $state['failure_code'] ) ? substr( sanitize_key( (string) $state['failure_code'] ), 0, 64 ) : '',
            'cache_checked_at'   => time(),
        ];
        $formats = [ '%s', '%s', '%d' ];

        foreach ( [ 'created_at' => 'cache_created_at', 'expires_at' => 'cache_expires_at', 'stale_until' => 'cache_stale_until', 'refresh_requested_at' => 'cache_refresh_requested_at' ] as $source => $column ) {
            if ( array_key_exists( $source, $state ) ) {
                $data[ $column ] = max( 0, (int) $state[ $source ] );
                $formats[]       = '%d';
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = false !== $wpdb->update(
            $this->table_name(),
            $data,
            [ 'url_hash' => hash( 'sha256', $url ) ],
            $formats,
            [ '%s' ]
        );

        if ( $updated ) {
            $this->clear_coverage_cache();
        }

        return $updated;
    }

    /**
     * Return one bounded set of known variants approaching soft expiry.
     *
     * @param int $before Soft-expiry upper bound.
     * @param int $limit Maximum URLs.
     * @param int $claim_before Ignore claims newer than this timestamp.
     * @return string[]
     */
    public function due_refresh_urls( $before, $limit = 25, $claim_before = 0 ) {
        global $wpdb;

        $status     = $this->status();
        $state      = isset( $status['state'] ) ? $status['state'] : '';
        $generation = 'ready' === $state
            ? ( isset( $status['generation'] ) ? max( 0, (int) $status['generation'] ) : 0 )
            : ( isset( $status['active_generation'] ) ? max( 0, (int) $status['active_generation'] ) : 0 );

        if ( ! $this->exists() || 1 > $generation ) {
            return [];
        }

        $before       = max( 1, (int) $before );
        $limit        = min( 50, max( 1, (int) $limit ) );
        $claim_before = max( 0, (int) $claim_before );
        $table        = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $urls = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT resources.url FROM {$table} AS resources WHERE resources.active = 1 AND resources.scan_generation = %d AND resources.cache_state IN ('current','stale','expired') AND resources.cache_expires_at > 0 AND resources.cache_expires_at <= %d AND (resources.cache_refresh_requested_at = 0 OR resources.cache_refresh_requested_at < %d) AND " . $this->public_post_condition( 'resources' ) . ' ORDER BY resources.cache_expires_at ASC,resources.id ASC LIMIT %d',
                $generation,
                $before,
                $claim_before,
                $limit
            )
        );

        return array_values( array_filter( array_map( 'esc_url_raw', is_array( $urls ) ? $urls : [] ) ) );
    }

    /**
     * @param string[] $urls Accepted Preload URLs.
     * @param int      $requested_at Claim timestamp.
     * @return bool
     */
    public function mark_refresh_requested( array $urls, $requested_at = 0 ) {
        global $wpdb;

        $hashes = array_values( array_unique( array_map( static function ( $url ) { return hash( 'sha256', esc_url_raw( (string) $url ) ); }, array_filter( $urls ) ) ) );

        if ( ! $this->exists() || empty( $hashes ) ) {
            return false;
        }

        $placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
        $values       = array_merge( [ max( 1, (int) $requested_at ) ], $hashes );
        $table        = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $updated = false !== $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET cache_refresh_requested_at = %d WHERE url_hash IN ({$placeholders})",
                $values
            )
        );

        return $updated;
    }

    /**
     * Remove every catalog generation for one post-backed resource.
     *
     * @param int $post_id WordPress post ID.
     * @return array{success:bool,code:string,urls:string[],removed:int}
     */
    public function remove_post_resources( $post_id ) {
        global $wpdb;

        $post_id = absint( $post_id );

        if ( 1 > $post_id || ! $this->exists() ) {
            return [ 'success' => false, 'code' => 'catalog-unavailable', 'urls' => [], 'removed' => 0 ];
        }

        $table = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $urls = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT url FROM {$table} WHERE object_id = %d AND resource_type IN ('listing','page','search')",
                $post_id
            )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $removed = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE object_id = %d AND resource_type IN ('listing','page','search')",
                $post_id
            )
        );

        if ( false === $removed ) {
            return [ 'success' => false, 'code' => 'remove-failed', 'urls' => [], 'removed' => 0 ];
        }

        $urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', is_array( $urls ) ? $urls : [] ) ) ) );
        $this->clear_coverage_cache();

        return [ 'success' => true, 'code' => 'removed', 'urls' => $urls, 'removed' => (int) $removed ];
    }

    public function mark_all_cache_state( $state ) {
        global $wpdb;

        if ( ! $this->exists() ) {
            return false;
        }

        $state = sanitize_key( (string) $state );

        if ( ! in_array( $state, [ 'uncached', 'invalidated' ], true ) ) {
            return false;
        }

        $reset = 'uncached' === $state
            ? ', cache_created_at = 0, cache_expires_at = 0, cache_stale_until = 0, cache_refresh_requested_at = 0'
            : '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $updated = false !== $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . $this->table_name() . " SET cache_state = %s, cache_failure_code = '', cache_checked_at = %d{$reset} WHERE active = 1",
                $state,
                time()
            )
        );

        if ( $updated ) {
            $this->clear_coverage_cache();
        }

        return $updated;
    }

    private function empty_coverage() {
        $types = [];

        foreach ( [ 'listing', 'archive', 'page', 'search' ] as $type ) {
            $types[ $type ] = [ 'total' => 0, 'cached' => 0, 'needs_refresh' => 0 ];
        }

        return [
            'ready'         => false,
            'total'         => 0,
            'cached'        => 0,
            'needs_refresh' => 0,
            'types'         => $types,
        ];
    }

    private function clear_coverage_cache() {
        $status     = $this->status();
        $generation = isset( $status['generation'] ) ? max( 0, (int) $status['generation'] ) : 0;
        $active     = isset( $status['active_generation'] ) ? max( 0, (int) $status['active_generation'] ) : 0;

        foreach ( array_unique( array_filter( [ $generation, $active ] ) ) as $candidate ) {
            wp_cache_delete( self::COVERAGE_KEY . '-' . $candidate, self::COVERAGE_GROUP );
        }
    }

    private function normalize_row( array $resource, $generation, $now ) {
        $url       = isset( $resource['url'] ) ? esc_url_raw( (string) $resource['url'] ) : '';
        $type      = isset( $resource['type'] ) ? sanitize_key( (string) $resource['type'] ) : '';
        $object_id = isset( $resource['object_id'] ) ? max( 0, (int) $resource['object_id'] ) : 0;

        if ( '' === $url || ! in_array( $type, [ 'listing', 'archive', 'page', 'search' ], true ) || ! $this->is_public_post_resource( $type, $object_id ) ) {
            return [];
        }

        return [
            'logical_key'     => substr( sanitize_key( isset( $resource['logical_key'] ) ? (string) $resource['logical_key'] : $type . '-' . ( isset( $resource['object_id'] ) ? (int) $resource['object_id'] : hash( 'sha256', $url ) ) ), 0, 191 ),
            'url_hash'        => hash( 'sha256', $url ),
            'resource_type'   => $type,
            'route_type'      => substr( sanitize_key( isset( $resource['route_type'] ) ? (string) $resource['route_type'] : $type ), 0, 64 ),
            'object_id'       => $object_id,
            'language'        => substr( sanitize_key( isset( $resource['language'] ) ? (string) $resource['language'] : '' ), 0, 32 ),
            'title'           => $this->normalize_title( isset( $resource['title'] ) ? $resource['title'] : '' ),
            'url'             => $url,
            'directory_ids'   => $this->id_set( isset( $resource['directory_ids'] ) ? $resource['directory_ids'] : [] ),
            'category_ids'    => $this->id_set( isset( $resource['category_ids'] ) ? $resource['category_ids'] : [] ),
            'location_ids'    => $this->id_set( isset( $resource['location_ids'] ) ? $resource['location_ids'] : [] ),
            'modified_at'     => isset( $resource['modified_at'] ) ? max( 0, (int) $resource['modified_at'] ) : 0,
            'discovered_at'   => max( 0, (int) $now ),
            'scan_generation' => max( 1, (int) $generation ),
            'active'          => 1,
        ];
    }

    private function normalize_title( $title ) {
        $charset = get_bloginfo( 'charset' );
        $charset = is_string( $charset ) && '' !== $charset ? $charset : 'UTF-8';

        return sanitize_text_field( html_entity_decode( (string) $title, ENT_QUOTES | ENT_HTML5, $charset ) );
    }

    private function hydrate_groups( array $groups ) {
        global $wpdb;

        if ( empty( $groups ) ) {
            return [];
        }

        $ids          = array_map( 'absint', wp_list_pluck( $groups, 'representative_id' ) );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $table        = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $representatives = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids ), ARRAY_A );
        $by_id           = [];

        foreach ( is_array( $representatives ) ? $representatives : [] as $row ) {
            $by_id[ (int) $row['id'] ] = $row;
        }

        $variant_clauses = [];
        $variant_values  = [];

        foreach ( $groups as $group ) {
            $variant_clauses[] = '(resources.resource_type = %s AND resources.logical_key = %s AND resources.scan_generation = %d)';
            $variant_values[]  = $group['resource_type'];
            $variant_values[]  = $group['logical_key'];
            $variant_values[]  = (int) $group['active_generation'];
        }

        $variant_sql = "SELECT resources.resource_type,resources.logical_key,resources.url,resources.language,resources.cache_state,resources.cache_failure_code,resources.cache_checked_at FROM {$table} AS resources WHERE resources.active = 1 AND " . $this->public_post_condition( 'resources' ) . ' AND (' . implode( ' OR ', $variant_clauses ) . ') ORDER BY resources.id ASC';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        $variant_rows      = $wpdb->get_results( $wpdb->prepare( $variant_sql, $variant_values ), ARRAY_A );
        $variants_by_group = [];

        foreach ( is_array( $variant_rows ) ? $variant_rows : [] as $variant ) {
            $variants_by_group[ $variant['resource_type'] . '|' . $variant['logical_key'] ][] = $variant;
        }

        $items = [];

        foreach ( $groups as $group ) {
            $id = (int) $group['representative_id'];

            if ( ! isset( $by_id[ $id ] ) ) {
                continue;
            }

            $row      = $by_id[ $id ];
            $variants = isset( $variants_by_group[ $row['resource_type'] . '|' . $row['logical_key'] ] ) ? $variants_by_group[ $row['resource_type'] . '|' . $row['logical_key'] ] : [];
            $failures = [];

            foreach ( $variants as $variant ) {
                if ( 'failed' !== $variant['cache_state'] || '' === $variant['cache_failure_code'] ) {
                    continue;
                }

                $failures[] = [
                    'url'        => $variant['url'],
                    'language'   => $variant['language'],
                    'code'       => $variant['cache_failure_code'],
                    'checked_at' => max( 0, (int) $variant['cache_checked_at'] ),
                ];
            }

            $failure_code = ! empty( $failures ) ? $failures[0]['code'] : '';
            $items[]      = [
                'id'                 => $row['resource_type'] . '-' . $row['logical_key'],
                'logical_key'        => $row['logical_key'],
                'title'              => $row['title'],
                'url'                => $row['url'],
                'type'               => $row['resource_type'],
                'route_type'         => $row['route_type'],
                'object_id'          => (int) $row['object_id'],
                'modified_at'        => (int) $group['group_modified'],
                'variant_urls'       => wp_list_pluck( $variants, 'url' ),
                'languages'          => array_values( array_filter( wp_list_pluck( $variants, 'language' ) ) ),
                'variants'           => count( $variants ),
                'failures'           => $failures,
                'stored_cache_state' => $group['group_state'],
                'stored_cache'       => [
                    'state'        => $group['group_state'],
                    'created_at'   => isset( $group['group_cache_created_at'] ) ? max( 0, (int) $group['group_cache_created_at'] ) : 0,
                    'expires_at'   => isset( $group['group_cache_expires_at'] ) ? max( 0, (int) $group['group_cache_expires_at'] ) : 0,
                    'stale_until'  => isset( $group['group_cache_stale_until'] ) ? max( 0, (int) $group['group_cache_stale_until'] ) : 0,
                    'checked_at'   => isset( $group['group_cache_checked_at'] ) ? max( 0, (int) $group['group_cache_checked_at'] ) : 0,
                    'variants'     => count( $variants ),
                    'failure_code' => $failure_code,
                ],
            ];
        }

        return $items;
    }

    private function is_public_post_resource( $type, $object_id ) {
        if ( 1 > $object_id || ! in_array( $type, [ 'listing', 'page', 'search' ], true ) ) {
            return true;
        }

        $post = get_post( $object_id );

        if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
            return false;
        }

        return 'listing' === $type ? ATBDP_POST_TYPE === $post->post_type : 'page' === $post->post_type;
    }

    private function public_post_condition( $alias ) {
        global $wpdb;

        $prefix            = '' !== $alias ? sanitize_key( $alias ) . '.' : '';
        $listing_post_type = esc_sql( ATBDP_POST_TYPE );

        return "({$prefix}object_id = 0 OR {$prefix}resource_type = 'archive' OR ({$prefix}resource_type = 'listing' AND EXISTS (SELECT 1 FROM {$wpdb->posts} AS directorist_public_posts WHERE directorist_public_posts.ID = {$prefix}object_id AND directorist_public_posts.post_type = '{$listing_post_type}' AND directorist_public_posts.post_status = 'publish')) OR ({$prefix}resource_type IN ('page','search') AND EXISTS (SELECT 1 FROM {$wpdb->posts} AS directorist_public_pages WHERE directorist_public_pages.ID = {$prefix}object_id AND directorist_public_pages.post_type = 'page' AND directorist_public_pages.post_status = 'publish')))";
    }

    private function id_set( $ids ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : [ $ids ] ) ) ) );
        sort( $ids, SORT_NUMERIC );

        return empty( $ids ) ? '' : ',' . implode( ',', $ids ) . ',';
    }

    private function drop_mismatched_index( $name, array $expected_columns ) {
        global $wpdb;

        if ( ! $this->exists( true ) ) {
            return;
        }

        $table = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . $table, ARRAY_A );
        $columns = [];

        foreach ( is_array( $indexes ) ? $indexes : [] as $index ) {
            if ( $name === $index['Key_name'] ) {
                $columns[ (int) $index['Seq_in_index'] ] = $index['Column_name'];
            }
        }

        if ( empty( $columns ) ) {
            return;
        }

        ksort( $columns, SORT_NUMERIC );

        if ( array_values( $columns ) === array_values( $expected_columns ) ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query( 'ALTER TABLE ' . $table . ' DROP INDEX ' . $name );
    }

    private function refresh_expired_states() {
        global $wpdb;

        $table = $this->table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $now = time();
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET cache_state = CASE WHEN cache_stale_until = 0 OR cache_stale_until < %d THEN 'expired' ELSE 'stale' END, cache_checked_at = %d WHERE active = 1 AND cache_state IN ('current','stale') AND cache_expires_at > 0 AND cache_expires_at < %d",
                $now,
                $now,
                $now
            )
        );
    }
}
