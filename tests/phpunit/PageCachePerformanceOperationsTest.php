<?php
/**
 * Dashboard operation and compatibility behavior locks.
 */

use Directorist\Cache\Cache_Provider;
use Directorist\Cache\Performance_Event_Log;
use Directorist\Cache\Performance_Operations;
use Directorist\Cache\Performance_Settings;

class Directorist_Page_Cache_Performance_Test_Provider implements Cache_Provider {
    public $invalidations = [];

    public $warms = [];

    public function get_id() {
        return 'test-cache';
    }

    public function is_available() {
        return true;
    }

    public function get_capabilities() {
        return [ 'purge_site', 'purge_dependencies', 'purge_entries', 'warm_urls' ];
    }

    public function supports( $capability ) {
        return in_array( $capability, $this->get_capabilities(), true );
    }

    public function invalidate( array $request ) {
        $this->invalidations[] = $request;

        return [ 'success' => true, 'code' => 'invalidated' ];
    }

    public function warm( array $urls ) {
        $this->warms[] = $urls;

        return [ 'success' => true, 'code' => 'queued', 'queued' => count( $urls ) ];
    }

    public function get_status() {
        return [ 'id' => 'test-cache', 'available' => true, 'nested' => [ 'healthy' => true ] ];
    }
}

class Directorist_Page_Cache_Performance_Throwing_Provider extends Directorist_Page_Cache_Performance_Test_Provider {
    public function supports( $capability ) {
        unset( $capability );
        throw new RuntimeException( 'provider failed' );
    }

    public function get_status() {
        throw new RuntimeException( 'provider failed' );
    }
}

