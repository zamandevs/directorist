<?php
/**
 * Performance settings invalidation and warming behavior locks.
 */

use Directorist\Cache\Cache_Provider;
use Directorist\Cache\Performance_Settings_Automation;

class Directorist_Performance_Settings_Automation_Test_Provider implements Cache_Provider {
    public $invalidations = [];

    public function get_id() {
        return 'settings-automation'; }

    public function is_available() {
        return true; }

    public function get_capabilities() {
        return [ 'purge_site', 'warm_urls' ]; }

    public function supports( $capability ) {
        return in_array( $capability, $this->get_capabilities(), true ); }

    public function invalidate( array $request ) {
        $this->invalidations[] = $request; return [ 'success' => true, 'code' => 'invalidated' ]; }

    public function warm( array $urls ) {
        unset( $urls ); return [ 'success' => true, 'code' => 'queued' ]; }

    public function get_status() {
        return [ 'available' => true ]; }
}

final class Directorist_Page_Cache_Performance_Settings_Automation_Test extends WP_UnitTestCase {
    public function test_unchanged_or_disabled_settings_do_not_touch_the_provider() {
        $provider = new Directorist_Performance_Settings_Automation_Test_Provider();
        $service  = new Performance_Settings_Automation( $provider );
        $enabled  = [ 'enabled' => true, 'cache_duration' => 'automatic', 'cache_filtered_results' => true ];
        $disabled = [ 'enabled' => false, 'cache_duration' => 'automatic', 'cache_filtered_results' => true ];

        $this->assertSame( 'settings-unchanged', $service->apply( $enabled, $enabled )['code'] );
        $this->assertSame( 'cache-disabled', $service->apply( $disabled, $enabled )['code'] );
        $this->assertSame( [], $provider->invalidations );
    }

    public function test_policy_change_invalidates_conservatively_and_queues_bounded_warming() {
        $provider = new Directorist_Performance_Settings_Automation_Test_Provider();
        $warming  = [];
        $service  = new Performance_Settings_Automation(
            $provider,
            static function ( $invalidation, $plan ) use ( &$warming ) {
                $warming = compact( 'invalidation', 'plan' );

                return [ 'success' => true, 'code' => 'queued', 'queued' => 2 ];
            },
            static function () {
                return 7;
            }
        );

        $result = $service->apply(
            [ 'enabled' => true, 'cache_duration' => '21600', 'cache_filtered_results' => false ],
            [ 'enabled' => true, 'cache_duration' => '3600', 'cache_filtered_results' => true ]
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'settings-refreshed', $result['code'] );
        $this->assertSame( [ 'cache_duration', 'cache_filtered_results' ], $result['changed'] );
        $this->assertSame( 7, $provider->invalidations[0]['site_id'] );
        $this->assertTrue( $provider->invalidations[0]['conservative'] );
        $this->assertSame( [ 'settings', 'template' ], $warming['plan']['generations'] );
    }

    public function test_provider_exceptions_are_contained() {
        $provider = new class() extends Directorist_Performance_Settings_Automation_Test_Provider {
            public function is_available() {
                throw new RuntimeException( 'provider failed' ); }
        };
        $service  = new Performance_Settings_Automation( $provider );
        $result   = $service->apply(
            [ 'enabled' => true, 'cache_duration' => '21600', 'cache_filtered_results' => true ],
            [ 'enabled' => true, 'cache_duration' => '3600', 'cache_filtered_results' => true ]
        );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'provider-unavailable', $result['code'] );
    }
}
