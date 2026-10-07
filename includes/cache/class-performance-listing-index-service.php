<?php

namespace Directorist\Cache;

use Directorist\database\Listing_Index;
use Directorist\database\Listing_Index_Directory_State;
use Directorist\database\Listing_Index_Lifecycle;
use Directorist\database\Listing_Index_Maintenance;
use Directorist\database\Listing_Index_Schema;

/**
 * User-facing Listing Index status and maintenance boundary.
 */
final class Performance_Listing_Index_Service {
    const MAX_PER_PAGE      = 50;
    const CACHE_GROUP       = 'directorist-performance';
    const METRICS_CACHE_KEY = 'listing-index-counts-v1';

    /** @return array */
    public function get_summary() {
        $schema_healthy = Listing_Index_Schema::is_compatible();
        $status         = Listing_Index_Schema::status();
        $data_current   = $schema_healthy
            && Listing_Index_Schema::DATA_VERSION === get_option( Listing_Index_Schema::DATA_VERSION_OPTION, '' )
            && Listing_Index_Schema::STATUS_READY === $status
            && Listing_Index_Lifecycle::is_current_deployment_trusted();
        $reads_active   = $data_current && Listing_Index::is_enabled();
        $background     = Listing_Index_Maintenance::background_process();
        $metrics        = $this->listing_metrics( $schema_healthy );
        $canonical      = $metrics['canonical'];
        $indexed        = $metrics['indexed'];

        return [
            'state'              => $this->global_state( $schema_healthy, $data_current, $reads_active, $status ),
            'schema_healthy'     => $schema_healthy,
            'data_current'       => $data_current,
            'reads_active'       => $reads_active,
            'canonical_listings' => max( 0, $canonical ),
            'indexed_listings'   => max( 0, $indexed ),
            'queued'             => $background->has_queued_work(),
            'running'            => $background->is_running(),
            'phase'              => in_array( get_option( Listing_Index::REBUILD_PHASE_OPTION, '' ), [ 'build', 'verify' ], true ) ? get_option( Listing_Index::REBUILD_PHASE_OPTION, '' ) : '',
            'repair_available'   => Listing_Index_Schema::repair_required(),
        ];
    }

