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
        return [ 'purge_site', 'warm_urls' ];
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

    public function test_companion_operations_degrade_when_missing_and_delegate_when_registered() {
        $operations = $this->operations( new Directorist_Page_Cache_Performance_Test_Provider() );

        $this->assertSame( 'operation_unavailable', $operations->execute( 'pause', [] )['code'] );

        add_filter(
            'directorist_page_cache_performance_operation',
            static function ( $result, $action ) {
                return 'pause' === $action ? [ 'success' => true, 'code' => 'paused' ] : $result;
            },
            10,
            2
        );

        $this->assertSame( 'paused', $operations->execute( 'pause', [] )['code'] );
        $this->assertSame( 'unknown_operation', $operations->execute( 'delete-everything', [] )['code'] );
    }

    public function test_settings_input_is_normalized_through_schema() {
        $result = $this->operations( new Directorist_Page_Cache_Performance_Test_Provider() )->execute(
            'save_settings',
            [ 'sample_rate' => 10, 'history_limit' => 75, 'enabled' => 1, 'unknown' => 'drop' ]
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 10, $result['settings']['sample_rate'] );
        $this->assertSame( 75, $result['settings']['history_limit'] );
        $this->assertArrayNotHasKey( 'unknown', $result['settings'] );
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
