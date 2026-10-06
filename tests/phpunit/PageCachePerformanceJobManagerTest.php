<?php
/**
 * Bounded Performance bulk-job behavior locks.
 */

use Directorist\Cache\Cache_Provider;
use Directorist\Cache\Performance_Event_Log;
use Directorist\Cache\Performance_Job_Manager;
use Directorist\Cache\Performance_Job_Ledger;
use Directorist\Cache\Performance_Operations;
use Directorist\Cache\Performance_Resource_Catalog;
use Directorist\Cache\Performance_Resource_Store;
use Directorist\Cache\Performance_Settings;

final class Directorist_Performance_Job_Test_Provider implements Cache_Provider {
    public $warmed = [];

    public $warm_calls = [];

    public $queued_items = [];

    public $purged = [];

    public $fail_on_call = 0;

    public $on_warm;

    public function get_id() {
        return 'directorist-cache'; }

    public function is_available() {
        return true; }

    public function get_capabilities() {
        return [ 'warm_urls', 'purge_url', 'purge_urls' ]; }

    public function supports( $capability ) {
        return in_array( $capability, $this->get_capabilities(), true ); }

    public function invalidate( array $request ) {
        $this->purged = array_merge( $this->purged, $request['urls'] );

        return [ 'success' => true, 'code' => 'invalidated', 'count' => count( $request['urls'] ) ]; }

    public function warm( array $urls ) {
        $this->warm_calls[] = $urls;

        if ( $this->fail_on_call === count( $this->warm_calls ) ) {
            return [ 'success' => false, 'code' => 'provider-failed' ];
        }

        $this->warmed = array_merge( $this->warmed, $urls );

        foreach ( $urls as $url ) {
            $this->queued_items[] = apply_filters(
                'directorist_page_cache_warm_queue_item',
                [ 'url' => $url, 'attempts' => 0 ],
                $url,
                $this
            );
        }

        if ( is_callable( $this->on_warm ) ) {
            call_user_func( $this->on_warm, $urls );
        }

        return [ 'success' => true, 'code' => 'queued', 'queued' => count( $urls ), 'accepted_urls' => $urls ]; }

    public function get_status() {
        return [ 'available' => true ]; }
}

