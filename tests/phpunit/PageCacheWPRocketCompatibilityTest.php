<?php
/**
 * WP Rocket request-policy and lifecycle compatibility locks.
 */

use Directorist\Cache\Cache_Provider;
use Directorist\Cache\WP_Rocket_Compatibility;
use Directorist\Cache\WP_Rocket_Early_Guard;

final class Directorist_Page_Cache_WP_Rocket_Compatibility_Provider implements Cache_Provider {
    public $invalidations = [];

    public $succeed = true;

    public function get_id() {
        return 'wp-rocket';
    }

    public function is_available() {
        return true;
    }

    public function get_capabilities() {
        return [ 'purge_site' ];
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

        return [ 'success' => true, 'code' => 'unsupported' ];
    }

    public function get_status() {
        return [ 'available' => true ];
    }
}

class Directorist_Page_Cache_WP_Rocket_Compatibility_Test extends WP_UnitTestCase {
    protected function tearDown(): void {
        WP_Rocket_Compatibility::reset();
        parent::tearDown();
    }

    public function test_activation_preserves_provider_rules_and_rebuilds_once_per_policy() {
        $refresh  = 0;
        $provider = new Directorist_Page_Cache_WP_Rocket_Compatibility_Provider();
        $compat   = $this->compatibility( $provider, $refresh );

        $first  = $compat->activate( $provider );
        $second = $compat->activate( $provider );

        $this->assertSame( 'configuration_rebuilt', $first['code'] );
        $this->assertSame( 'configuration_ready', $second['code'] );
        $this->assertSame( [ 'customer_arg', 'custom_field', 'directory_type', 'q' ], $compat->query_strings( [ 'customer_arg', 'q' ] ) );
        $this->assertSame( [ 'customer_cookie', 'pll_language', 'wp-wpml_current_language' ], $compat->dynamic_cookies( [ 'customer_cookie', 'pll_language' ] ) );
        $reject = $compat->reject_cookies( [ 'customer_session' ] );
        $this->assertContains( 'customer_session', $reject );
        $this->assertContains( '(?:^|;\\s*)PHPSESSID(?:=|;|$)', $reject );
        $this->assertContains( '(?:^|;\\s*)wordpress_logged_in_', $reject );
        $this->assertSame( 1, $refresh );
        $this->assertCount( 1, $provider->invalidations );
        $this->assertTrue( $provider->invalidations[0]['conservative'] );
        $this->assertSame( WP_Rocket_Compatibility::REQUEST_POLICY_VERSION, WP_Rocket_Compatibility::current()['policy_version'] );
    }

    public function test_generated_dropin_guard_contains_only_bounded_policy_and_preserves_provider_content() {
        $provider = new Directorist_Page_Cache_WP_Rocket_Compatibility_Provider();
        $refresh  = 0;
        $compat   = $this->compatibility( $provider, $refresh );
        $compat->activate( $provider );

        $content = $compat->inject_early_guard( "<?php\n// WP Rocket original\n" );

        $this->assertStringContainsString( 'WP Rocket original', $content );
        $this->assertStringContainsString( WP_Rocket_Compatibility::EARLY_GUARD_MARKER, $content );
        $this->assertStringContainsString( 'class-wp-rocket-early-guard.php', $content );
        $this->assertStringContainsString( "'custom_field'", $content );
        $this->assertStringContainsString( "'pll_language'", $content );
        $this->assertSame( 1, substr_count( $content, WP_Rocket_Compatibility::EARLY_GUARD_MARKER ) );
    }

    public function test_htaccess_guard_preserves_provider_rules_and_forces_sensitive_requests_through_php() {
        $provider = new Directorist_Page_Cache_WP_Rocket_Compatibility_Provider();
        $refresh  = 0;
        $compat   = $this->compatibility( $provider, $refresh );
        $rules    = "RewriteCond %{REQUEST_METHOD} GET\nRewriteCond %{QUERY_STRING} =\"\"\nRewriteRule .* cache.html [L]\n";
        $guarded  = $compat->htaccess_rules( $rules );
        $guarded  = $compat->htaccess_rules( $guarded );

        $this->assertStringContainsString( 'RewriteRule .* cache.html [L]', $guarded );
        $this->assertStringContainsString( 'RewriteCond %{HTTP:Authorization} =""', $guarded );
        $this->assertStringContainsString( 'pll_language|wp\-wpml_current_language', $guarded );
        $this->assertSame( 1, substr_count( $guarded, WP_Rocket_Compatibility::HTACCESS_GUARD_MARKER ) );
    }

