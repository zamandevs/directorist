<?php
/**
 * Provider registry selection and lifecycle tests.
 */

use Directorist\Cache\Cache_Provider;
use Directorist\Cache\Dropin_Owner_Detector;
use Directorist\Cache\Provider_Registry;

class Directorist_Page_Cache_Registry_Test_Provider implements Cache_Provider {
    private $id;

    private $available;

    private $capabilities;

    public function __construct( $id, $available = true, array $capabilities = [ 'purge_site' ] ) {
        $this->id           = $id;
        $this->available    = $available;
        $this->capabilities = $capabilities;
    }

    public function get_id() {
        return $this->id;
    }

    public function is_available() {
        return $this->available;
    }

    public function get_capabilities() {
        return $this->capabilities;
    }

    public function supports( $capability ) {
        return in_array( $capability, $this->capabilities, true );
    }

    public function invalidate( array $request ) {
        unset( $request );

        return [ 'success' => true ];
    }

    public function warm( array $urls ) {
        unset( $urls );

        return [ 'success' => false ];
    }

    public function get_status() {
        return [ 'id' => $this->id ];
    }
}

class Directorist_Page_Cache_Provider_Registry_Test extends WP_UnitTestCase {
    public function test_zero_available_providers_keeps_selection_inert() {
        $registry  = $this->registry_with_owner( 'none' );
        $selection = $registry->select( false );

        $this->assertFalse( $selection->is_selected() );
        $this->assertSame( 'no_available_provider', $selection->get_code() );
        $this->assertSame( [], $selection->get_candidate_ids() );
    }

    public function test_one_available_provider_is_selected() {
        $registry = $this->registry_with_owner( 'none' );
        $provider = new Directorist_Page_Cache_Registry_Test_Provider( 'cache-a' );
        $registry->register( $provider, 20 );

        $selection = $registry->select( false );

        $this->assertTrue( $selection->is_selected() );
        $this->assertSame( 'selected', $selection->get_code() );
        $this->assertSame( $provider, $selection->get_provider() );
    }

    public function test_unavailable_provider_is_not_a_candidate() {
        $registry = $this->registry_with_owner( 'none' );
        $registry->register( new Directorist_Page_Cache_Registry_Test_Provider( 'cache-a', false ) );

        $this->assertSame( 'no_available_provider', $registry->select( false )->get_code() );
    }

    public function test_exact_only_provider_is_not_selected_without_broad_fallback() {
        $registry = $this->registry_with_owner( 'none' );
        $registry->register( new Directorist_Page_Cache_Registry_Test_Provider( 'exact-only', true, [ 'purge_url' ] ) );

        $this->assertSame( 'no_available_provider', $registry->select( false )->get_code() );
    }

    public function test_native_dependency_and_generation_provider_is_safe_without_site_purge() {
        $registry = $this->registry_with_owner( 'none' );
        $provider = new Directorist_Page_Cache_Registry_Test_Provider( 'tag-cache', true, [ 'purge_dependencies', 'purge_generations' ] );
        $registry->register( $provider );

        $this->assertSame( $provider, $registry->select( false )->get_provider() );
    }

    public function test_multiple_available_providers_fail_closed_with_deterministic_ids() {
        $registry = $this->registry_with_owner( 'none' );
        $registry->register( new Directorist_Page_Cache_Registry_Test_Provider( 'cache-b' ), 10 );
        $registry->register( new Directorist_Page_Cache_Registry_Test_Provider( 'cache-a' ), 20 );

        $selection = $registry->select( false );

        $this->assertFalse( $selection->is_selected() );
        $this->assertSame( 'multiple_providers', $selection->get_code() );
        $this->assertSame( [ 'cache-a', 'cache-b' ], $selection->get_candidate_ids() );
    }

    public function test_equal_priority_candidates_use_stable_id_order() {
        $registry = $this->registry_with_owner( 'none' );
        $registry->register( new Directorist_Page_Cache_Registry_Test_Provider( 'cache-z' ), 10 );
        $registry->register( new Directorist_Page_Cache_Registry_Test_Provider( 'cache-a' ), 10 );

        $this->assertSame( [ 'cache-a', 'cache-z' ], $registry->select( false )->get_candidate_ids() );
    }

    public function test_known_dropin_owner_must_match_the_only_provider() {
        $matching = $this->registry_with_owner( 'cache-a' );
        $provider = new Directorist_Page_Cache_Registry_Test_Provider( 'cache-a' );
        $matching->register( $provider );

        $mismatch = $this->registry_with_owner( 'cache-b' );
        $mismatch->register( new Directorist_Page_Cache_Registry_Test_Provider( 'cache-a' ) );

        $this->assertSame( $provider, $matching->select( false )->get_provider() );
        $this->assertSame( 'dropin_owner_mismatch', $mismatch->select( false )->get_code() );
    }

    public function test_unknown_dropin_blocks_automatic_selection() {
        $registry = $this->registry_with_owner( 'unknown' );
        $registry->register( new Directorist_Page_Cache_Registry_Test_Provider( 'cache-a' ) );

        $this->assertSame( 'unknown_dropin', $registry->select( false )->get_code() );
    }

    public function test_duplicate_provider_ids_keep_the_highest_priority_registration() {
        $registry = $this->registry_with_owner( 'none' );
        $low      = new Directorist_Page_Cache_Registry_Test_Provider( 'cache-a' );
        $high     = new Directorist_Page_Cache_Registry_Test_Provider( 'cache-a' );

        $registry->register( $low, 5 );
        $registry->register( $high, 50 );

        $this->assertSame( $high, $registry->select( false )->get_provider() );
    }

    public function test_custom_provider_filter_is_applied_before_selection() {
        $registry = $this->registry_with_owner( 'none' );
        $provider = new Directorist_Page_Cache_Registry_Test_Provider( 'filter-cache' );
        $callback = static function ( $providers ) use ( $provider ) {
            $providers[] = [ 'provider' => $provider, 'priority' => 40 ];

            return $providers;
        };

        add_filter( 'directorist_page_cache_providers', $callback );
        $selection = $registry->select( false );
        remove_filter( 'directorist_page_cache_providers', $callback );

        $this->assertSame( $provider, $selection->get_provider() );
    }

    public function test_public_registration_api_rejects_invalid_ids_and_accepts_provider() {
        $invalid = new Directorist_Page_Cache_Registry_Test_Provider( '' );
        $valid   = new Directorist_Page_Cache_Registry_Test_Provider( 'custom-cache' );

        $this->assertFalse( directorist_page_cache_register_provider( $invalid ) );
        $this->assertTrue( directorist_page_cache_register_provider( $valid, 30 ) );
    }

    public function test_lifecycle_callback_invalidates_persistent_owner_cache() {
        update_site_option( Dropin_Owner_Detector::CACHE_OPTION, [ 'owner' => 'cache-a', 'path' => '/tmp/a' ] );

        directorist_page_cache_flush_provider_detection();

        $this->assertFalse( get_site_option( Dropin_Owner_Detector::CACHE_OPTION, false ) );
    }

    private function registry_with_owner( $owner ) {
        $detector = $this->getMockBuilder( Dropin_Owner_Detector::class )
            ->setConstructorArgs( [ '', false ] )
            ->onlyMethods( [ 'detect' ] )
            ->getMock();
        $detector->method( 'detect' )->willReturn( $owner );

        return new Provider_Registry( $detector );
    }
}
