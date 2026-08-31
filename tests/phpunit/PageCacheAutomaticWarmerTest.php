<?php
/**
 * Automatic post-invalidation and provider-neutral warming behavior locks.
 */

use Directorist\Cache\Automatic_Warmer;
use Directorist\Cache\Cache_Provider;
use Directorist\Cache\Performance_Event_Log;
use Directorist\Cache\Warm_Background_Process;

final class Directorist_Page_Cache_Automatic_Warm_Test_Provider implements Cache_Provider {
    public $calls = [];

    private $supports_warm;

    public function __construct( $supports_warm = true ) {
        $this->supports_warm = $supports_warm;
    }

    public function get_id() {
        return 'automatic-warm-test';
    }

    public function is_available() {
        return true;
    }

    public function get_capabilities() {
        return $this->supports_warm ? [ 'purge_site', 'warm_urls' ] : [ 'purge_site' ];
    }

    public function supports( $capability ) {
        return in_array( $capability, $this->get_capabilities(), true );
    }

    public function invalidate( array $request ) {
        unset( $request );

        return [ 'success' => true, 'code' => 'purged' ];
    }

    public function warm( array $urls ) {
        $this->calls[] = $urls;

        return [ 'success' => true, 'code' => 'queued', 'queued' => count( $urls ) ];
    }

    public function get_status() {
        return [ 'available' => true ];
    }
}