    /**
     * @param array $args Pagination and search.
     * @return array
     */
    public function get_directories( array $args = [] ) {
        $page     = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
        $per_page = isset( $args['per_page'] ) ? min( self::MAX_PER_PAGE, max( 1, absint( $args['per_page'] ) ) ) : 20;
        $search   = isset( $args['search'] ) ? substr( sanitize_text_field( (string) $args['search'] ), 0, 100 ) : '';
        $base     = [
            'taxonomy'   => ATBDP_DIRECTORY_TYPE,
            'hide_empty' => false,
            'search'     => $search,
        ];
        $total    = wp_count_terms( $base );
        $total    = is_wp_error( $total ) ? 0 : (int) $total;
        $terms    = get_terms(
            array_merge(
                $base,
                [
                    'number'  => $per_page,
                    'offset'  => ( $page - 1 ) * $per_page,
                    'orderby' => 'name',
                    'order'   => 'ASC',
                ]
            )
        );
        $terms    = is_wp_error( $terms ) ? [] : $terms;
        $counts   = $this->listing_counts( wp_list_pluck( $terms, 'term_id' ) );
        $items    = [];

        foreach ( $terms as $term ) {
            $state    = Listing_Index_Directory_State::get( $term->term_id );
            $manifest = json_decode( (string) $state['active_manifest'], true );
            $listings = isset( $counts[ $term->term_id ] ) ? (int) $counts[ $term->term_id ] : 0;
            $progress = $this->directory_progress( $state, $listings );

            $items[] = [
                'id'            => (int) $term->term_id,
                'name'          => sanitize_text_field( $term->name ),
                'listings'      => $listings,
                'filter_fields' => is_array( $manifest ) ? count( $manifest ) : 0,
                'state'         => $this->directory_state( $state ),
                'phase'         => in_array( $state['phase'], [ 'build', 'verify' ], true ) ? $state['phase'] : '',
                'progress'      => $progress,
                'updated_at'    => '' === $state['updated_at'] ? '' : mysql2date( 'c', $state['updated_at'], false ),
                'actions'       => [ 'regenerate' => true ],
            ];
        }

        $pages = max( 1, (int) ceil( $total / $per_page ) );

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => min( $page, $pages ),
            'per_page' => $per_page,
            'pages'    => $pages,
            'search'   => $search,
        ];
    }

    /**
     * @param int $directory_id Directory type term ID.
     * @return array
     */
    public function regenerate_directory( $directory_id ) {
        $directory_id = absint( $directory_id );

        if ( ! $directory_id || ! term_exists( $directory_id, ATBDP_DIRECTORY_TYPE ) ) {
            return $this->result( false, 'directory-not-found' );
        }

        if ( ! Listing_Index_Schema::is_compatible() ) {
            return $this->result( false, 'index-unavailable' );
        }

        $queued = Listing_Index_Directory_State::queue_rebuild( $directory_id );

        if ( ! $queued ) {
            $state = Listing_Index_Directory_State::get( $directory_id, true );

            if ( in_array( $state['status'], [ Listing_Index_Directory_State::STATUS_PENDING, Listing_Index_Directory_State::STATUS_BUILDING ], true ) ) {
                return $this->result( true, 'already-queued' );
            }

            return $this->result( false, 'regeneration-not-queued' );
        }

        $this->flush_metrics();

        return $this->result( true, 'regeneration-queued' );
    }

    /** @return array */
    public function regenerate_all() {
        Listing_Index_Schema::invalidate_data();
        $queued = Listing_Index_Maintenance::schedule();
        $this->flush_metrics();

        return $this->result( (bool) $queued, $queued ? 'regeneration-queued' : 'regeneration-not-queued' );
    }

    /**
     * @param bool $enabled Requested read state.
     * @return array
     */
    public function set_reads_enabled( $enabled ) {
        if ( $enabled && ! Listing_Index_Schema::is_ready() ) {
            return $this->result( false, 'index-not-ready' );
        }

        Listing_Index::set_enabled( (bool) $enabled );

        return $this->result( true, $enabled ? 'reads-enabled' : 'reads-disabled' );
    }

    /** @return array */
    public function repair() {
        $this->flush_metrics();

        if ( Listing_Index_Schema::repair_required() && ! Listing_Index_Schema::repair_missing_tables() ) {
            return $this->result( false, 'repair-failed' );
        }

        return $this->regenerate_all();
    }

    private function listing_counts( array $directory_ids ) {
        global $wpdb;

        $directory_ids = array_values( array_filter( array_map( 'absint', $directory_ids ) ) );

        if ( empty( $directory_ids ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $directory_ids ), '%d' ) );
        $sql          = "SELECT CAST(pm.meta_value AS UNSIGNED) AS directory_id, COUNT(DISTINCT p.ID) AS listing_count FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = %s AND p.post_status = %s AND pm.meta_key = %s AND CAST(pm.meta_value AS UNSIGNED) IN ({$placeholders}) GROUP BY CAST(pm.meta_value AS UNSIGNED)";
        $values       = array_merge( [ ATBDP_POST_TYPE, 'publish', '_directory_type' ], $directory_ids );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        $rows   = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
        $counts = [];

        foreach ( $rows as $row ) {
            $counts[ (int) $row['directory_id'] ] = (int) $row['listing_count'];
        }

        return $counts;
    }

    private function listing_metrics( $schema_healthy ) {
        global $wpdb;

        $cache_key = self::METRICS_CACHE_KEY . ( $schema_healthy ? '-healthy' : '-unavailable' );
        $found     = false;
        $metrics   = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

        if ( $found && is_array( $metrics ) && isset( $metrics['canonical'], $metrics['indexed'] ) ) {
            return [
                'canonical' => max( 0, (int) $metrics['canonical'] ),
                'indexed'   => max( 0, (int) $metrics['indexed'] ),
            ];
        }

        $canonical = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
                ATBDP_POST_TYPE,
                'publish'
            )
        );
        $indexed   = 0;

        if ( $schema_healthy ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $indexed = (int) $wpdb->get_var( 'SELECT COUNT(listing_id) FROM ' . Listing_Index_Schema::listing_table() . " WHERE post_status = 'publish'" );
        }

        $metrics = [ 'canonical' => max( 0, $canonical ), 'indexed' => max( 0, $indexed ) ];
        wp_cache_set( $cache_key, $metrics, self::CACHE_GROUP, MINUTE_IN_SECONDS );

        return $metrics;
    }

    private function flush_metrics() {
        wp_cache_delete( self::METRICS_CACHE_KEY . '-healthy', self::CACHE_GROUP );
        wp_cache_delete( self::METRICS_CACHE_KEY . '-unavailable', self::CACHE_GROUP );
    }

    private function directory_progress( array $state, $listings ) {
        if ( ! in_array( $state['status'], [ Listing_Index_Directory_State::STATUS_PENDING, Listing_Index_Directory_State::STATUS_BUILDING ], true ) ) {
            return 'ready' === $state['status'] ? 100 : 0;
        }

        $listings = max( 1, (int) $listings );
        $progress = min( 100, (int) floor( 100 * (int) $state['processed'] / $listings ) );

        return 'verify' === $state['phase'] ? 50 + (int) floor( $progress / 2 ) : (int) floor( $progress / 2 );
    }

    private function directory_state( array $state ) {
        if ( 0 < (int) $state['errors'] ) {
            return 'attention';
        }

        if ( in_array( $state['status'], [ Listing_Index_Directory_State::STATUS_PENDING, Listing_Index_Directory_State::STATUS_BUILDING ], true ) ) {
            return 'updating';
        }

        return Listing_Index_Directory_State::STATUS_READY === $state['status'] && 0 < (int) $state['active_generation'] ? 'active' : 'needs-update';
    }

    private function global_state( $schema_healthy, $data_current, $reads_active, $status ) {
        if ( ! $schema_healthy ) {
            return 'unavailable';
        }

        if ( Listing_Index_Schema::STATUS_BUILDING === $status ) {
            return 'updating';
        }

        if ( Listing_Index_Schema::STATUS_FAILED === $status ) {
            return 'attention';
        }

        if ( ! $data_current ) {
            return 'needs-update';
        }

        return $reads_active ? 'active' : 'inactive';
    }

    private function result( $success, $code ) {
        return [ 'success' => (bool) $success, 'code' => (string) $code ];
    }
}