class Directorist_Page_Cache_Performance_Operations_Test extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option( Performance_Settings::OPTION_NAME );
        delete_option( Performance_Event_Log::OPTION_NAME );
    }

    protected function tearDown(): void {
        delete_option( Performance_Settings::OPTION_NAME );
        delete_option( Performance_Event_Log::OPTION_NAME );
        remove_all_filters( 'directorist_page_cache_performance_operation' );
        remove_all_actions( 'directorist_page_cache_enabled_changed' );
        parent::tearDown();
    }

    public function test_purge_and_warm_use_provider_contracts_with_bounded_inputs() {
        $provider   = new Directorist_Page_Cache_Performance_Test_Provider();
        $operations = $this->operations( $provider );

        $purge = $operations->execute( 'purge', [] );
        $warm  = $operations->execute( 'warm', [ 'listing_limit' => 999, 'term_limit' => -1, 'page_limit' => 99 ] );

        $this->assertSame( 'invalidated', $purge['code'] );
        $this->assertTrue( $provider->invalidations[0]['conservative'] );
        $this->assertSame( 1, $provider->invalidations[0]['site_id'] );
        $this->assertSame( 'queued', $warm['code'] );
        $this->assertSame( [ [ home_url( '/warm-one/' ), home_url( '/warm-two/' ) ] ], $provider->warms );
    }

    public function test_enable_disable_updates_core_first_and_notifies_integrations() {
        $changes    = [];
        $operations = $this->operations( new Directorist_Page_Cache_Performance_Test_Provider() );
        add_action(
            'directorist_page_cache_enabled_changed',
            static function ( $enabled ) use ( &$changes ) {
                $changes[] = $enabled;
            }
        );

        $disabled = $operations->execute( 'disable', [] );
        $enabled  = $operations->execute( 'enable', [] );

        $this->assertSame( 'disabled', $disabled['code'] );
        $this->assertSame( 'enabled', $enabled['code'] );
        $this->assertSame( [ false, true ], $changes );
        $this->assertTrue( ( new Performance_Settings() )->get()['enabled'] );
    }

    public function test_internal_queue_controls_are_not_public_operations() {
        $operations = $this->operations( new Directorist_Page_Cache_Performance_Test_Provider() );

        $this->assertSame( 'unknown_operation', $operations->execute( 'pause', [] )['code'] );
        $this->assertSame( 'unknown_operation', $operations->execute( 'resume', [] )['code'] );
        $this->assertSame( 'unknown_operation', $operations->execute( 'cancel', [] )['code'] );
        $this->assertSame( 'unknown_operation', $operations->execute( 'delete-everything', [] )['code'] );
    }

    public function test_exact_url_purge_accepts_only_same_origin_public_urls() {
        $provider   = new Directorist_Page_Cache_Performance_Test_Provider();
        $operations = $this->operations( $provider );
        $url        = home_url( '/directory/listing/' );

        $result  = $operations->execute( 'purge_url', [ 'url' => $url ] );
        $foreign = $operations->execute( 'purge_url', [ 'url' => 'https://foreign.test/listing/' ] );

        $this->assertSame( 'invalidated', $result['code'] );
        $this->assertSame( [ $url ], $provider->invalidations[0]['urls'] );
        $this->assertFalse( $provider->invalidations[0]['conservative'] );
        $this->assertSame( 'invalid_url', $foreign['code'] );
        $this->assertCount( 1, $provider->invalidations );
    }

    public function test_route_and_entry_purges_accept_only_bounded_identifiers() {
        $provider   = new Directorist_Page_Cache_Performance_Test_Provider();
        $operations = $this->operations( $provider );
        $hash       = str_repeat( 'a', 64 );

        $route     = $operations->execute( 'purge_route', [ 'route_type' => 'listing' ] );
        $entry     = $operations->execute( 'purge_entry', [ 'entry_hash' => $hash ] );
        $bad       = $operations->execute( 'purge_entry', [ 'entry_hash' => '../../wp-config.php' ] );
        $bad_route = $operations->execute( 'purge_route', [ 'route_type' => '../listing' ] );

        $this->assertSame( 'invalidated', $route['code'] );
        $this->assertSame( [ 'directorist:1:route:listing' ], $provider->invalidations[0]['dependencies'] );
        $this->assertSame( 'invalidated', $entry['code'] );
        $this->assertSame( [ $hash ], $provider->invalidations[1]['entry_hashes'] );
        $this->assertSame( 'invalid_entry', $bad['code'] );
        $this->assertSame( 'invalid_route', $bad_route['code'] );
        $this->assertCount( 2, $provider->invalidations );
    }

    public function test_diagnostics_are_expiring_and_do_not_expose_arbitrary_settings() {
        $operations = $this->operations( new Directorist_Page_Cache_Performance_Test_Provider() );

        $enabled  = $operations->execute( 'enable_diagnostics', [] );
        $disabled = $operations->execute( 'disable_diagnostics', [] );

        $this->assertTrue( $enabled['settings']['diagnostics_until'] > time() );
        $this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS, $enabled['settings']['diagnostics_until'] );
        $this->assertSame( 0, $disabled['settings']['diagnostics_until'] );
    }

    public function test_settings_input_is_normalized_through_schema() {
        $result = $this->operations( new Directorist_Page_Cache_Performance_Test_Provider() )->execute(
            'save_settings',
            [ 'sample_rate' => 10, 'history_limit' => 75, 'enabled' => 1, 'unknown' => 'drop' ]
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 10, $result['settings']['sample_rate'] );
        $this->assertSame( 50, $result['settings']['history_limit'] );
        $this->assertArrayNotHasKey( 'unknown', $result['settings'] );
    }

    public function test_settings_transition_notifies_lifecycle_with_current_and_previous_values() {
        $changes = [];
        ( new Performance_Settings() )->update(
            [
                'enabled'                => true,
                'cache_duration'         => '3600',
                'cache_filtered_results' => true,
            ]
        );
        add_action(
            'directorist_page_cache_enabled_changed',
            static function ( $enabled, $current, $previous ) use ( &$changes ) {
                $changes = compact( 'enabled', 'current', 'previous' );
            },
            10,
            3
        );

        $this->operations( new Directorist_Page_Cache_Performance_Test_Provider() )->execute(
            'save_settings',
            [ 'cache_duration' => '21600', 'cache_filtered_results' => false ]
        );

        $this->assertTrue( $changes['enabled'] );
        $this->assertSame( '3600', $changes['previous']['cache_duration'] );
        $this->assertTrue( $changes['previous']['cache_filtered_results'] );
        $this->assertSame( '21600', $changes['current']['cache_duration'] );
        $this->assertFalse( $changes['current']['cache_filtered_results'] );
    }

    public function test_throwing_provider_is_contained_as_an_operation_failure() {
        $operations = $this->operations( new Directorist_Page_Cache_Performance_Throwing_Provider() );

        $this->assertSame( 'operation_exception', $operations->execute( 'purge', [] )['code'] );
        $this->assertSame( 'unhealthy', $operations->execute( 'verify', [] )['code'] );
    }

    private function operations( Cache_Provider $provider ) {
        return new Performance_Operations(
            $provider,
            new Performance_Settings(),
            new Performance_Event_Log(),
            static function () {
                return [ home_url( '/warm-one/' ), home_url( '/warm-two/' ) ];
            },
            static function () {
                return 1;
            }
        );
    }
}