class Directorist_Page_Cache_Automatic_Warmer_Test extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option( Performance_Event_Log::OPTION_NAME );
    }

    protected function tearDown(): void {
        delete_option( Performance_Event_Log::OPTION_NAME );
        parent::tearDown();
    }

    public function test_successful_exact_invalidation_queues_deduplicated_public_urls() {
        $provider = new Directorist_Page_Cache_Automatic_Warm_Test_Provider();
        $warmer   = new Automatic_Warmer(
            $provider,
            static function () {
                return [];
            }
        );
        $url      = home_url( '/directory/listing/' );

        $result = $warmer->after_invalidation(
            [ 'success' => true ],
            [
                'urls'         => [ $url, $url, 'https://foreign.test/listing/' ],
                'generations'  => [],
                'conservative' => false,
            ]
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( [ [ $url ] ], $provider->calls );
    }

    public function test_collection_invalidation_adds_only_bounded_configured_routes() {
        $provider = new Directorist_Page_Cache_Automatic_Warm_Test_Provider();
        $limits   = [];
        $warmer   = new Automatic_Warmer(
            $provider,
            static function ( $args ) use ( &$limits ) {
                $limits[] = $args;

                return [ home_url( '/all-listings/' ), home_url( '/search/' ) ];
            }
        );

        $warmer->after_invalidation(
            [ 'success' => true ],
            [
                'urls'         => [],
                'generations'  => [ 'directorist:1:collection:listings' ],
                'conservative' => false,
            ]
        );

        $this->assertSame( [ [ 'listing_limit' => 0, 'term_limit' => 0, 'page_limit' => 1 ] ], $limits );
        $this->assertSame( 2, count( $provider->calls[0] ) );
    }

    public function test_conservative_invalidation_uses_small_discovery_bounds() {
        $provider = new Directorist_Page_Cache_Automatic_Warm_Test_Provider();
        $limits   = [];
        $warmer   = new Automatic_Warmer(
            $provider,
            static function ( $args ) use ( &$limits ) {
                $limits = $args;

                return [];
            }
        );

        $result = $warmer->after_invalidation(
            [ 'success' => true ],
            [ 'urls' => [], 'generations' => [], 'conservative' => true ]
        );

        $this->assertSame( [ 'listing_limit' => 5, 'term_limit' => 10, 'page_limit' => 2 ], $limits );
        $this->assertSame( 'no_urls', $result['code'] );
    }

    public function test_failed_invalidation_or_provider_without_warm_capability_does_nothing() {
        $provider = new Directorist_Page_Cache_Automatic_Warm_Test_Provider( false );
        $warmer   = new Automatic_Warmer( $provider );

        $failed      = $warmer->after_invalidation( [ 'success' => false ], [ 'urls' => [ home_url( '/' ) ] ] );
        $unsupported = $warmer->after_invalidation( [ 'success' => true ], [ 'urls' => [ home_url( '/' ) ] ] );

        $this->assertSame( 'invalidation_failed', $failed['code'] );
        $this->assertSame( 'warm_unsupported', $unsupported['code'] );
        $this->assertSame( [], $provider->calls );
    }

    public function test_mutation_during_an_internal_warm_request_does_not_queue_itself_again() {
        $provider                                 = new Directorist_Page_Cache_Automatic_Warm_Test_Provider();
        $warmer                                   = new Automatic_Warmer( $provider );
        $_SERVER['HTTP_X_DIRECTORIST_CACHE_WARM'] = '1';

        try {
            $result = $warmer->after_invalidation( [ 'success' => true ], [ 'urls' => [ home_url( '/directory/listing/' ) ] ] );
        } finally {
            unset( $_SERVER['HTTP_X_DIRECTORIST_CACHE_WARM'] );
        }

        $this->assertSame( 'mutation_during_warm', $result['code'] );
        $this->assertSame( [], $provider->calls );
    }

    public function test_worker_reports_terminal_cache_verification_failure_to_the_performance_job() {
        $reported = [];
        $listener = static function ( $job_id, $url, $result ) use ( &$reported ) {
            $reported = compact( 'job_id', 'url', 'result' );
        };
        add_action( 'directorist_page_cache_performance_warm_result', $listener, 10, 3 );
        $worker = new Warm_Background_Process(
            [
                'requester' => static function () {
                    return [ 'response' => [ 'code' => 200 ] ];
                },
                'verifier'  => static function () {
                    return [ 'success' => false, 'code' => 'mutation-during-warm' ];
                },
            ]
        );
        $worker->reset();
        $url = home_url( '/directory/listing/' );

        try {
            $result = $worker->process_item( [ 'url' => $url, 'attempts' => 3, 'performance_job_id' => 'job-1' ] );
        } finally {
            remove_action( 'directorist_page_cache_performance_warm_result', $listener, 10 );
            $worker->reset();
        }

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'job-1', $reported['job_id'] );
        $this->assertSame( 'mutation-during-warm', $reported['result']['code'] );
    }

    public function test_worker_sends_the_signed_force_refresh_header_when_available() {
        $request_headers = [];
        $worker          = new Warm_Background_Process(
            [
                'requester'     => static function ( $url, array $args ) use ( &$request_headers ) {
                    unset( $url );
                    $request_headers = $args['headers'];

                    return [ 'response' => [ 'code' => 200 ] ];
                },
                'verifier'      => static function () {
                    return [ 'success' => true, 'code' => 'current' ];
                },
                'refresh_token' => static function () {
                    return 'signed-refresh-token';
                },
                'clock'         => static function () {
                    return 1000;
                },
            ]
        );
        $worker->reset();

        try {
            $result = $worker->process_item( [ 'url' => home_url( '/refresh/' ), 'attempts' => 0 ] );
        } finally {
            $worker->reset();
        }

        $this->assertTrue( $result['success'] );
        $this->assertSame( '1', $request_headers['X-Directorist-Cache-Warm'] );
        $refresh_key = ( new \Directorist\Cache\Built_In\Request_Key() )->from_url( home_url( '/refresh/' ) );
        $this->assertSame( '1000', $request_headers['X-Directorist-Cache-Refresh-Time'] );
        $this->assertSame( $refresh_key['hash'], $request_headers['X-Directorist-Cache-Refresh-Key'] );
        $this->assertSame(
            hash_hmac( 'sha256', "1000\n" . home_url( '/refresh/' ) . "\n" . $refresh_key['hash'], 'signed-refresh-token' ),
            $request_headers['X-Directorist-Cache-Refresh']
        );
    }

    public function test_worker_sends_validated_language_variation_as_anonymous_refresh_cookies() {
        $request = [];
        $worker  = new Warm_Background_Process(
            [
                'requester'     => static function ( $url, array $args ) use ( &$request ) {
                    $request = compact( 'url', 'args' );

                    return [ 'response' => [ 'code' => 200 ] ];
                },
                'verifier'      => static function () { return [ 'success' => true, 'code' => 'current' ]; },
                'refresh_token' => static function () { return 'signed-refresh-token'; },
                'clock'         => static function () { return 1000; },
            ]
        );
        $worker->reset();
        $url       = home_url( '/same-url/' );
        $variation = [ 'wp-wpml_current_language' => 'sv' ];
        $key       = ( new \Directorist\Cache\Built_In\Request_Key() )->from_url( $url, $variation );

        try {
            $result = $worker->process_item( [ 'url' => $url, 'attempts' => 0, 'variation' => $variation, 'cache_hash' => $key['hash'] ] );
        } finally {
            $worker->reset();
        }

        $this->assertTrue( $result['success'] );
        $this->assertSame( $key['hash'], $request['args']['headers']['X-Directorist-Cache-Refresh-Key'] );
        $this->assertCount( 1, $request['args']['cookies'] );
        $this->assertSame( 'wp-wpml_current_language', $request['args']['cookies'][0]->name );
        $this->assertSame( 'sv', $request['args']['cookies'][0]->value );
    }

    public function test_exact_language_variants_queue_independently_and_each_variant_is_deduplicated() {
        $dispatches = 0;
        $worker     = new Warm_Background_Process(
            [
                'dispatcher' => static function () use ( &$dispatches ) {
                    ++$dispatches;

                    return true;
                },
            ]
        );
        $worker->reset();
        $url    = home_url( '/same-url/' );
        $en     = [ 'pll_language' => 'en' ];
        $sv     = [ 'pll_language' => 'sv' ];
        $en_key = ( new \Directorist\Cache\Built_In\Request_Key() )->from_url( $url, $en );
        $sv_key = ( new \Directorist\Cache\Built_In\Request_Key() )->from_url( $url, $sv );

        try {
            $first     = $worker->enqueue_refresh( $url, $en, $en_key['hash'] );
            $second    = $worker->enqueue_refresh( $url, $sv, $sv_key['hash'] );
            $duplicate = $worker->enqueue_refresh( $url, $en, $en_key['hash'] );
        } finally {
            $worker->reset();
        }

        $this->assertSame( 'queued', $first['code'] );
        $this->assertSame( 'queued', $second['code'] );
        $this->assertSame( 'no_urls', $duplicate['code'] );
        $this->assertSame( 2, $dispatches );
    }

    public function test_successful_but_uncacheable_responses_are_terminal_without_opening_the_circuit() {
        $reported = [];
        $listener = static function ( $job_id, $url, $result ) use ( &$reported ) {
            $reported[] = compact( 'job_id', 'url', 'result' );
        };
        add_action( 'directorist_page_cache_performance_warm_result', $listener, 10, 3 );
        $worker = new Warm_Background_Process(
            [
                'requester' => static function () {
                    return [ 'response' => [ 'code' => 200 ] ];
                },
                'verifier'  => static function () {
                    return [ 'success' => false, 'code' => 'uncached' ];
                },
            ]
        );
        $worker->reset();
        $results = [];

        try {
            for ( $index = 1; $index <= 4; ++$index ) {
                $results[] = $worker->process_item(
                    [
                        'url'                => home_url( '/private-page-' . $index . '/' ),
                        'attempts'           => 0,
                        'performance_job_id' => 'job-1',
                    ]
                );
            }

            $status = $worker->status();
        } finally {
            remove_action( 'directorist_page_cache_performance_warm_result', $listener, 10 );
            $worker->reset();
        }

        $this->assertSame( [ 'uncached', 'uncached', 'uncached', 'uncached' ], wp_list_pluck( $results, 'code' ) );
        $this->assertSame( 4, count( $reported ) );
        $this->assertSame( 0, $status['deferred'] );
        $this->assertFalse( $status['circuit_open'] );
    }

    public function test_non_retryable_client_error_is_reported_once_without_opening_the_circuit() {
        $reported = [];
        $listener = static function ( $job_id, $url, $result ) use ( &$reported ) {
            $reported[] = compact( 'job_id', 'url', 'result' );
        };
        add_action( 'directorist_page_cache_performance_warm_result', $listener, 10, 3 );
        $worker = new Warm_Background_Process(
            [
                'requester' => static function () {
                    return [ 'response' => [ 'code' => 404 ] ];
                },
            ]
        );
        $worker->reset();
        $url = home_url( '/missing-translation/' );

        try {
            $result = $worker->process_item(
                [
                    'url'                        => $url,
                    'attempts'                   => 0,
                    'performance_job_id'         => 'job-404',
                    'performance_resource_title' => 'Missing translation',
                    'performance_resource_type'  => 'listing',
                ]
            );
            $status = $worker->status();
        } finally {
            remove_action( 'directorist_page_cache_performance_warm_result', $listener, 10 );
            $worker->reset();
        }

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'http-status-404', $result['code'] );
        $this->assertCount( 1, $reported );
        $this->assertSame( 'http-status-404', $reported[0]['result']['code'] );
        $this->assertSame( 'Missing translation', $reported[0]['result']['resource_title'] );
        $this->assertSame( 'listing', $reported[0]['result']['resource_type'] );
        $this->assertSame( 0, $status['deferred'] );
        $this->assertFalse( $status['circuit_open'] );
    }

    public function test_terminal_result_is_reported_for_a_selected_warm_without_a_bulk_job_id() {
        $reported = [];
        $listener = static function ( $url, $result ) use ( &$reported ) {
            $reported[] = compact( 'url', 'result' );
        };
        add_action( 'directorist_page_cache_warm_result', $listener, 10, 2 );
        $worker = new Warm_Background_Process(
            [
                'requester' => static function () {
                    return [ 'response' => [ 'code' => 404 ] ];
                },
            ]
        );
        $worker->reset();
        $url = home_url( '/missing-selected-listing/' );

        try {
            $worker->process_item( [ 'url' => $url, 'attempts' => 0 ] );
        } finally {
            remove_action( 'directorist_page_cache_warm_result', $listener, 10 );
            $worker->reset();
        }

        $this->assertCount( 1, $reported );
        $this->assertSame( $url, $reported[0]['url'] );
        $this->assertSame( 'http-status-404', $reported[0]['result']['code'] );
    }

    public function test_provider_neutral_worker_filters_urls_and_dispatches_anonymous_queue() {
        $requests   = [];
        $dispatches = 0;
        $worker     = new Warm_Background_Process(
            [
                'requester'  => static function ( $url, $args ) use ( &$requests ) {
                    $requests[] = [ $url, $args ];

                    return [ 'response' => [ 'code' => 200 ] ];
                },
                'dispatcher' => static function () use ( &$dispatches ) {
                    ++$dispatches;

                    return true;
                },
            ]
        );
        $worker->reset();
        $url = home_url( '/directory/listing/' );

        $queued = $worker->enqueue( [ $url, $url, home_url( '/wp-admin/' ), 'https://foreign.test/' ] );
        $status = $worker->status();
        $result = $worker->process_item( [ 'url' => $url, 'attempts' => 0 ] );

        $this->assertSame( 1, $queued['queued'] );
        $this->assertSame( 1, $dispatches );
        $this->assertSame( 1, $status['queued'] );
        $this->assertFalse( $status['circuit_open'] );
        $this->assertTrue( $result['success'] );
        $this->assertSame( [], $requests[0][1]['cookies'] );
        $this->assertSame( '1', $requests[0][1]['headers']['X-Directorist-Cache-Warm'] );
        $worker->reset();
    }

    public function test_worker_coalesces_repeated_warm_urls_across_nearby_mutations() {
        $dispatches = 0;
        $worker     = new Warm_Background_Process(
            [
                'dispatcher' => static function () use ( &$dispatches ) {
                    ++$dispatches;

                    return true;
                },
                'clock'      => static function () {
                    return 1000;
                },
            ]
        );
        $worker->reset();
        $url = home_url( '/directory/listing/' );

        $first  = $worker->enqueue( [ $url ] );
        $second = $worker->enqueue( [ $url ] );

        $this->assertSame( 1, $first['queued'] );
        $this->assertSame( 0, $second['queued'] );
        $this->assertSame( 1, $dispatches );
        $worker->reset();
    }

    public function test_worker_does_not_resave_prior_items_across_request_scoped_chunks() {
        global $wpdb;

        $worker = new Warm_Background_Process(
            [
                'dispatcher' => static function () {
                    return true;
                },
            ]
        );
        $worker->reset();

        $worker->enqueue( [ home_url( '/directory/chunk-one/' ) ] );
        $worker->enqueue( [ home_url( '/directory/chunk-two/' ) ] );

        $identifier   = 'wp_' . get_current_blog_id() . '_' . Warm_Background_Process::ACTION;
        $table        = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
        $name_column  = is_multisite() ? 'meta_key' : 'option_name';
        $value_column = is_multisite() ? 'meta_value' : 'option_value';
        $id_column    = is_multisite() ? 'meta_id' : 'option_id';
        $values       = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT {$value_column} FROM {$table} WHERE {$name_column} LIKE %s ORDER BY {$id_column} ASC",
                $wpdb->esc_like( $identifier . '_batch_' ) . '%'
            )
        );
        $sizes        = array_map(
            static function ( $value ) {
                return count( (array) maybe_unserialize( $value ) );
            },
            $values
        );

        $this->assertSame( [ 1, 1 ], $sizes );
        $worker->reset();
    }

    public function test_worker_cancel_invalidates_in_flight_items_without_blocking_future_warms() {
        $requests = 0;
        $worker   = new Warm_Background_Process(
            [
                'requester'  => static function () use ( &$requests ) {
                    ++$requests;

                    return [ 'response' => [ 'code' => 200 ] ];
                },
                'dispatcher' => static function () {
                    return true;
                },
            ]
        );
        $worker->reset();
        $url        = home_url( '/directory/listing/' );
        $identifier = 'wp_' . get_current_blog_id() . '_' . Warm_Background_Process::ACTION;
        $generation = (int) get_site_option( $identifier . '_generation', 0 );

        $this->assertSame( 1, $worker->enqueue( [ $url ] )['queued'] );
        $this->assertSame( 'cancelled', $worker->cancel()['code'] );

        $stale = $worker->process_item( [ 'url' => $url, 'attempts' => 0, 'generation' => $generation ] );
        $fresh = $worker->process_item( [ 'url' => $url, 'attempts' => 0, 'generation' => $generation + 1 ] );

        $this->assertSame( 'cancelled', $stale['code'] );
        $this->assertTrue( $fresh['success'] );
        $this->assertSame( 1, $requests );
        $worker->reset();
    }

    public function test_cancellation_during_http_does_not_restore_claims_or_report_a_result() {
        $recorded = 0;
        $worker   = null;
        $worker   = new Warm_Background_Process(
            [
                'requester'  => static function () use ( &$worker ) {
                    $worker->cancel();

                    return [ 'response' => [ 'code' => 200 ] ];
                },
                'dispatcher' => static function () {
                    return true;
                },
            ]
        );
        $worker->reset();
        $url        = home_url( '/directory/in-flight-cancel/' );
        $identifier = 'wp_' . get_current_blog_id() . '_' . Warm_Background_Process::ACTION;
        $generation = (int) get_site_option( $identifier . '_generation', 0 );
        $callback   = static function () use ( &$recorded ) {
            ++$recorded;
        };
        add_action( 'directorist_page_cache_performance_warm_result', $callback, 10, 3 );

        try {
            $worker->enqueue( [ $url ] );
            $result = $worker->process_item(
                [
                    'url'                => $url,
                    'attempts'           => 0,
                    'generation'         => $generation,
                    'performance_job_id' => 'cancelled-job',
                ]
            );
        } finally {
            remove_action( 'directorist_page_cache_performance_warm_result', $callback, 10 );
        }

        $this->assertSame( 'cancelled', $result['code'] );
        $this->assertSame( 0, $recorded );
        $this->assertSame( 0, $worker->status()['queued'] );
        $worker->reset();
    }

    public function test_cancelled_generation_cannot_resave_the_remainder_of_an_in_flight_batch() {
        $worker = new Warm_Background_Process(
            [
                'dispatcher' => static function () {
                    return true;
                },
            ]
        );
        $worker->reset();
        $identifier = 'wp_' . get_current_blog_id() . '_' . Warm_Background_Process::ACTION;
        $generation = (int) get_site_option( $identifier . '_generation', 0 );
        $batch_key  = $identifier . '_batch_cancelled_generation';
        $worker->cancel();
        $worker->update(
            $batch_key,
            [ [ 'url' => home_url( '/directory/stale/' ), 'generation' => $generation ] ]
        );

        $this->assertFalse( get_site_option( $batch_key, false ) );
        $this->assertSame( 0, $worker->status()['queued'] );
        $worker->reset();
    }

    public function test_expiring_dedupe_claims_without_batches_are_not_reported_as_queued_work() {
        $worker = new Warm_Background_Process(
            [
                'dispatcher' => static function () {
                    return true;
                },
            ]
        );
        $worker->reset();
        $identifier = 'wp_' . get_current_blog_id() . '_' . Warm_Background_Process::ACTION;
        set_transient( $identifier . '_dedupe', [ 'orphaned-claim' => time() + 60 ], 60 );

        $this->assertSame( 0, $worker->status()['queued'] );
        $worker->reset();
    }

    public function test_successful_warm_releases_dedupe_claim_for_a_later_mutation() {
        $dispatches = 0;
        $worker     = new Warm_Background_Process(
            [
                'requester'  => static function () {
                    return [ 'response' => [ 'code' => 200 ] ];
                },
                'dispatcher' => static function () use ( &$dispatches ) {
                    ++$dispatches;

                    return true;
                },
                'clock'      => static function () {
                    return 1000;
                },
            ]
        );
        $worker->reset();
        $url = home_url( '/directory/listing/' );

        $first     = $worker->enqueue( [ $url ] );
        $duplicate = $worker->enqueue( [ $url ] );
        $warmed    = $worker->process_item( [ 'url' => $url, 'attempts' => 0 ] );
        $next      = $worker->enqueue( [ $url ] );

        $this->assertSame( 1, $first['queued'] );
        $this->assertSame( 0, $duplicate['queued'] );
        $this->assertTrue( $warmed['success'] );
        $this->assertSame( 1, $next['queued'] );
        $this->assertSame( 2, $dispatches );
        $worker->reset();
    }

    public function test_worker_failure_schedules_bounded_retry_and_opens_circuit() {
        $scheduled      = [];
        $schedule_calls = [];
        $now            = 1000;
        $worker         = new Warm_Background_Process(
            [
                'requester'   => static function () {
                    return [ 'response' => [ 'code' => 500 ] ];
                },
                'scheduler'   => static function ( $timestamp, $hook, $args ) use ( &$scheduled, &$schedule_calls ) {
                    $scheduled[ $hook ] = [ $timestamp, $args ];
                    $schedule_calls[]   = [ $timestamp, $hook, $args ];

                    return true;
                },
                'unscheduler' => static function ( $hook ) use ( &$scheduled ) {
                    unset( $scheduled[ $hook ] );

                    return true;
                },
                'clock'       => static function () use ( &$now ) {
                    return $now;
                },
            ]
        );
        $worker->reset();

        for ( $attempt = 0; $attempt < 4; ++$attempt ) {
            $result = $worker->process_item(
                [
                    'url'      => home_url( '/directory/listing-' . $attempt . '/' ),
                    'attempts' => 0,
                ]
            );
        }

        $status = $worker->status();

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'circuit_open', $result['code'] );
        $this->assertArrayNotHasKey( Warm_Background_Process::RETRY_HOOK, $scheduled );
        $this->assertSame( [ 1300, [] ], $scheduled[ Warm_Background_Process::RETRY_BATCH_HOOK ] );
        $this->assertSame( 2, count( $schedule_calls ) );
        $this->assertSame( 4, $status['deferred'] );
        $this->assertSame( 1300, $status['recovery_at'] );
        $this->assertSame( 'automatic-warm-circuit-open', ( new Performance_Event_Log() )->recent()[0]['code'] );
        $worker->reset();
    }

    public function test_retry_limit_remains_terminal_after_the_circuit_threshold_is_reached() {
        $reported = [];
        $listener = static function ( $job_id, $url, $result ) use ( &$reported ) {
            $reported[] = compact( 'job_id', 'url', 'result' );
        };
        add_action( 'directorist_page_cache_performance_warm_result', $listener, 10, 3 );
        $worker = new Warm_Background_Process(
            [
                'requester'   => static function () {
                    return [ 'response' => [ 'code' => 500 ] ];
                },
                'scheduler'   => static function () {
                    return true;
                },
                'unscheduler' => static function () {
                    return true;
                },
                'clock'       => static function () {
                    return 1000;
                },
            ]
        );
        $worker->reset();

        try {
            for ( $attempt = 0; $attempt < 3; ++$attempt ) {
                $worker->process_item(
                    [
                        'url'      => home_url( '/directory/circuit-primer-' . $attempt . '/' ),
                        'attempts' => 0,
                    ]
                );
            }

            $result = $worker->process_item(
                [
                    'url'                => home_url( '/directory/retry-exhausted/' ),
                    'attempts'           => 3,
                    'performance_job_id' => 'job-retry-limit',
                ]
            );
            $status = $worker->status();
        } finally {
            remove_action( 'directorist_page_cache_performance_warm_result', $listener, 10 );
            $worker->reset();
        }

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'retry_exhausted', $result['code'] );
        $this->assertCount( 1, $reported );
        $this->assertSame( 'http-status-500', $reported[0]['result']['code'] );
        $this->assertSame( 3, $status['deferred'] );
    }

    public function test_circuit_open_items_use_bounded_deferred_batches_and_one_successor_event() {
        $scheduled  = [];
        $dispatches = 0;
        $now        = 1000;
        $worker     = new Warm_Background_Process(
            [
                'requester'   => static function () {
                    return [ 'response' => [ 'code' => 500 ] ];
                },
                'dispatcher'  => static function () use ( &$dispatches ) {
                    ++$dispatches;

                    return true;
                },
                'scheduler'   => static function ( $timestamp, $hook, $args ) use ( &$scheduled ) {
                    $scheduled[ $hook ] = [ $timestamp, $args ];

                    return true;
                },
                'unscheduler' => static function ( $hook ) use ( &$scheduled ) {
                    unset( $scheduled[ $hook ] );

                    return true;
                },
                'clock'       => static function () use ( &$now ) {
                    return $now;
                },
            ]
        );
        $worker->reset();

        for ( $index = 0; $index < 55; ++$index ) {
            $worker->process_item(
                [
                    'url'      => home_url( '/directory/deferred-' . $index . '/' ),
                    'attempts' => 0,
                ]
            );
        }

        $this->assertSame( 55, $worker->status()['deferred'] );
        $this->assertCount( 1, $scheduled );

        $now    = 1300;
        $result = $worker->retry_deferred();
        $status = $worker->status();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 50, $result['queued'] );
        $this->assertSame( 5, $result['deferred'] );
        $this->assertSame( 1, $dispatches );
        $this->assertSame( 5, $status['deferred'] );
        $this->assertSame( 1301, $status['recovery_at'] );
        $this->assertSame( [ 1301, [] ], $scheduled[ Warm_Background_Process::RETRY_BATCH_HOOK ] );
        $worker->reset();
    }

    public function test_deferred_items_remain_durable_when_queue_persistence_fails() {
        $scheduled = [];
        $now       = 1000;
        $worker    = new Warm_Background_Process(
            [
                'requester'       => static function () {
                    return [ 'response' => [ 'code' => 500 ] ];
                },
                'queue_persister' => static function () {
                    return false;
                },
                'scheduler'       => static function ( $timestamp, $hook, $args ) use ( &$scheduled ) {
                    $scheduled[ $hook ] = [ $timestamp, $args ];

                    return true;
                },
                'unscheduler'     => static function ( $hook ) use ( &$scheduled ) {
                    unset( $scheduled[ $hook ] );

                    return true;
                },
                'clock'           => static function () use ( &$now ) {
                    return $now;
                },
            ]
        );
        $worker->reset();
        $worker->process_item( [ 'url' => home_url( '/directory/durable-retry/' ), 'attempts' => 0 ] );
        $now    = 1100;
        $result = $worker->retry_deferred();
        $status = $worker->status();

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'retry_persist_failed', $result['code'] );
        $this->assertSame( 1, $result['deferred'] );
        $this->assertSame( 1, $status['deferred'] );
        $this->assertGreaterThan( $now, $status['recovery_at'] );
        $worker->reset();
    }

    public function test_legacy_retry_is_migrated_to_the_single_deferred_recovery_event() {
        $scheduled = [];
        $worker    = new Warm_Background_Process(
            [
                'scheduler'   => static function ( $timestamp, $hook, $args ) use ( &$scheduled ) {
                    $scheduled[ $hook ] = [ $timestamp, $args ];

                    return true;
                },
                'unscheduler' => static function ( $hook ) use ( &$scheduled ) {
                    unset( $scheduled[ $hook ] );

                    return true;
                },
                'clock'       => static function () {
                    return 1000;
                },
            ]
        );
        $worker->reset();

        $result = $worker->retry( [ 'url' => home_url( '/directory/legacy-retry/' ), 'attempts' => 1 ] );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'retry_deferred', $result['code'] );
        $this->assertSame( 1, $worker->status()['deferred'] );
        $this->assertArrayNotHasKey( Warm_Background_Process::RETRY_HOOK, $scheduled );
        $this->assertSame( [ 1000, [] ], $scheduled[ Warm_Background_Process::RETRY_BATCH_HOOK ] );
        $worker->reset();
    }

    public function test_worker_reset_clears_deferred_retries_and_recovery_events() {
        $scheduled = [];
        $worker    = new Warm_Background_Process(
            [
                'requester'   => static function () {
                    return [ 'response' => [ 'code' => 500 ] ];
                },
                'scheduler'   => static function ( $timestamp, $hook, $args ) use ( &$scheduled ) {
                    $scheduled[ $hook ] = [ $timestamp, $args ];

                    return true;
                },
                'unscheduler' => static function ( $hook ) use ( &$scheduled ) {
                    unset( $scheduled[ $hook ] );

                    return true;
                },
                'clock'       => static function () {
                    return 1000;
                },
            ]
        );
        $worker->reset();
        $worker->process_item( [ 'url' => home_url( '/directory/reset-retry/' ), 'attempts' => 0 ] );

        $this->assertSame( 1, $worker->status()['deferred'] );
        $this->assertNotEmpty( $scheduled );

        $worker->reset();

        $this->assertSame( 0, $worker->status()['deferred'] );
        $this->assertSame( 0, $worker->status()['recovery_at'] );
        $this->assertSame( [], $scheduled );
    }

    public function test_worker_reset_clears_every_legacy_retry_argument_signature() {
        $first  = [ 'url' => home_url( '/directory/legacy-one/' ), 'attempts' => 1 ];
        $second = [ 'url' => home_url( '/directory/legacy-two/' ), 'attempts' => 2 ];
        $worker = new Warm_Background_Process();
        $worker->reset();

        wp_schedule_single_event( time() + 60, Warm_Background_Process::RETRY_HOOK, [ $first ] );
        wp_schedule_single_event( time() + 120, Warm_Background_Process::RETRY_HOOK, [ $second ] );

        $this->assertNotFalse( wp_next_scheduled( Warm_Background_Process::RETRY_HOOK, [ $first ] ) );
        $this->assertNotFalse( wp_next_scheduled( Warm_Background_Process::RETRY_HOOK, [ $second ] ) );

        $worker->reset();

        $this->assertFalse( wp_next_scheduled( Warm_Background_Process::RETRY_HOOK, [ $first ] ) );
        $this->assertFalse( wp_next_scheduled( Warm_Background_Process::RETRY_HOOK, [ $second ] ) );
    }

    public function test_locked_retry_callback_schedules_one_delayed_successor() {
        $scheduled = [];
        $now       = 1000;
        $worker    = new Warm_Background_Process(
            [
                'requester'   => static function () {
                    return [ 'response' => [ 'code' => 500 ] ];
                },
                'scheduler'   => static function ( $timestamp, $hook, $args ) use ( &$scheduled ) {
                    $scheduled[ $hook ] = [ $timestamp, $args ];

                    return true;
                },
                'unscheduler' => static function ( $hook ) use ( &$scheduled ) {
                    unset( $scheduled[ $hook ] );

                    return true;
                },
                'clock'       => static function () use ( &$now ) {
                    return $now;
                },
            ]
        );
        $worker->reset();
        $worker->process_item( [ 'url' => home_url( '/directory/locked-retry/' ), 'attempts' => 0 ] );
        $identifier = 'wp_' . get_current_blog_id() . '_' . Warm_Background_Process::ACTION;

        unset( $scheduled[ Warm_Background_Process::RETRY_BATCH_HOOK ] );
        set_transient( $identifier . '_deferred_lock', 1, MINUTE_IN_SECONDS );
        $result = $worker->retry_deferred();

        $this->assertSame( 'retry_batch_locked', $result['code'] );
        $this->assertSame( [ 1060, [] ], $scheduled[ Warm_Background_Process::RETRY_BATCH_HOOK ] );
        delete_transient( $identifier . '_deferred_lock' );
        $worker->reset();
    }

    public function test_worker_status_repairs_a_missing_deferred_recovery_event() {
        $worker = new Warm_Background_Process(
            [
                'requester' => static function () {
                    return [ 'response' => [ 'code' => 500 ] ];
                },
            ]
        );
        $worker->reset();
        $worker->process_item( [ 'url' => home_url( '/directory/missing-recovery/' ), 'attempts' => 0 ] );

        $this->assertNotFalse( wp_next_scheduled( Warm_Background_Process::RETRY_BATCH_HOOK ) );
        wp_unschedule_hook( Warm_Background_Process::RETRY_BATCH_HOOK );
        $this->assertFalse( wp_next_scheduled( Warm_Background_Process::RETRY_BATCH_HOOK ) );

        $status = $worker->status();

        $this->assertSame( 1, $status['deferred'] );
        $this->assertNotFalse( wp_next_scheduled( Warm_Background_Process::RETRY_BATCH_HOOK ) );
        $this->assertGreaterThan( time(), $status['recovery_at'] );
        $worker->reset();
    }
}
