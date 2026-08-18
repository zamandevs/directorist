<?php
/**
 * Asynchronous listing-index maintenance tests.
 */

use Directorist\database\Listing_Index;
use Directorist\database\Listing_Index_Directory_State;
use Directorist\database\Listing_Index_Maintenance;
use Directorist\database\Listing_Index_Schema;
use Directorist\Listing_Index_Background_Process;

class Directorist_Listing_Index_Background_Process_Test extends WP_UnitTestCase {
    private $async_requests = [];

    public function set_up() {
        parent::set_up();

        global $wpdb;

        Listing_Index_Schema::create();
        Listing_Index::register_hooks();
        Listing_Index_Maintenance::register_hooks();

        foreach ( Listing_Index_Schema::field_tables() as $field_table ) {
            $wpdb->query( "DELETE FROM {$field_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        }
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::listing_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::state_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_READY );
        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, Listing_Index_Schema::DATA_VERSION, false );
        Listing_Index::set_enabled( true );
        delete_option( Listing_Index_Maintenance::LOCK_OPTION );
        delete_option( Listing_Index_Maintenance::RETRY_AFTER_OPTION );
        delete_option( Listing_Index_Schema::REPAIR_REQUIRED_OPTION );
        delete_option( Listing_Index::REBUILD_PHASE_OPTION );
        delete_option( Listing_Index::REBUILD_VERIFICATION_OPTION );
        wp_cache_flush();

        $this->async_requests = [];
        add_filter( 'pre_http_request', [ $this, 'capture_async_request' ], 10, 3 );

        if ( method_exists( Listing_Index_Maintenance::class, 'background_process' ) ) {
            Listing_Index_Maintenance::background_process()->reset();
        }
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', [ $this, 'capture_async_request' ], 10 );

        if ( method_exists( Listing_Index_Maintenance::class, 'background_process' ) ) {
            Listing_Index_Maintenance::background_process()->reset();
        }

        delete_option( Listing_Index_Maintenance::LOCK_OPTION );
        delete_option( Listing_Index_Maintenance::RETRY_AFTER_OPTION );
        delete_option( Listing_Index_Schema::REPAIR_REQUIRED_OPTION );
        parent::tear_down();
    }

    public function test_schedule_queues_one_cursor_job_and_dispatches_once() {
        $this->prepare_global_rebuild( 1 );

        Listing_Index_Maintenance::schedule_if_needed();
        Listing_Index_Maintenance::schedule_if_needed();

        $process = Listing_Index_Maintenance::background_process();

        $this->assertTrue( $process->has_queued_work() );
        $this->assertNotFalse( wp_next_scheduled( $process->get_cron_hook_identifier() ) );
        $this->assertCount( 1, $this->async_requests );
    }

    public function test_one_dispatch_completes_all_bounded_build_and_verification_phases() {
        $listing_ids = $this->prepare_global_rebuild( 25 );
        add_filter( 'directorist_listing_index_maintenance_batch_size', [ $this, 'ten_item_batch' ] );

        Listing_Index_Maintenance::schedule_if_needed();

        $process = new Directorist_Testable_Listing_Index_Background_Process();
        $process->run_worker();

        remove_filter( 'directorist_listing_index_maintenance_batch_size', [ $this, 'ten_item_batch' ] );

        $this->assertTrue( Listing_Index_Schema::is_ready() );
        $this->assertFalse( $process->has_queued_work() );
        $this->assertFalse( wp_next_scheduled( $process->get_cron_hook_identifier() ) );
        $this->assertCount( count( $listing_ids ), array_filter( array_map( [ Listing_Index::class, 'get_listing_row' ], $listing_ids ) ) );
    }

    public function test_time_limited_worker_retains_the_job_and_dispatches_a_successor() {
        $this->prepare_global_rebuild( 25 );
        add_filter( 'directorist_listing_index_maintenance_batch_size', [ $this, 'ten_item_batch' ] );

        Listing_Index_Maintenance::schedule_if_needed();

        $process = new Directorist_Testable_Listing_Index_Background_Process();
        add_filter( $process->get_identifier() . '_time_exceeded', '__return_true' );
        $process->run_worker();
        remove_filter( $process->get_identifier() . '_time_exceeded', '__return_true' );
        remove_filter( 'directorist_listing_index_maintenance_batch_size', [ $this, 'ten_item_batch' ] );

        $this->assertSame( Listing_Index_Schema::STATUS_BUILDING, Listing_Index_Schema::status() );
        $this->assertGreaterThan( 0, (int) get_option( 'directorist_listing_index_rebuild_cursor', 0 ) );
        $this->assertTrue( $process->has_queued_work() );
        $this->assertCount( 2, $this->async_requests );
    }

