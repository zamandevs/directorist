<?php
/**
 * LiteSpeed request-policy and migration behavior locks.
 */

use Directorist\Cache\Cache_Provider;
use Directorist\Cache\Cookie_Policy;
use Directorist\Cache\LiteSpeed_Compatibility;

final class Directorist_Page_Cache_LiteSpeed_Compatibility_Provider implements Cache_Provider {
    public $invalidations = [];

    public $succeed = true;

    public function get_id() {
        return 'litespeed-cache';
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

        return [ 'success' => $this->succeed, 'code' => $this->succeed ? 'purged_site' : 'purge_failed' ];
    }

    public function warm( array $urls ) {
        unset( $urls );

        return [ 'success' => true, 'code' => 'queued' ];
    }

    public function get_status() {
        return [ 'available' => true ];
    }
}

class Directorist_Page_Cache_LiteSpeed_Compatibility_Test extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( 'directorist_page_cache_litespeed_compatibility_v1' );
        parent::tearDown();
    }

    public function test_html_guard_denies_private_and_rejected_directorist_routes_only() {
        $route  = 'unrelated';
        $denied = [];
        $compat = new LiteSpeed_Compatibility(
            [
                'route_probe' => static function () use ( &$route ) {
                    return $route;
                },
                'deny_cache'  => static function ( $reason ) use ( &$denied ) {
                    $denied[] = $reason;

                    return true;
                },
            ]
        );

        $this->assertSame( 'unrelated_route', $compat->guard_request()['code'] );

        $route = 'public';
        $this->assertSame( 'public_route', $compat->guard_request()['code'] );

        $route = 'private';
        $this->assertSame( 'private_route', $compat->guard_request()['code'] );

        $route = 'rejected';
        $this->assertSame( 'rejected_route', $compat->guard_request()['code'] );
        $this->assertSame( [ 'private_route', 'rejected_route' ], $denied );
    }

    public function test_rest_guard_denies_directorist_namespaces_without_changing_unrelated_rest_routes() {
        $denied = [];
        $compat = new LiteSpeed_Compatibility(
            [
                'deny_cache' => static function ( $reason ) use ( &$denied ) {
                    $denied[] = $reason;

                    return true;
                },
            ]
        );

        $result = new WP_REST_Response( [ 'ok' => true ] );

        $this->assertSame( $result, $compat->guard_rest_request( $result, null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) ) );
        $this->assertSame( $result, $compat->guard_rest_request( $result, null, new WP_REST_Request( 'GET', '/directorist/v1/listings' ) ) );
        $this->assertSame( $result, $compat->guard_rest_request( $result, null, new WP_REST_Request( 'GET', '/wp/v2/at_biz_dir/42' ) ) );
        $this->assertSame( [ 'directorist_rest_route', 'directorist_rest_route' ], $denied );
    }

    public function test_policy_activation_purges_and_preloads_once_per_version() {
        $provider = new Directorist_Page_Cache_LiteSpeed_Compatibility_Provider();
        $warms    = [];
        $refresh  = 0;
        $compat   = new LiteSpeed_Compatibility(
            [
                'clock'             => static function () {
                    return 1000;
                },
                'deny_cache'        => '__return_true',
                'refresh_vary'      => static function () use ( &$refresh ) {
                    ++$refresh;

                    return true;
                },
                'warm_after_repair' => static function ( $selected, $purge, $plan ) use ( &$warms, $provider ) {
                    $warms[] = [ $selected, $purge, $plan ];
                    PHPUnit\Framework\Assert::assertSame( $provider, $selected );

                    return [ 'success' => true, 'code' => 'queued' ];
                },
            ]
        );

        $first  = $compat->activate( $provider );
        $second = $compat->activate( $provider );

        $this->assertSame( 'policy_rebuilt', $first['code'] );
        $this->assertSame( 'policy_ready', $second['code'] );
        $this->assertCount( 1, $provider->invalidations );
        $this->assertTrue( $provider->invalidations[0]['conservative'] );
        $this->assertCount( 1, $warms );
        $this->assertSame( 1, $refresh );
        $this->assertSame( LiteSpeed_Compatibility::REQUEST_POLICY_VERSION, LiteSpeed_Compatibility::current()['policy_version'] );
    }

    public function test_failed_policy_purge_is_retried_and_never_marked_current() {
        $provider          = new Directorist_Page_Cache_LiteSpeed_Compatibility_Provider();
        $provider->succeed = false;
        $compat            = new LiteSpeed_Compatibility( [ 'deny_cache' => '__return_true', 'refresh_vary' => '__return_true' ] );

        $first  = $compat->activate( $provider );
        $second = $compat->activate( $provider );

        $this->assertSame( 'policy_purge_failed', $first['code'] );
        $this->assertSame( 'policy_purge_failed', $second['code'] );
        $this->assertCount( 2, $provider->invalidations );
        $this->assertSame( [], LiteSpeed_Compatibility::current() );
    }

    public function test_native_vary_filters_preserve_provider_cookies_and_add_bounded_language_names() {
        $compat = new LiteSpeed_Compatibility(
            [
                'cookie_policy' => static function () {
                    return Cookie_Policy::defaults();
                },
            ]
        );

        $this->assertSame(
            [ 'existing_cookie', 'pll_language', 'wp-wpml_current_language' ],
            $compat->vary_cookies( [ 'existing_cookie', 'pll_language' ] )
        );
    }

    public function test_vary_refresh_failure_is_retried_without_purging_or_marking_policy_current() {
        $provider = new Directorist_Page_Cache_LiteSpeed_Compatibility_Provider();
        $refresh  = 0;
        $compat   = new LiteSpeed_Compatibility(
            [
                'deny_cache'   => '__return_true',
                'refresh_vary' => static function () use ( &$refresh ) {
                    ++$refresh;

                    return false;
                },
            ]
        );

        $first  = $compat->activate( $provider );
        $second = $compat->activate( $provider );

        $this->assertSame( 'vary_refresh_failed', $first['code'] );
        $this->assertSame( 'vary_refresh_failed', $second['code'] );
        $this->assertSame( 2, $refresh );
        $this->assertCount( 0, $provider->invalidations );
        $this->assertSame( [], LiteSpeed_Compatibility::current() );
    }
}