final class Directorist_Page_Cache_Performance_Job_Manager_Test extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option( Performance_Job_Manager::OPTION_NAME );
        delete_option( Performance_Job_Manager::LOCK_OPTION );
        wp_clear_scheduled_hook( Performance_Job_Manager::MONITOR_HOOK );
        Performance_Job_Ledger::cleanup_all();
    }

    protected function tearDown(): void {
        delete_option( Performance_Job_Manager::OPTION_NAME );
        delete_option( Performance_Job_Manager::LOCK_OPTION );
        wp_clear_scheduled_hook( Performance_Job_Manager::MONITOR_HOOK );
        Performance_Job_Ledger::cleanup_all();
        parent::tearDown();
    }

    public function test_bulk_job_processes_canonical_resources_in_bounded_pages() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager( $provider, $dispatched, 61 );
        $started    = $manager->start( 'warm', [ 'type' => 'listing' ] );

        $this->assertTrue( $started['success'] );
        $this->assertSame( 'queued', $started['job']['state'] );
        $this->assertSame( 1, $dispatched );

        $first = $manager->process( $started['job']['id'] );
        $this->assertSame( 'running', $first['state'] );
        $this->assertSame( 50, $first['processed'] );

        $second = $manager->process( $started['job']['id'] );
        $this->assertSame( 'verifying', $second['state'] );
        $this->assertSame( 61, $second['processed'] );
        $this->assertCount( 61, $provider->warmed );
        $this->assertSame( $started['job']['id'], $provider->queued_items[0]['performance_job_id'] );
        $this->assertSame( 'Listing 1', $provider->queued_items[0]['performance_resource_title'] );
        $this->assertSame( 'listing', $provider->queued_items[0]['performance_resource_type'] );
        $this->assertSame( 'completed', $this->complete_warm_job( $manager, $started['job']['id'], $provider->warmed )['state'] );
    }

    public function test_filtered_purge_does_not_skip_resources_when_each_batch_leaves_the_result_set() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $urls       = [];

        for ( $id = 1; $id <= 61; ++$id ) {
            $urls[ $id ] = home_url( '/directory/listing-' . $id . '/' );
        }

        $catalog    = new Performance_Resource_Catalog(
            $provider,
            static function ( array $args ) use ( $provider, $urls ) {
                $remaining = array_values( array_diff( $urls, $provider->purged ) );
                $offset    = ( $args['page'] - 1 ) * $args['per_page'];
                $selected  = array_slice( $remaining, $offset, $args['per_page'], true );
                $items     = [];

                foreach ( $selected as $id => $url ) {
                    $items[] = [
                        'id'         => 'listing-' . $id,
                        'title'      => 'Listing ' . $id,
                        'url'        => $url,
                        'type'       => 'listing',
                        'route_type' => 'listing',
                        'object_id'  => $id,
                    ];
                }

                return [ 'items' => $items, 'total' => count( $remaining ) ];
            }
        );
        $settings   = new Performance_Settings();
        $operations = new Performance_Operations( $provider, $settings, new Performance_Event_Log( $settings ) );
        $manager    = new Performance_Job_Manager(
            $catalog,
            $operations,
            static function () use ( &$dispatched ) {
                ++$dispatched;

                return true;
            },
            static function () {
                return 1700000000;
            }
        );

        $started = $manager->start( 'purge', [ 'type' => 'listing', 'cache_state' => 'current' ] );
        $first   = $manager->process( $started['job']['id'] );
        $second  = $manager->process( $started['job']['id'] );

        $this->assertSame( 'running', $first['state'] );
        $this->assertSame( 11, $first['processed'] );
        $this->assertSame( 61, $first['total'] );
        $this->assertSame( 'completed', $second['state'] );
        $this->assertSame( 61, $second['processed'] );
        $this->assertCount( 61, array_unique( $provider->purged ) );
        $this->assertSame( [], array_diff( $urls, $provider->purged ) );
    }

    public function test_filtered_warm_does_not_skip_resources_when_queued_batches_leave_the_result_set() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $urls       = [];

        for ( $id = 1; $id <= 61; ++$id ) {
            $urls[ $id ] = home_url( '/directory/listing-' . $id . '/' );
        }

        $catalog    = new Performance_Resource_Catalog(
            $provider,
            static function ( array $args ) use ( $provider, $urls ) {
                $remaining = array_values( array_diff( $urls, $provider->warmed ) );
                $offset    = ( $args['page'] - 1 ) * $args['per_page'];
                $selected  = array_slice( $remaining, $offset, $args['per_page'], true );
                $items     = [];

                foreach ( $selected as $id => $url ) {
                    $items[] = [
                        'id'         => 'listing-' . $id,
                        'title'      => 'Listing ' . $id,
                        'url'        => $url,
                        'type'       => 'listing',
                        'route_type' => 'listing',
                        'object_id'  => $id,
                    ];
                }

                return [ 'items' => $items, 'total' => count( $remaining ) ];
            }
        );
        $settings   = new Performance_Settings();
        $operations = new Performance_Operations( $provider, $settings, new Performance_Event_Log( $settings ) );
        $manager    = new Performance_Job_Manager(
            $catalog,
            $operations,
            static function () use ( &$dispatched ) {
                ++$dispatched;

                return true;
            },
            static function () {
                return 1700000000;
            }
        );

        $started = $manager->start( 'warm', [ 'type' => 'listing', 'cache_state' => 'needs-refresh' ] );
        $first   = $manager->process( $started['job']['id'] );
        $second  = $manager->process( $started['job']['id'] );

        $this->assertSame( 'running', $first['state'] );
        $this->assertSame( 11, $first['processed'] );
        $this->assertSame( 61, $first['total'] );
        $this->assertSame( 'verifying', $second['state'] );
        $this->assertSame( 61, $second['processed'] );
        $this->assertCount( 61, array_unique( $provider->warmed ) );
        $this->assertSame( [], array_diff( $urls, $provider->warmed ) );
        $this->assertSame( 'completed', $this->complete_warm_job( $manager, $started['job']['id'], $provider->warmed )['state'] );
    }

    public function test_filtered_warm_completes_when_the_result_set_shrinks_between_preview_and_first_batch() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $queries    = 0;
        $catalog    = new Performance_Resource_Catalog(
            $provider,
            static function ( array $args ) use ( &$queries ) {
                ++$queries;
                $total  = 1 === $queries ? 24 : 23;
                $offset = ( $args['page'] - 1 ) * $args['per_page'];
                $count  = min( $args['per_page'], max( 0, $total - $offset ) );
                $items  = [];

                for ( $index = 0; $index < $count; ++$index ) {
                    $id      = $offset + $index + 1;
                    $items[] = [
                        'id'         => 'page-' . $id,
                        'title'      => 'Page ' . $id,
                        'url'        => home_url( '/page-' . $id . '/' ),
                        'type'       => 'page',
                        'route_type' => 'embedded',
                        'object_id'  => $id,
                    ];
                }

                return [ 'items' => $items, 'total' => $total ];
            }
        );
        $settings   = new Performance_Settings();
        $operations = new Performance_Operations( $provider, $settings, new Performance_Event_Log( $settings ) );
        $manager    = new Performance_Job_Manager(
            $catalog,
            $operations,
            static function () use ( &$dispatched ) {
                ++$dispatched;

                return true;
            }
        );

        $started   = $manager->start( 'warm', [ 'type' => 'page', 'cache_state' => 'needs-refresh' ] );
        $verifying = $manager->process( $started['job']['id'] );
        $completed = $this->complete_warm_job( $manager, $started['job']['id'], $provider->warmed );

        $this->assertSame( 24, $started['job']['total'] );
        $this->assertSame( 'verifying', $verifying['state'] );
        $this->assertSame( 23, $verifying['processed'] );
        $this->assertSame( 24, $verifying['total'] );
        $this->assertCount( 23, $provider->warmed );
        $this->assertSame( 'completed', $completed['state'] );
        $this->assertSame( 100, $completed['progress'] );
    }

    public function test_warm_result_does_not_end_discovery_before_later_batches_are_queued() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager( $provider, $dispatched, 61 );
        $started    = $manager->start( 'warm', [ 'type' => 'listing' ] );
        $first      = $manager->process( $started['job']['id'] );
        $recorded   = $manager->record_warm_result(
            $started['job']['id'],
            $provider->warmed[0],
            [ 'success' => true, 'code' => 'current' ]
        );
        $second     = $manager->process( $started['job']['id'] );

        $this->assertSame( 'running', $first['state'] );
        $this->assertSame( 'running', $recorded['state'] );
        $this->assertSame( 'verifying', $second['state'] );
        $this->assertSame( 61, $second['processed'] );
        $this->assertCount( 61, array_unique( $provider->warmed ) );
    }

    public function test_summary_recovers_a_legacy_verifying_job_after_all_queued_urls_are_terminal() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $cancelled  = 0;
        $manager    = $this->manager(
            $provider,
            $dispatched,
            1,
            static function () use ( &$cancelled ) {
                ++$cancelled;

                return true;
            }
        );
        $started    = $manager->start( 'warm', [ 'type' => 'page', 'cache_state' => 'needs-refresh' ] );
        $manager->process( $started['job']['id'] );
        $job                = get_option( Performance_Job_Manager::OPTION_NAME );
        $job['processed']   = 0;
        $job['total']       = 1;
        $job['warmed_urls'] = 0;
        $job['failed_urls'] = 1;
        $job['url_results'] = [ hash( 'sha256', $provider->warmed[0] ) => 'failed' ];
        $job['code']        = 'uncached';
        $job['state']       = 'verifying';
        unset( $job['discovery_complete'] );
        update_option( Performance_Job_Manager::OPTION_NAME, $job, false );

        $recovered = $manager->get_current();

        $this->assertSame( 'failed', $recovered['state'] );
        $this->assertSame( 'completed-with-failures', $recovered['code'] );
        $this->assertSame( 1, $recovered['failed'] );
        $this->assertArrayNotHasKey( 'url_results', get_option( Performance_Job_Manager::OPTION_NAME ) );
    }

    public function test_empty_filtered_scope_is_rejected_before_dispatch() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager( $provider, $dispatched, 0 );

        $result = $manager->start( 'warm', [ 'search' => 'no-matching-resource' ] );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'no-resources', $result['code'] );
        $this->assertSame( 0, $dispatched );
        $this->assertFalse( get_option( Performance_Job_Manager::OPTION_NAME, false ) );
    }

    public function test_empty_retry_clears_a_stale_failed_job() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager( $provider, $dispatched, 0 );
        update_option(
            Performance_Job_Manager::OPTION_NAME,
            [
                'id'        => wp_generate_uuid4(),
                'action'    => 'warm',
                'signature' => 'old-failure',
                'state'     => 'failed',
                'code'      => 'completed-with-failures',
            ],
            false
        );

        $result = $manager->start( 'warm', [ 'type' => 'listing', 'cache_state' => 'uncached' ] );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'no-resources', $result['code'] );
        $this->assertSame( 'idle', $manager->get_current()['state'] );
        $this->assertFalse( get_option( Performance_Job_Manager::OPTION_NAME, false ) );
    }

    public function test_inline_warm_results_are_merged_with_discovery_progress() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager( $provider, $dispatched, 1 );
        $started    = $manager->start( 'warm', [ 'type' => 'listing' ] );
        $job_id     = $started['job']['id'];

        $provider->on_warm = static function ( array $urls ) use ( $manager, $job_id ) {
            foreach ( $urls as $url ) {
                $manager->record_warm_result( $job_id, $url, [ 'success' => true, 'code' => 'current' ] );
            }
        };

        $completed = $manager->process( $job_id );

        $this->assertSame( 'completed', $completed['state'] );
        $this->assertSame( 1, $completed['processed'] );
        $this->assertSame( 1, $completed['queued'] );
        $this->assertSame( 1, $completed['warmed'] );
        $this->assertSame( 0, $completed['failed'] );
        $this->assertSame( 100, $completed['progress'] );
    }

    public function test_process_does_not_overwrite_a_cross_request_cancellation_cached_before_the_provider_call() {
        global $wpdb;

        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $cancelled  = 0;
        $manager    = $this->manager(
            $provider,
            $dispatched,
            1,
            static function () use ( &$cancelled ) {
                ++$cancelled;

                return true;
            }
        );

        $started = $manager->start( 'warm', [ 'type' => 'listing' ] );

        $provider->on_warm = static function () use ( $wpdb ) {
            $job               = get_option( Performance_Job_Manager::OPTION_NAME );
            $job['state']      = 'cancelled';
            $job['code']       = 'cancelled';
            $job['updated_at'] = 1700000001;

            // Model another PHP request committing to the database while this
            // request retains its earlier runtime-cache copy of the option.
            $wpdb->update(
                $wpdb->options,
                [ 'option_value' => maybe_serialize( $job ) ],
                [ 'option_name' => Performance_Job_Manager::OPTION_NAME ]
            );
        };

        $result = $manager->process( $started['job']['id'] );

        $this->assertSame( 'cancelled', $result['state'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertSame( 'cancelled', get_option( Performance_Job_Manager::OPTION_NAME )['state'] );
        $this->assertSame( 1, $cancelled );
    }

    public function test_listing_taxonomy_filters_are_part_of_the_stored_job_scope() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager( $provider, $dispatched, 2 );
        $started    = $manager->start(
            'warm',
            [
                'type'         => 'listing',
                'directory_id' => 9,
                'category_id'  => 13,
                'location_id'  => 27,
            ]
        );

        $this->assertSame( 9, $started['job']['scope']['directory_id'] );
        $this->assertSame( 13, $started['job']['scope']['category_id'] );
        $this->assertSame( 27, $started['job']['scope']['location_id'] );

        $conflict = $manager->start( 'warm', [ 'type' => 'listing', 'directory_id' => 9, 'category_id' => 14, 'location_id' => 27 ] );
        $this->assertSame( 'job-conflict', $conflict['code'] );
    }

    public function test_equivalent_active_jobs_are_deduplicated_and_cancellable() {
        $provider        = new Directorist_Performance_Job_Test_Provider();
        $dispatched      = 0;
        $cancelled_queue = 0;
        $manager         = $this->manager(
            $provider,
            $dispatched,
            2,
            static function () use ( &$cancelled_queue ) {
                ++$cancelled_queue;

                return true;
            }
        );

        $first     = $manager->start( 'warm', [ 'type' => 'all' ] );
        $duplicate = $manager->start( 'warm', [ 'type' => 'all' ] );

        $this->assertSame( 'already-running', $duplicate['code'] );
        $this->assertSame( $first['job']['id'], $duplicate['job']['id'] );
        $this->assertSame( 1, $dispatched );

        $cancelled = $manager->cancel( $first['job']['id'] );
        $this->assertTrue( $cancelled['success'] );
        $this->assertSame( 'cancelled', $cancelled['job']['state'] );
        $this->assertSame( 1, $cancelled_queue );
        $this->assertSame( 'cancelled', $manager->process( $first['job']['id'] )['state'] );
    }

    public function test_late_warm_result_cannot_overwrite_a_cancelled_job() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager( $provider, $dispatched, 1 );
        $started    = $manager->start( 'warm', [ 'type' => 'listing' ] );
        $queued     = $manager->process( $started['job']['id'] );

        $this->assertSame( 'verifying', $queued['state'] );
        $this->assertTrue( $manager->cancel( $started['job']['id'] )['success'] );

        $late = $manager->record_warm_result(
            $started['job']['id'],
            home_url( '/directory/listing-1/' ),
            [ 'success' => true, 'code' => 'current' ]
        );

        $this->assertSame( 'cancelled', $late['state'] );
        $this->assertSame( 0, $late['warmed'] );
        $this->assertSame( 'cancelled', $manager->get_current()['state'] );
    }

    public function test_verifying_warm_job_can_cancel_its_active_provider_queue() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager(
            $provider,
            $dispatched,
            1,
            static function () {
                return true;
            }
        );
        $started    = $manager->start( 'warm', [ 'type' => 'all' ] );

        $this->assertSame( 'verifying', $manager->process( $started['job']['id'] )['state'] );

        $cancelled = $manager->cancel( $started['job']['id'] );
        $this->assertTrue( $cancelled['success'] );
        $this->assertSame( 'cancelled', $cancelled['job']['state'] );
    }

    public function test_verifying_warm_job_without_an_active_queue_remains_active_until_verified() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager(
            $provider,
            $dispatched,
            1,
            static function () {
                return false;
            }
        );
        $started    = $manager->start( 'warm', [ 'type' => 'all' ] );

        $this->assertSame( 'verifying', $manager->process( $started['job']['id'] )['state'] );

        $cancelled = $manager->cancel( $started['job']['id'] );
        $this->assertTrue( $cancelled['success'] );
        $this->assertSame( 'cancelled', $cancelled['job']['state'] );
    }

    public function test_idle_verifying_job_requeues_urls_missing_terminal_results() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $now        = 1700000000;
        $recovered  = [];
        $manager    = $this->manager_with_recovery( $provider, $dispatched, $now, $recovered );
        $started    = $manager->start( 'warm', [ 'type' => 'listing' ] );
        $queued     = $manager->process( $started['job']['id'] );

        $this->assertSame( 'verifying', $queued['state'] );
        $now   += Performance_Job_Manager::STALL_GRACE + 1;
        $status = $manager->monitor( $started['job']['id'] );

        $this->assertSame( 'verifying', $status['state'] );
        $this->assertSame( 'recovering', $status['code'] );
        $this->assertSame( [ home_url( '/directory/listing-1/' ) ], $recovered );

        $completed = $manager->record_warm_result(
            $started['job']['id'],
            $recovered[0],
            [ 'success' => true, 'code' => 'current' ]
        );

        $this->assertSame( 'completed', $completed['state'] );
        $this->assertSame( 100, $completed['progress'] );
    }

    public function test_repeatedly_orphaned_urls_become_terminal_failures_instead_of_polling_forever() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $now        = 1700000000;
        $recovered  = [];
        $manager    = $this->manager_with_recovery( $provider, $dispatched, $now, $recovered );
        $started    = $manager->start( 'warm', [ 'type' => 'listing' ] );
        $manager->process( $started['job']['id'] );

        for ( $round = 0; $round < Performance_Job_Manager::MAX_STALL_RECOVERIES; ++$round ) {
            $now += Performance_Job_Manager::STALL_GRACE + 1;
            $this->assertSame( 'verifying', $manager->get_current()['state'] );
        }

        $now     += Performance_Job_Manager::STALL_GRACE + 1;
        $terminal = $manager->get_current();

        $this->assertSame( 'failed', $terminal['state'] );
        $this->assertSame( 'incomplete-warm-results', $terminal['code'] );
        $this->assertSame( 1, $terminal['failed'] );
        $this->assertCount( Performance_Job_Manager::MAX_STALL_RECOVERIES, $recovered );
    }

    public function test_legacy_orphaned_job_without_a_url_ledger_is_closed_as_a_failure() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $now        = 1700000100;
        $recovered  = [];
        $manager    = $this->manager_with_recovery( $provider, $dispatched, $now, $recovered );
        update_option(
            Performance_Job_Manager::OPTION_NAME,
            [
                'id'                 => wp_generate_uuid4(),
                'action'             => 'warm',
                'scope'              => [ 'type' => 'all' ],
                'signature'          => 'legacy',
                'state'              => 'verifying',
                'processed'          => 1,
                'discovered'         => 1,
                'queued_urls'        => 2,
                'warmed_urls'        => 1,
                'failed_urls'        => 0,
                'discovery_complete' => true,
                'url_results'        => [ hash( 'sha256', home_url( '/cached/' ) ) => 'warmed' ],
                'total'              => 1,
                'code'               => 'verifying',
                'created_at'         => $now - 100,
                'updated_at'         => $now - Performance_Job_Manager::STALL_GRACE - 1,
            ],
            false
        );

        $terminal = $manager->get_current();

        $this->assertSame( 'failed', $terminal['state'] );
        $this->assertSame( 'incomplete-warm-results', $terminal['code'] );
        $this->assertSame( 1, $terminal['failed'] );
        $this->assertSame( [], $recovered );

        $unchanged = $manager->get_current();
        $this->assertSame( 'failed', $unchanged['state'] );
        $this->assertSame( 'incomplete-warm-results', $unchanged['code'] );
    }

    public function test_signed_job_worker_uses_the_provider_selected_for_its_successor_request() {
        $provider = new Directorist_Performance_Job_Test_Provider();

        $this->assertTrue( function_exists( 'directorist_page_cache_register_performance_job_worker' ) );
        $this->assertTrue( directorist_page_cache_register_performance_job_worker( $provider ) );

        $worker     = directorist_page_cache_performance_job_process();
        $identifier = 'wp_' . get_current_blog_id() . '_' . Directorist\Cache\Performance_Job_Process::ACTION;

        $this->assertNotFalse( has_action( 'wp_ajax_' . $identifier, [ $worker, 'maybe_handle' ] ) );
        $this->assertNotFalse( has_action( 'wp_ajax_nopriv_' . $identifier, [ $worker, 'maybe_handle' ] ) );

        $resolver = new ReflectionProperty( $worker, 'manager' );
        $resolver->setAccessible( true );
        $manager = call_user_func( $resolver->getValue( $worker ) );
        $this->assertInstanceOf( Performance_Job_Manager::class, $manager );

        $operations = new ReflectionProperty( $manager, 'operations' );
        $operations->setAccessible( true );
        $operation_provider = new ReflectionProperty( $operations->getValue( $manager ), 'provider' );
        $operation_provider->setAccessible( true );
        $this->assertSame( $provider, $operation_provider->getValue( $operations->getValue( $manager ) ) );
    }

    public function test_expanded_resource_urls_are_processed_without_truncation() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager( $provider, $dispatched, 50 );

        add_filter( 'directorist_performance_cache_resource_urls', [ $this, 'expand_resource_urls' ], 10, 2 );

        try {
            $started = $manager->start( 'warm', [ 'type' => 'listing' ] );
            $result  = $manager->process( $started['job']['id'] );
        } finally {
            remove_filter( 'directorist_performance_cache_resource_urls', [ $this, 'expand_resource_urls' ], 10 );
        }

        $this->assertSame( 'verifying', $result['state'] );
        $this->assertSame( 50, $result['processed'] );
        $this->assertCount( 250, $provider->warmed );
        $this->assertCount( 5, $provider->warm_calls );

        foreach ( $provider->warm_calls as $call ) {
            $this->assertLessThanOrEqual( 50, count( $call ) );
        }

        $this->assertSame( 'completed', $this->complete_warm_job( $manager, $started['job']['id'], $provider->warmed )['state'] );
    }

    public function test_expanded_resource_chunk_failure_does_not_advance_progress() {
        $provider               = new Directorist_Performance_Job_Test_Provider();
        $provider->fail_on_call = 2;
        $dispatched             = 0;
        $manager                = $this->manager( $provider, $dispatched, 50 );

        add_filter( 'directorist_performance_cache_resource_urls', [ $this, 'expand_resource_urls' ], 10, 2 );

        try {
            $started = $manager->start( 'warm', [ 'type' => 'listing' ] );
            $result  = $manager->process( $started['job']['id'] );
        } finally {
            remove_filter( 'directorist_performance_cache_resource_urls', [ $this, 'expand_resource_urls' ], 10 );
        }

        $this->assertSame( 'running', $result['state'] );
        $this->assertSame( 'provider-failed', $result['code'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertCount( 50, $provider->warmed );
        $this->assertCount( 2, $provider->warm_calls );

        $provider->fail_on_call = 0;
        add_filter( 'directorist_performance_cache_resource_urls', [ $this, 'expand_resource_urls' ], 10, 2 );

        try {
            $retried = $manager->process( $started['job']['id'] );
        } finally {
            remove_filter( 'directorist_performance_cache_resource_urls', [ $this, 'expand_resource_urls' ], 10 );
        }

        $this->assertSame( 'verifying', $retried['state'] );
        $this->assertSame( 250, $retried['queued'] );
        $this->assertSame(
            'completed',
            $this->complete_warm_job( $manager, $started['job']['id'], array_values( array_unique( $provider->warmed ) ) )['state']
        );
    }

    public function test_warm_job_completes_only_after_each_queued_url_is_verified() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager( $provider, $dispatched, 1 );
        $started    = $manager->start( 'warm', [ 'type' => 'listing' ] );
        $url        = home_url( '/directory/listing-1/' );

        $queued = $manager->process( $started['job']['id'] );

        $this->assertSame( 'verifying', $queued['state'] );
        $this->assertSame( 1, $queued['discovered'] );
        $this->assertSame( 1, $queued['queued'] );
        $this->assertSame( 0, $queued['warmed'] );
        $this->assertLessThan( 100, $queued['progress'] );
        $this->assertNotFalse( wp_next_scheduled( Performance_Job_Manager::MONITOR_HOOK, [ $started['job']['id'] ] ) );

        $completed = $manager->record_warm_result(
            $started['job']['id'],
            $url,
            [ 'success' => true, 'code' => 'current' ]
        );

        $this->assertSame( 'completed', $completed['state'] );
        $this->assertSame( 1, $completed['warmed'] );
        $this->assertSame( 100, $completed['progress'] );
        $this->assertFalse( wp_next_scheduled( Performance_Job_Manager::MONITOR_HOOK, [ $started['job']['id'] ] ) );
        $this->assertArrayNotHasKey( 'url_results', get_option( Performance_Job_Manager::OPTION_NAME ) );

        $duplicate = $manager->record_warm_result( $started['job']['id'], $url, [ 'success' => true, 'code' => 'current' ] );
        $this->assertSame( 1, $duplicate['warmed'] );
    }

    public function test_terminal_warm_job_preserves_the_dominant_failure_reason_in_its_public_contract() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager( $provider, $dispatched, 3 );
        $started    = $manager->start( 'warm', [ 'type' => 'listing' ] );
        $manager->process( $started['job']['id'] );

        $manager->record_warm_result( $started['job']['id'], $provider->warmed[0], [ 'success' => false, 'code' => 'http-status-404', 'resource_title' => 'Missing listing 1', 'resource_type' => 'listing' ] );
        $manager->record_warm_result( $started['job']['id'], $provider->warmed[1], [ 'success' => false, 'code' => 'http-status-404', 'resource_title' => 'Missing listing 2', 'resource_type' => 'listing' ] );
        $completed = $manager->record_warm_result( $started['job']['id'], $provider->warmed[2], [ 'success' => false, 'code' => 'uncached', 'resource_title' => 'Uncached listing', 'resource_type' => 'listing' ] );

        $this->assertSame( 'failed', $completed['state'] );
        $this->assertSame( 'completed-with-failures', $completed['code'] );
        $this->assertSame( 'http-status-404', $completed['failure_code'] );
        $this->assertSame( 2, $completed['failure_count'] );
        $this->assertArrayNotHasKey( 'failure_codes', $completed );
        $this->assertSame( [ 'http-status-404' => 2, 'uncached' => 1 ], get_option( Performance_Job_Manager::OPTION_NAME )['failure_codes'] );
        $this->assertSame(
            [
                [ 'url' => $provider->warmed[0], 'title' => 'Missing listing 1', 'type' => 'listing', 'code' => 'http-status-404' ],
                [ 'url' => $provider->warmed[1], 'title' => 'Missing listing 2', 'type' => 'listing', 'code' => 'http-status-404' ],
                [ 'url' => $provider->warmed[2], 'title' => 'Uncached listing', 'type' => 'listing', 'code' => 'uncached' ],
            ],
            $completed['failure_items']
        );
        $this->assertSame( 0, $completed['failure_items_omitted'] );
        $this->assertArrayHasKey( 'failure_items', get_option( Performance_Job_Manager::OPTION_NAME ) );
        $this->assertArrayNotHasKey( 'url_results', get_option( Performance_Job_Manager::OPTION_NAME ) );
    }

    public function test_terminal_warm_job_bounds_public_failure_details() {
        $provider   = new Directorist_Performance_Job_Test_Provider();
        $dispatched = 0;
        $manager    = $this->manager( $provider, $dispatched, Performance_Job_Manager::MAX_FAILURE_ITEMS + 1 );
        $started    = $manager->start( 'warm', [ 'type' => 'listing' ] );

        while ( 'verifying' !== $manager->process( $started['job']['id'] )['state'] ) {
            // Process every bounded discovery page.
        }

        foreach ( $provider->warmed as $index => $url ) {
            $completed = $manager->record_warm_result(
                $started['job']['id'],
                $url,
                [ 'success' => false, 'code' => 'http-status-404', 'resource_title' => 'Missing listing ' . ( $index + 1 ), 'resource_type' => 'listing' ]
            );
        }

        $this->assertSame( 'failed', $completed['state'] );
        $this->assertSame( Performance_Job_Manager::MAX_FAILURE_ITEMS + 1, $completed['failed'] );
        $this->assertCount( Performance_Job_Manager::MAX_FAILURE_ITEMS, $completed['failure_items'] );
        $this->assertSame( 1, $completed['failure_items_omitted'] );
    }

    public function test_terminal_failure_notice_is_cleared_when_its_scope_has_no_public_failed_resources() {
        global $wpdb;

        $provider = new Directorist_Performance_Job_Test_Provider();
        $store    = new Performance_Resource_Store();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query( 'DROP TABLE IF EXISTS ' . $store->table_name() );
        $store->create();
        $store->begin_generation( 100 );
        $store->complete_generation( 100 );
        $catalog    = new Performance_Resource_Catalog( $provider, null, null, null, $store );
        $settings   = new Performance_Settings();
        $operations = new Performance_Operations( $provider, $settings, new Performance_Event_Log( $settings ) );
        $manager    = new Performance_Job_Manager( $catalog, $operations );
        $url        = home_url( '/?post_type=at_biz_dir&p=9999' );
        update_option(
            Performance_Job_Manager::OPTION_NAME,
            [
                'id'                 => wp_generate_uuid4(),
                'action'             => 'warm',
                'scope'              => [ 'type' => 'listing', 'cache_state' => 'failed' ],
                'state'              => 'failed',
                'code'               => 'completed-with-failures',
                'processed'          => 1,
                'discovered'         => 1,
                'queued_urls'        => 1,
                'warmed_urls'        => 0,
                'failed_urls'        => 1,
                'failure_codes'      => [ 'http-status-404' => 1 ],
                'failure_items'      => [ hash( 'sha256', $url ) => [ 'url' => $url, 'title' => 'Unpublished listing', 'type' => 'listing', 'code' => 'http-status-404' ] ],
                'discovery_complete' => true,
                'total'              => 1,
                'created_at'         => 1700000000,
                'updated_at'         => 1700000000,
            ],
            false
        );

        $current = $manager->get_current();

        $this->assertSame( 'completed', $current['state'] );
        $this->assertSame( 0, $current['failed'] );
        $this->assertSame( [], $current['failure_items'] );
        $this->assertSame( [], get_option( Performance_Job_Manager::OPTION_NAME )['failure_items'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query( 'DROP TABLE IF EXISTS ' . $store->table_name() );
        delete_option( Performance_Resource_Store::VERSION_OPTION );
        delete_option( Performance_Resource_Store::STATUS_OPTION );
    }

    public function expand_resource_urls( array $urls, array $resource ) {
        $expanded = [];

        for ( $page = 1; $page <= 5; ++$page ) {
            $expanded[] = add_query_arg( 'catalog_page', $page, $resource['url'] );
        }

        return $expanded;
    }

    private function complete_warm_job( Performance_Job_Manager $manager, $job_id, array $urls ) {
        $job = [];

        foreach ( $urls as $url ) {
            $job = $manager->record_warm_result( $job_id, $url, [ 'success' => true, 'code' => 'current' ] );
        }

        return $job;
    }

    private function manager( Directorist_Performance_Job_Test_Provider $provider, &$dispatched, $total, $warm_queue_canceller = null ) {
        $catalog    = new Performance_Resource_Catalog(
            $provider,
            static function ( array $args ) use ( $total ) {
                $offset = ( $args['page'] - 1 ) * $args['per_page'];
                $count  = min( $args['per_page'], max( 0, $total - $offset ) );
                $items  = [];

                for ( $index = 0; $index < $count; ++$index ) {
                    $id      = $offset + $index + 1;
                    $items[] = [
                        'id'         => 'listing-' . $id,
                        'title'      => 'Listing ' . $id,
                        'url'        => home_url( '/directory/listing-' . $id . '/' ),
                        'type'       => 'listing',
                        'route_type' => 'listing',
                        'object_id'  => $id,
                    ];
                }

                return [ 'items' => $items, 'total' => $total ];
            }
        );
        $settings   = new Performance_Settings();
        $operations = new Performance_Operations( $provider, $settings, new Performance_Event_Log( $settings ) );

        return new Performance_Job_Manager(
            $catalog,
            $operations,
            static function () use ( &$dispatched ) {
                ++$dispatched;

                return true;
            },
            static function () {
                return 1700000000;
            },
            $warm_queue_canceller
        );
    }

    private function manager_with_recovery( Directorist_Performance_Job_Test_Provider $provider, &$dispatched, &$now, array &$recovered ) {
        $catalog    = new Performance_Resource_Catalog(
            $provider,
            static function () {
                return [
                    'items' => [
                        [
                            'id'         => 'listing-1',
                            'title'      => 'Listing 1',
                            'url'        => home_url( '/directory/listing-1/' ),
                            'type'       => 'listing',
                            'route_type' => 'listing',
                            'object_id'  => 1,
                        ],
                    ],
                    'total' => 1,
                ];
            }
        );
        $settings   = new Performance_Settings();
        $operations = new Performance_Operations( $provider, $settings, new Performance_Event_Log( $settings ) );

        return new Performance_Job_Manager(
            $catalog,
            $operations,
            static function () use ( &$dispatched ) {
                ++$dispatched;

                return true;
            },
            static function () use ( &$now ) {
                return $now;
            },
            null,
            static function () {
                return [ 'queued' => 0, 'deferred' => 0, 'running' => false, 'recovery_at' => 0 ];
            },
            static function ( array $urls ) use ( &$recovered ) {
                $recovered = array_merge( $recovered, $urls );

                return [ 'success' => true, 'code' => 'recovered', 'queued' => count( $urls ), 'accepted_urls' => $urls ];
            },
            new Performance_Job_Ledger()
        );
    }
}