    public function test_cron_healthcheck_recovers_a_queued_job_without_another_dispatch() {
        $this->prepare_global_rebuild( 1 );
        Listing_Index_Maintenance::schedule_if_needed();

        $process = new Directorist_Testable_Listing_Index_Background_Process();
        $process->handle_cron_healthcheck();

        $this->assertTrue( Listing_Index_Schema::is_ready() );
        $this->assertFalse( $process->has_queued_work() );
        $this->assertFalse( wp_next_scheduled( $process->get_cron_hook_identifier() ) );
    }

    public function test_worker_repairs_missing_schema_and_rebuilds_derived_data() {
        $listing_ids = $this->prepare_global_rebuild( 1 );
        Listing_Index::rebuild_start();
        Listing_Index::rebuild_batch( 0, 10 );
        Listing_Index::rebuild_finish();

        $this->assertTrue( Listing_Index_Schema::is_ready() );

        $table      = Listing_Index_Schema::listing_table();
        $hide_table = static function( $sql ) use ( $table ) {
            if ( false !== strpos( $sql, 'information_schema.tables' ) ) {
                return str_replace( $table, $table . '_missing', $sql );
            }

            return $sql;
        };

        add_filter( 'query', $hide_table );
        Listing_Index_Schema::reset_request_cache();

        $this->assertFalse( Listing_Index::is_enabled() );
        $this->assertTrue( Listing_Index_Schema::repair_required() );
        remove_filter( 'query', $hide_table );

        $process = new Directorist_Testable_Listing_Index_Background_Process();
        $process->run_worker();

        $this->assertTrue( Listing_Index_Schema::tables_exist() );
        $this->assertFalse( Listing_Index_Schema::repair_required() );
        $this->assertTrue( Listing_Index_Schema::is_ready() );
        $this->assertFalse( $process->has_queued_work() );
        $this->assertNotEmpty( Listing_Index::get_listing_row( $listing_ids[0] ) );
    }

    public function capture_async_request( $preempt, $args, $url ) {
        unset( $args );

        if ( false === strpos( $url, 'directorist_listing_index' ) ) {
            return $preempt;
        }

        $this->async_requests[] = $url;

        return [
            'headers'  => [],
            'body'     => '',
            'response' => [
                'code'    => 202,
                'message' => 'Accepted',
            ],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    public function ten_item_batch() {
        return 10;
    }

    private function prepare_global_rebuild( $listing_count ) {
        $directory_id = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Background Directory',
            ]
        );

        update_term_meta(
            $directory_id,
            'search_form_fields',
            [
                'fields' => [
                    'select_1' => [
                        'widget_name'         => 'select',
                        'widget_key'          => 'select_1',
                        'original_widget_key' => 'select_1',
                    ],
                ],
            ]
        );
        update_term_meta(
            $directory_id,
            'submission_form_fields',
            [
                'fields' => [
                    'select_1' => [
                        'widget_name' => 'select',
                        'widget_key'  => 'select_1',
                        'field_key'   => 'custom-select-1',
                    ],
                ],
            ]
        );

        $listing_ids = [];

        for ( $index = 0; $index < $listing_count; ++$index ) {
            $listing_id = self::factory()->post->create(
                [
                    'post_type'   => ATBDP_POST_TYPE,
                    'post_status' => 'publish',
                    'post_title'  => 'Background Listing ' . $index,
                ]
            );

            update_post_meta( $listing_id, '_directory_type', $directory_id );
            update_post_meta( $listing_id, '_custom-select-1', 'value-' . $index );
            $listing_ids[] = $listing_id;
        }

        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, 'stale', false );
        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_NEEDS_REBUILD );

        return $listing_ids;
    }
}

class Directorist_Testable_Listing_Index_Background_Process extends Listing_Index_Background_Process {
    public function run_worker() {
        $this->handle();
    }
}
