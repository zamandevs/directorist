<?php
/**
 * Behavior and contract tests for the Directorist page-cache manager.
 */

use Directorist\Cache\Cache_Manager;
use Directorist\Cache\Cache_Provider;
use Directorist\Cache\Null_Cache_Provider;

class Directorist_Page_Cache_Manager_Test extends WP_UnitTestCase {
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_bootstrap_does_not_eagerly_load_request_policy_value_objects() {
        $this->assertFalse( class_exists( 'Directorist\\Cache\\Request_Context', false ) );
        $this->assertFalse( class_exists( 'Directorist\\Cache\\Request_Policy', false ) );
        $this->assertFalse( class_exists( 'Directorist\\Cache\\Eligibility_Result', false ) );
        $this->assertFalse( class_exists( 'Directorist\\Cache\\Provider_Registry', false ) );
        $this->assertFalse( class_exists( 'Directorist\\Cache\\Dropin_Owner_Detector', false ) );
        $this->assertFalse( class_exists( 'Directorist\\Cache\\WP_Super_Cache_Provider', false ) );
        $this->assertFalse( class_exists( 'Directorist\\Cache\\Response_Capture', false ) );
        $this->assertFalse( class_exists( 'Directorist\\Cache\\Warm_URL_Registry', false ) );
        $this->assertFalse( class_exists( 'Directorist\\Cache\\Warm_URL_Discovery', false ) );
    }

    public function test_plugin_bootstrap_initializes_one_request_scoped_manager() {
        $first  = Cache_Manager::instance();
        $second = Cache_Manager::instance();

        $this->assertSame( $first, $second );
        $this->assertTrue( $first->is_initialized() );
        $this->assertSame( $first, directorist_page_cache() );
    }

    public function test_default_provider_is_an_inert_null_provider() {
        $provider = Cache_Manager::instance()->get_provider();

        $this->assertInstanceOf( Cache_Provider::class, $provider );
        $this->assertInstanceOf( Null_Cache_Provider::class, $provider );
        $this->assertSame( 'none', $provider->get_id() );
        $this->assertFalse( $provider->is_available() );
        $this->assertSame( [], $provider->get_capabilities() );
        $this->assertFalse( $provider->supports( 'purge_url' ) );
    }

    public function test_null_provider_operations_are_no_ops_with_explicit_results() {
        $provider = new Null_Cache_Provider();

        $this->assertSame(
            [
                'success' => false,
                'code'    => 'no_provider',
            ],
            $provider->invalidate( [ 'urls' => [ 'https://example.org/directory/' ] ] )
        );
        $this->assertSame(
            [
                'success' => false,
                'code'    => 'no_provider',
            ],
            $provider->warm( [ 'https://example.org/directory/' ] )
        );
        $this->assertSame(
            [
                'id'           => 'none',
                'available'    => false,
                'capabilities' => [],
            ],
            $provider->get_status()
        );
    }

    public function test_manager_initialization_is_idempotent() {
        $manager = Cache_Manager::instance();

        $this->assertSame( $manager, $manager->initialize() );
        $this->assertSame( $manager, $manager->initialize() );
        $this->assertSame( 1, did_action( 'directorist_page_cache_initialized' ) );
    }
}
