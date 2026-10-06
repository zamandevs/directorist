<?php

use Directorist\Cache\Built_In\Cache_Engine;

require_once __DIR__ . '/fixtures/PerformanceReviewFixture.php';

/**
 * Cache bypass constants and request-static hook guards cannot be reset in PHP.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Directorist_Performance_Preload_Review_Regression_Test extends Directorist_Performance_Review_Fixture {
    public function test_external_adapter_keeps_partial_queue_acceptance_for_job_accounting() {
        $provider = new \Directorist\Cache\Cache_Enabler_Provider( [
            'purge_site' => '__return_true',
            'warm_urls' => static function ( $urls ) {
                return [ 'success' => true, 'code' => 'queued', 'queued' => 1, 'accepted_urls' => [ $urls[1] ] ];
            },
        ] );
        $operations = new \Directorist\Cache\Performance_Operations( $provider );
        $manager = new \Directorist\Cache\Performance_Job_Manager( new \Directorist\Cache\Performance_Resource_Catalog( $provider ), $operations );
        $method = new ReflectionMethod( $manager, 'execute_url_batch' );
        $method->setAccessible( true );
        $urls = [ home_url( '/directory/review-first/' ), home_url( '/directory/review-second/' ) ];
        $result = $method->invoke( $manager, 'warm_urls', $urls );
        $this->assertSame( 1, $result['queued'], 'The adapter discarded the worker queue result, so the manager counted phantom work: ' . wp_json_encode( $result ) );
        $this->assertSame( [ $urls[1] ], $result['accepted_urls'] );
    }

    public function test_failed_queue_batch_keeps_urls_already_accepted_by_the_worker() {
        $urls = [ home_url( '/directory/first/' ), home_url( '/directory/second/' ) ];
        $provider = new \Directorist\Cache\Cache_Enabler_Provider( [
            'warm_urls' => static function () use ( $urls ) {
                return [ 'success' => false, 'code' => 'queue_write_failed', 'queued' => 1, 'accepted_urls' => [ $urls[1] ] ];
            },
        ] );
        $manager = new \Directorist\Cache\Performance_Job_Manager( new \Directorist\Cache\Performance_Resource_Catalog( $provider ), new \Directorist\Cache\Performance_Operations( $provider ) );
        $method = new ReflectionMethod( $manager, 'execute_url_batch' );
        $method->setAccessible( true );
        $result = $method->invoke( $manager, 'warm_urls', $urls );
        $this->assertFalse( $result['success'] );
        $this->assertSame( 1, $result['queued'] );
        $this->assertSame( [ $urls[1] ], $result['accepted_urls'] );
    }

    public function test_partial_queue_count_without_membership_does_not_invent_accepted_urls() {
        $provider = new \Directorist\Cache\Cache_Enabler_Provider( [ 'warm_urls' => static function () {
            return [ 'success' => true, 'queued' => 1 ];
        } ] );
        $result = $provider->warm( [ home_url( '/first/' ), home_url( '/second/' ) ] );
        $this->assertFalse( $result['success'] );
        $this->assertSame( 'invalid_warm_result', $result['code'] );
        $this->assertSame( [], $result['accepted_urls'] );
    }

    public static function provider_acceptance_cases() {
        $cases = [];
        foreach ( [
            \Directorist\Cache\Cache_Enabler_Provider::class,
            \Directorist\Cache\LiteSpeed_Cache_Provider::class,
            \Directorist\Cache\WP_Fastest_Cache_Provider::class,
            \Directorist\Cache\WP_Rocket_Provider::class,
            \Directorist\Cache\WP_Super_Cache_Provider::class,
        ] as $class ) {
            foreach ( [ 0, 1, 2 ] as $accepted ) {
                $cases[] = [ $class, $accepted ];
            }
        }
        return $cases;
    }

    /** @dataProvider provider_acceptance_cases */
    public function test_each_provider_preserves_actual_preload_acceptance( $class, $accepted ) {
        $urls = [ home_url( '/directory/first/' ), home_url( '/directory/second/' ) ];
        $accepted_urls = array_slice( array_reverse( $urls ), 0, $accepted );
        $provider = new $class( [ 'server' => true, 'purge_site' => '__return_true', 'warm_urls' => static function () use ( $accepted, $accepted_urls ) {
            return [ 'success' => true, 'code' => $accepted ? 'queued' : 'no_urls', 'queued' => $accepted, 'accepted_urls' => $accepted_urls ];
        } ] );
        $result = $provider->warm( $urls );
        $this->assertTrue( $result['success'] );
        $this->assertSame( $accepted, $result['queued'] ?? -1 );
        $this->assertSame( $accepted_urls, $result['accepted_urls'] ?? null );
        $this->assertSame( $accepted ? 'queued' : 'no_urls', $result['code'] );
        $this->assertTrue( $provider->invalidate( [ 'site_id' => get_current_blog_id(), 'conservative' => true ] )['success'] );
    }
}
