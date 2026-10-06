<?php
/**
 * Built-in cache provider behavior locks.
 */

use Directorist\Cache\Built_In\Provider;

final class Directorist_Page_Cache_Built_In_Test_Engine {
    public $invalidations = [];

    public $warms = [];

    public $warming = true;

    public function is_available() {
        return true;
    }

    public function invalidate( array $plan ) {
        $this->invalidations[] = $plan;

        return [ 'success' => true, 'code' => 'engine_invalidated' ];
    }

    public function warm( array $urls ) {
        $this->warms[] = $urls;

        return [ 'success' => true, 'code' => 'engine_warmed' ];
    }

    public function supports_warm() {
        return $this->warming;
    }

    public function get_status() {
        return [ 'ready' => true ];
    }
}

final class Directorist_Page_Cache_Built_In_Provider_Test extends WP_UnitTestCase {
    public function test_healthy_enabled_provider_exposes_safe_capabilities() {
        $provider = $this->provider( new Directorist_Page_Cache_Built_In_Test_Engine() );

        $this->assertSame( 'directorist-cache', $provider->get_id() );
        $this->assertTrue( $provider->is_available() );
        $this->assertTrue( $provider->supports( 'purge_url' ) );
        $this->assertTrue( $provider->supports( 'purge_dependencies' ) );
        $this->assertTrue( $provider->supports( 'purge_generations' ) );
        $this->assertTrue( $provider->supports( 'purge_site' ) );
        $this->assertTrue( $provider->supports( 'purge_entries' ) );
        $this->assertTrue( $provider->supports( 'warm_urls' ) );
    }

    public function test_provider_delegates_without_transforming_payload() {
        $engine   = new Directorist_Page_Cache_Built_In_Test_Engine();
        $provider = $this->provider( $engine );
        $plan     = [ 'site_id' => 1, 'generations' => [ 'directorist:1:site' ] ];
        $urls     = [ 'https://example.org/directory/' ];

        $this->assertSame( 'engine_invalidated', $provider->invalidate( $plan )['code'] );
        $this->assertSame( 'engine_warmed', $provider->warm( $urls )['code'] );
        $this->assertSame( [ $plan ], $engine->invalidations );
        $this->assertSame( [ $urls ], $engine->warms );
    }

    public function test_missing_unhealthy_disabled_or_throwing_engine_fails_open() {
        $missing   = $this->provider( null );
        $unhealthy = $this->provider( new Directorist_Page_Cache_Built_In_Test_Engine(), false );
        $disabled  = $this->provider( new Directorist_Page_Cache_Built_In_Test_Engine(), true, false );
        $throwing  = new Provider(
            static function () {
                throw new RuntimeException( 'resolver failed' );
            },
            static function () {
                return true;
            },
            static function () {
                return true;
            }
        );

        foreach ( [ $missing, $unhealthy, $disabled, $throwing ] as $provider ) {
            $this->assertFalse( $provider->is_available() );
            $this->assertSame( 'engine_unavailable', $provider->invalidate( [] )['code'] );
        }

        $this->assertSame( 'integration_disabled', $disabled->get_status()['code'] );
    }

    public function test_unimplemented_warming_is_not_advertised_or_dispatched() {
        $engine          = new Directorist_Page_Cache_Built_In_Test_Engine();
        $engine->warming = false;
        $provider        = $this->provider( $engine );

        $this->assertTrue( $provider->is_available() );
        $this->assertFalse( $provider->supports( 'warm_urls' ) );
        $this->assertSame( 'capability_unavailable', $provider->warm( [ 'https://example.org/directory/' ] )['code'] );
        $this->assertSame( [], $engine->warms );
    }

    public function test_default_provider_is_dormant_without_proven_lifecycle_health() {
        $provider = new Provider();

        $this->assertFalse( $provider->is_available() );
        $this->assertSame( 'engine_unavailable', $provider->get_status()['code'] );
    }

    public function test_successful_invalidation_schedules_cleanup_and_status_reports_bounded_inventory() {
        $engine    = new Directorist_Page_Cache_Built_In_Test_Engine();
        $scheduled = 0;
        $provider  = new Provider(
            static function () use ( $engine ) {
                return $engine;
            },
            '__return_true',
            '__return_true',
            static function () use ( &$scheduled ) {
                ++$scheduled;
            },
            static function () {
                return [ 'success' => true, 'entries' => 12, 'bytes' => 4096 ];
            }
        );

        $provider->invalidate( [ 'site_id' => 1 ] );
        $status    = $provider->get_status();
        $inventory = $provider->get_inventory();

        $this->assertSame( 1, $scheduled );
        $this->assertArrayNotHasKey( 'inventory', $status );
        $this->assertSame( 12, $inventory['entries'] );
        $this->assertSame( 4096, $inventory['bytes'] );
    }

    public function test_inventory_arguments_are_forwarded_only_on_explicit_admin_reads() {
        $engine    = new Directorist_Page_Cache_Built_In_Test_Engine();
        $requested = [];
        $provider  = new Provider(
            static function () use ( $engine ) {
                return $engine;
            },
            '__return_true',
            '__return_true',
            null,
            static function ( $args = [] ) use ( &$requested ) {
                $requested[] = $args;

                return [ 'success' => true, 'items' => [], 'page' => isset( $args['page'] ) ? $args['page'] : 1 ];
            }
        );

        $provider->get_status();
        $inventory = $provider->get_inventory( [ 'page' => 3, 'route_type' => 'listing' ] );

        $this->assertSame( [ [ 'page' => 3, 'route_type' => 'listing' ] ], $requested );
        $this->assertSame( 3, $inventory['page'] );
    }

    private function provider( $engine, $healthy = true, $enabled = true ) {
        return new Provider(
            static function () use ( $engine ) {
                return $engine;
            },
            static function () use ( $healthy ) {
                return $healthy;
            },
            static function () use ( $enabled ) {
                return $enabled;
            }
        );
    }
}