    public function test_provider_cookie_regex_keeps_wordpress_test_cookie_public() {
        $refresh = 0;
        $compat  = $this->compatibility( new Directorist_Page_Cache_WP_Rocket_Compatibility_Provider(), $refresh );
        $pattern = '#' . implode( '|', $compat->reject_cookies( [] ) ) . '#';

        $this->assertSame( 0, preg_match( $pattern, 'wordpress_test_cookie' ) );
        $this->assertSame( 1, preg_match( $pattern, 'wordpress_logged_in_hash' ) );
        $this->assertSame( 1, preg_match( $pattern, 'other=1; wordpress_sec_hash=value' ) );
    }

    public function test_early_guard_denies_private_inputs_without_blocking_provider_owned_bypasses() {
        $policy = [
            'query_arguments' => [ 'directory_type', 'q' ],
            'ignore_exact'    => [ 'wordpress_test_cookie' ],
            'ignore_prefixes' => [],
            'reject_exact'    => [ 'PHPSESSID' ],
            'reject_prefixes' => [ 'wordpress_' ],
            'vary'            => [
                'pll_language' => [ 'pattern' => '^[a-z]{2}$', 'max_length' => 2 ],
            ],
        ];
        $server = [ 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/all-listings/', 'QUERY_STRING' => '' ];

        $this->assertFalse( WP_Rocket_Early_Guard::should_bypass( $server, [], [], $policy ) );
        $this->assertFalse( WP_Rocket_Early_Guard::should_bypass( $server, [ 'unrelated' => '1' ], [], $policy ) );
        $this->assertFalse( WP_Rocket_Early_Guard::should_bypass( $server, [ 'q' => 'hotel' ], [ 'pll_language' => 'en' ], $policy ) );
        $this->assertTrue( WP_Rocket_Early_Guard::should_bypass( array_merge( $server, [ 'HTTP_AUTHORIZATION' => 'Bearer private' ] ), [], [], $policy ) );
        $this->assertTrue( WP_Rocket_Early_Guard::should_bypass( array_merge( $server, [ 'PHP_AUTH_USER' => 'private' ] ), [], [], $policy ) );
        $this->assertTrue( WP_Rocket_Early_Guard::should_bypass( $server, [ 'q' => 'hotel', 'private_token' => 'secret' ], [], $policy ) );
        $this->assertTrue( WP_Rocket_Early_Guard::should_bypass( $server, [ '_wpnonce' => 'private' ], [], $policy ) );
        $this->assertTrue( WP_Rocket_Early_Guard::should_bypass( $server, [], [ 'PHPSESSID' => 'private' ], $policy ) );
        $this->assertFalse( WP_Rocket_Early_Guard::should_bypass( $server, [], [ 'wordpress_test_cookie' => 'WP Cookie check' ], $policy ) );
        $this->assertTrue( WP_Rocket_Early_Guard::should_bypass( $server, [], [ 'wordpress_logged_in_hash' => 'private' ], $policy ) );
        $this->assertTrue( WP_Rocket_Early_Guard::should_bypass( $server, [], [ 'pll_language' => str_repeat( 'x', 20 ) ], $policy ) );
        $this->assertTrue( WP_Rocket_Early_Guard::should_bypass( array_merge( $server, [ 'REQUEST_METHOD' => 'POST' ] ), [], [], $policy ) );
    }

    public function test_late_guard_uses_directorist_route_and_render_eligibility() {
        $route    = 'public';
        $eligible = true;
        $denied   = [];
        $compat   = new WP_Rocket_Compatibility(
            [
                'query_arguments' => static function () {
                    return [ 'q' ];
                },
                'route_probe'     => static function () use ( &$route ) {
                    return $route;
                },
                'begin_capture'   => static function () use ( &$eligible ) {
                    return [ 'eligible' => $eligible, 'reason' => $eligible ? 'eligible' : 'unsupported_query' ];
                },
                'finish_capture'  => static function () use ( &$eligible ) {
                    return [ 'eligible' => $eligible, 'reason' => $eligible ? 'eligible' : 'private_render' ];
                },
                'deny_cache'      => static function ( $reason ) use ( &$denied ) {
                    $denied[] = $reason;

                    return true;
                },
            ]
        );

        $this->assertSame( 'request_eligible', $compat->guard_request()['code'] );
        $eligible = false;
        $this->assertSame( 'render_bypassed', $compat->finish_request()['code'] );

        $route = 'private';
        $this->assertSame( 'private_route', $compat->guard_request()['code'] );
        $this->assertSame( [ 'private_render', 'private_route' ], $denied );
    }

    public function test_failed_refresh_never_purges_or_marks_policy_current() {
        $provider = new Directorist_Page_Cache_WP_Rocket_Compatibility_Provider();
        $refresh  = 0;
        $compat   = $this->compatibility( $provider, $refresh, false );
        $result   = $compat->activate( $provider );

        $this->assertSame( 'configuration_refresh_failed', $result['code'] );
        $this->assertSame( 1, $refresh );
        $this->assertCount( 0, $provider->invalidations );
        $this->assertSame( [], WP_Rocket_Compatibility::current() );
    }

    public function test_stored_policy_repairs_generated_configuration_drift() {
        $provider = new Directorist_Page_Cache_WP_Rocket_Compatibility_Provider();
        $refresh  = 0;
        $modes    = [];
        $current  = true;
        $compat   = $this->compatibility( $provider, $refresh, true, $modes, $current );

        $this->assertSame( 'configuration_rebuilt', $compat->activate( $provider )['code'] );
        $current = false;
        $this->assertSame( 'configuration_rebuilt', $compat->activate( $provider )['code'] );
        $this->assertSame( 2, $refresh );
        $this->assertCount( 2, $provider->invalidations );
    }

    public function test_cleanup_removes_only_directorist_filters_and_purges_stale_variants() {
        $provider = new Directorist_Page_Cache_WP_Rocket_Compatibility_Provider();
        $refresh  = 0;
        $modes    = [];
        $compat   = $this->compatibility( $provider, $refresh, true, $modes );
        $compat->activate( $provider );

        $result = $compat->deactivate();

        $this->assertSame( 'configuration_removed', $result['code'] );
        $this->assertSame( 2, $refresh );
        $this->assertSame( [ true, false ], $modes );
        $this->assertCount( 2, $provider->invalidations );
        $this->assertSame( [], WP_Rocket_Compatibility::current() );
    }

    private function compatibility( Cache_Provider $provider, &$refresh, $refresh_success = true, &$refresh_modes = null, &$configuration_current = null ) {
        return new WP_Rocket_Compatibility(
            [
                'query_arguments'       => static function () {
                    return [ 'q', 'directory_type', 'custom_field' ];
                },
                'cookie_policy'         => static function () {
                    return [
                        'ignore_exact'    => [ 'wordpress_test_cookie' ],
                        'ignore_prefixes' => [],
                        'reject_exact'    => [ 'PHPSESSID' ],
                        'reject_prefixes' => [ 'wordpress_', 'wordpress_logged_in_' ],
                        'vary'            => [
                            'pll_language'             => [ 'pattern' => '^[a-z]+$', 'max_length' => 8 ],
                            'wp-wpml_current_language' => [ 'pattern' => '^[a-z]+$', 'max_length' => 8 ],
                        ],
                    ];
                },
                'refresh_config'        => static function ( $expect_guard ) use ( &$refresh, $refresh_success, &$refresh_modes ) {
                    ++$refresh;

                    if ( is_array( $refresh_modes ) ) {
                        $refresh_modes[] = (bool) $expect_guard;
                    }

                    return (bool) $refresh_success;
                },

                'configuration_current' => static function () use ( &$configuration_current ) {
                    return null === $configuration_current ? true : (bool) $configuration_current;
                },

                'provider_resolver'     => static function () use ( $provider ) {
                    return $provider;
                },
                'guard_path'            => static function () {
                    return '/plugin/includes/cache/class-wp-rocket-early-guard.php';
                },
                'clock'                 => static function () {
                    return 1000;
                },
                'warm_after_repair'     => static function () {
                    return [ 'success' => true, 'code' => 'unsupported' ];
                },
            ]
        );
    }
}
