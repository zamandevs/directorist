<?php
/**
 * User-facing Listing Index status and operation behavior locks.
 */

use Directorist\Cache\Performance_Listing_Index_Service;
use Directorist\database\Listing_Index;
use Directorist\database\Listing_Index_Directory_State;
use Directorist\database\Listing_Index_Lifecycle;
use Directorist\database\Listing_Index_Maintenance;
use Directorist\database\Listing_Index_Schema;

class Directorist_Performance_Listing_Index_Service_Test extends WP_UnitTestCase {
    private $directory_id;

    public function set_up() {
        parent::set_up();

        global $wpdb;

        Listing_Index_Schema::create();
        wp_cache_delete( Performance_Listing_Index_Service::METRICS_CACHE_KEY . '-healthy', Performance_Listing_Index_Service::CACHE_GROUP );
        wp_cache_delete( Performance_Listing_Index_Service::METRICS_CACHE_KEY . '-unavailable', Performance_Listing_Index_Service::CACHE_GROUP );
        Listing_Index_Maintenance::background_process()->reset();
        delete_option( Listing_Index_Maintenance::LOCK_OPTION );
        delete_option( Listing_Index_Maintenance::RETRY_AFTER_OPTION );

        foreach ( Listing_Index_Schema::field_tables() as $table ) {
            $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        }

        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::listing_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::state_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        $this->directory_id = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Restaurants',
            ]
        );
        $listing_id         = self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Indexed Restaurant',
            ]
        );

        update_post_meta( $listing_id, '_directory_type', $this->directory_id );
        Listing_Index_Directory_State::activate_current_configuration( $this->directory_id );
        Listing_Index::sync_listing( $listing_id );
        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_READY );
        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, Listing_Index_Schema::DATA_VERSION, false );
        Listing_Index_Lifecycle::trust_current_deployment();
        Listing_Index::set_enabled( true );
    }

    public function tear_down() {
        Listing_Index_Maintenance::background_process()->reset();
        delete_option( Listing_Index_Maintenance::LOCK_OPTION );
        delete_option( Listing_Index_Maintenance::RETRY_AFTER_OPTION );
        parent::tear_down();
    }

    public function test_summary_distinguishes_schema_data_and_read_path_state() {
        $summary = ( new Performance_Listing_Index_Service() )->get_summary();

        $this->assertSame( 'active', $summary['state'] );
        $this->assertTrue( $summary['schema_healthy'] );
        $this->assertTrue( $summary['data_current'] );
        $this->assertTrue( $summary['reads_active'] );
        $this->assertSame( 1, $summary['canonical_listings'] );
        $this->assertSame( 1, $summary['indexed_listings'] );

        delete_option( Listing_Index_Schema::DATA_VERSION_OPTION );
        $summary = ( new Performance_Listing_Index_Service() )->get_summary();

        $this->assertSame( 'needs-update', $summary['state'] );
        $this->assertFalse( $summary['data_current'] );
        $this->assertFalse( $summary['reads_active'] );
    }

    public function test_directory_table_reports_plain_language_progress_inputs() {
        $result = ( new Performance_Listing_Index_Service() )->get_directories( [ 'page' => 1, 'per_page' => 20, 'search' => 'Restaurants' ] );

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( $this->directory_id, $result['items'][0]['id'] );
        $this->assertSame( 'Restaurants', $result['items'][0]['name'] );
        $this->assertSame( 1, $result['items'][0]['listings'] );
        $this->assertSame( 'active', $result['items'][0]['state'] );
        $this->assertArrayHasKey( 'filter_fields', $result['items'][0] );
        $this->assertArrayNotHasKey( 'indexed_fields', $result['items'][0] );
        $this->assertArrayHasKey( 'updated_at', $result['items'][0] );
        $this->assertArrayNotHasKey( 'active_generation', $result['items'][0] );
        $this->assertArrayNotHasKey( 'cursor', $result['items'][0] );
    }

    public function test_forced_directory_regeneration_queues_a_new_generation_even_when_configuration_is_unchanged() {
        $before = Listing_Index_Directory_State::get( $this->directory_id, true );
        $result = ( new Performance_Listing_Index_Service() )->regenerate_directory( $this->directory_id );
        $after  = Listing_Index_Directory_State::get( $this->directory_id, true );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'regeneration-queued', $result['code'] );
        $this->assertSame( Listing_Index_Directory_State::STATUS_PENDING, $after['status'] );
        $this->assertGreaterThan( $before['active_generation'], $after['pending_generation'] );
        $this->assertTrue( Listing_Index_Maintenance::has_pending_work() );
    }

    public function test_reads_cannot_be_enabled_until_index_data_is_current() {
        delete_option( Listing_Index_Schema::DATA_VERSION_OPTION );
        $service = new Performance_Listing_Index_Service();
        $result  = $service->set_reads_enabled( true );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'index-not-ready', $result['code'] );

        $disabled = $service->set_reads_enabled( false );
        $this->assertTrue( $disabled['success'] );
        $this->assertFalse( Listing_Index::is_enabled() );
    }

    public function test_summary_reuses_bounded_count_metrics_but_regeneration_flushes_them() {
        global $wpdb;

        $service = new Performance_Listing_Index_Service();
        $service->get_summary();
        $before = $wpdb->num_queries;
        $second = $service->get_summary();
        $count  = $wpdb->num_queries - $before;

        $this->assertSame( 1, $second['canonical_listings'] );
        $this->assertLessThan( 2, $count, 'Cached summary counts should not repeat both aggregate SQL queries.' );

        $service->regenerate_directory( $this->directory_id );
        $this->assertFalse( wp_cache_get( Performance_Listing_Index_Service::METRICS_CACHE_KEY . '-healthy', Performance_Listing_Index_Service::CACHE_GROUP ) );
    }
}
