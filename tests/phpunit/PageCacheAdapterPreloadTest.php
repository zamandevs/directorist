<?php

use Directorist\Cache\Cache_Enabler_Provider;
use Directorist\Cache\LiteSpeed_Cache_Provider;
use Directorist\Cache\Provider_Capabilities;
use Directorist\Cache\WP_Fastest_Cache_Provider;
use Directorist\Cache\WP_Rocket_Provider;

final class Directorist_Page_Cache_Adapter_Preload_Test extends WP_UnitTestCase {
    public static function providers() {
        return [ [ Cache_Enabler_Provider::class ], [ LiteSpeed_Cache_Provider::class ], [ WP_Fastest_Cache_Provider::class ], [ WP_Rocket_Provider::class ] ];
    }

    /** @dataProvider providers */
    public function test_preload_delegates_to_callback_and_retains_unsupported_fallback( $class ) {
        $calls = [];
        $provider = new $class( [ 'server' => true, 'purge_site' => '__return_true', 'warm_urls' => static function ( $urls ) use ( &$calls ) {
            $calls[] = $urls;
            return [ 'success' => true, 'code' => 'warm_queued' ];
        } ] );
        $this->assertTrue( $provider->supports( Provider_Capabilities::WARM_URLS ) );
        $this->assertSame( 'warmed_urls', $provider->warm( [ home_url( '/listing/' ), home_url( '/listing/' ) ] )['code'] );
        $this->assertSame( [ [ home_url( '/listing/' ) ] ], $calls );
        $unsupported = new $class( [ 'server' => true, 'purge_site' => '__return_true', 'warm_urls' => 'not_a_callable' ] );
        $this->assertFalse( $unsupported->supports( Provider_Capabilities::WARM_URLS ) );
        $this->assertSame( 'unsupported_warm', $unsupported->warm( [ home_url( '/' ) ] )['code'] );
    }

    /** @dataProvider providers */
    public function test_dispatch_failure_exception_and_disabled_provider_are_honest( $class ) {
        $failure = new $class( [ 'server' => true, 'purge_site' => '__return_true', 'warm_urls' => static function () { return [ 'success' => false, 'code' => 'queue_write_failed' ]; } ] );
        $this->assertSame( 'queue_write_failed', $failure->warm( [ home_url( '/' ) ] )['code'] );
        $exception = new $class( [ 'server' => true, 'purge_site' => '__return_true', 'warm_urls' => static function () { throw new RuntimeException( 'queue' ); } ] );
        $this->assertFalse( $exception->warm( [ home_url( '/' ) ] )['success'] );
        $disabled = new $class( [ 'server' => true, 'purge_site' => '__return_true', 'disabled' => true, 'warm_urls' => static function () { throw new RuntimeException( 'must not run' ); } ] );
        $this->assertSame( 'provider_unavailable', $disabled->warm( [ home_url( '/' ) ] )['code'] );
    }
}
