<?php
/**
 * WP Super Cache policy, path, and late-request compatibility locks.
 */

use Directorist\Cache\Cache_Provider;
use Directorist\Cache\WP_Super_Cache_Compatibility;

final class Directorist_Page_Cache_WPSC_Compatibility_Provider implements Cache_Provider {
    public $invalidations = [];

    public function get_id() {
        return 'wp-super-cache';
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

        return [ 'success' => true, 'code' => 'purged_site' ];
    }

    public function warm( array $urls ) {
        unset( $urls );

        return [ 'success' => true, 'code' => 'queued' ];
    }

    public function get_status() {
        return [ 'available' => true ];
    }
}

class Directorist_Page_Cache_WP_Super_Cache_Compatibility_Test extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( WP_Super_Cache_Compatibility::OPTION_NAME );
        parent::tearDown();
    }

    public function test_sync_preserves_user_configuration_and_adds_bounded_directorist_policy() {
        $config = [
            'wpsc_rejected_cookies'                  => [ '^custom_private_' ],
            'wpsc_cookies'                           => [ 'currency' ],
            'wpsc_plugins'                           => [ 'wp-content/custom-cache.php' ],
            'directorist_page_cache_wpsc_vary_rules' => [],
            'wp_cache_slash_check'                   => 0,
            'wp_cache_home_path'                     => '/old/',
            'wp_cache_mod_rewrite'                   => 0,
        ];
        $writes = [];
        $compat = $this->compatibility( $config, $writes );

        $result = $compat->synchronize();

        $this->assertTrue( $result['safe'] );
        $this->assertTrue( $result['changed'] );
        $this->assertTrue( $result['rebuild_required'] );
        $this->assertSame( 1, $config['wp_cache_slash_check'] );
        $this->assertSame( '/subdirectory/', $config['wp_cache_home_path'] );
        $this->assertContains( '^custom_private_', $config['wpsc_rejected_cookies'] );
        $this->assertCount( 2, $config['wpsc_rejected_cookies'] );
        $this->assertStringContainsString( 'atbdlc__selected_listings_id', $config['wpsc_rejected_cookies'][1] );
        $this->assertStringContainsString( '(?#directorist-page-cache)', $config['wpsc_rejected_cookies'][1] );
        $this->assertSame(
            [
                'currency',
                'PHPSESSID',
                'atbdlc__selected_listings_id',
                'wordpress_logged_in_',
                'comment_author_',
                'pll_language',
                'wp-wpml_current_language',
            ],
            $config['wpsc_cookies']
        );
        $this->assertSame( [ 'wp-content/custom-cache.php', 'wp-content/plugins/directorist/includes/cache/wp-super-cache-early-guard.php' ], $config['wpsc_plugins'] );
        $this->assertSame(
            [
                'wp-wpml_current_language' => [ 'pattern' => '^[a-z]+$', 'max_length' => 8 ],
                'pll_language'             => [ 'pattern' => '^[a-z]+$', 'max_length' => 8 ],
            ],
            $config['directorist_page_cache_wpsc_vary_rules']
        );
        $this->assertSame(
            [
                'wpsc_rejected_cookies',
                'wpsc_cookies',
                'wpsc_plugins',
                'directorist_page_cache_wpsc_vary_rules',
                'wp_cache_slash_check',
                'wp_cache_home_path',
            ],
            array_keys( $writes )
        );

        $writes = [];
        $second = $compat->synchronize();

        $this->assertFalse( $second['changed'] );
        $this->assertTrue( $second['rebuild_required'] );
        $this->assertSame( [], $writes );
    }

    public function test_activate_purges_and_warms_once_after_initial_or_changed_configuration() {
        $config   = $this->ready_config();
        $writes   = [];
        $warms    = [];
        $provider = new Directorist_Page_Cache_WPSC_Compatibility_Provider();
        $compat   = $this->compatibility(
            $config,
            $writes,
            [
                'warm_after_repair' => static function ( $selected, $purge, $plan ) use ( &$warms, $provider ) {
                    $warms[] = [ $selected, $purge, $plan ];
                    PHPUnit\Framework\Assert::assertSame( $provider, $selected );

                    return [ 'success' => true, 'code' => 'queued' ];
                },
            ]
        );

        $first  = $compat->activate( $provider );
        $second = $compat->activate( $provider );

        $this->assertTrue( $first['success'] );
        $this->assertSame( 'configuration_rebuilt', $first['code'] );
        $this->assertSame( 'configuration_ready', $second['code'] );
        $this->assertCount( 1, $provider->invalidations );
        $this->assertTrue( $provider->invalidations[0]['conservative'] );
        $this->assertCount( 1, $warms );
        $this->assertSame( [], $writes );
    }

    public function test_failed_provider_configuration_is_never_reported_as_safe_or_rebuilt() {
        $config = $this->ready_config();
        unset( $config['wp_cache_slash_check'] );
        $writes = [];
        $compat = $this->compatibility(
            $config,
            $writes,
            [
                'write_config' => static function ( $name, $value ) use ( &$writes ) {
                    $writes[ $name ] = $value;

                    return false;
                },
            ]
        );

        $result = $compat->activate( new Directorist_Page_Cache_WPSC_Compatibility_Provider() );
        $status = WP_Super_Cache_Compatibility::current();

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'configuration_write_failed', $result['code'] );
        $this->assertSame( 'needs-attention', $status['state'] );
        $this->assertFalse( $status['safe'] );
    }

    public function test_expert_mode_refreshes_rewrite_rules_and_fails_closed_when_refresh_fails() {
        $config                         = $this->ready_config();
        $config['wp_cache_mod_rewrite'] = 1;
        $writes                         = [];
        $refreshes                      = 0;
        $compat                         = $this->compatibility(
            $config,
            $writes,
            [
                'refresh_rewrite' => static function () use ( &$refreshes ) {
                    ++$refreshes;

                    return false;
                },
            ]
        );

        $result = $compat->synchronize();

        $this->assertFalse( $result['safe'] );
        $this->assertSame( 'rewrite_refresh_failed', $result['code'] );
        $this->assertSame( 1, $refreshes );
    }

    public function test_guard_leaves_other_pages_alone_and_denies_private_or_ineligible_directorist_routes() {
        $config   = $this->ready_config();
        $writes   = [];
        $route    = 'other';
        $begin    = [ 'eligible' => true ];
        $began    = 0;
        $denied   = [];
        $finished = 0;
        $compat   = $this->compatibility(
            $config,
            $writes,
            [
                'route_probe'    => static function () use ( &$route ) {
                    return $route;
                },
                'begin_capture'  => static function () use ( &$begin, &$began ) {
                    ++$began;

                    return $begin;
                },
                'finish_capture' => static function () use ( &$finished ) {
                    ++$finished;

                    return [ 'eligible' => false, 'reason' => 'private_render' ];
                },
                'deny_cache'     => static function ( $reason ) use ( &$denied ) {
                    $denied[] = $reason;

                    return true;
                },
            ]
        );

        $this->assertSame( 'unrelated_route', $compat->guard_request()['code'] );
        $this->assertSame( 0, $began );

        $route = 'private';
        $this->assertSame( 'private_route', $compat->guard_request()['code'] );

        $route = 'public';
        $begin = [ 'eligible' => false, 'reason' => 'rejected_cookie' ];
        $this->assertSame( 'request_bypassed', $compat->guard_request()['code'] );

        $begin = [ 'eligible' => true, 'reason' => 'eligible' ];
        $this->assertSame( 'request_eligible', $compat->guard_request()['code'] );
        $this->assertSame( 'render_bypassed', $compat->finish_request()['code'] );
        $this->assertSame( 1, $finished );
        $this->assertSame( [ 'private_route', 'rejected_cookie', 'private_render' ], $denied );
    }

    public function test_deactivation_removes_only_directorist_managed_configuration() {
        $config                            = $this->ready_config();
        $config['wpsc_rejected_cookies'][] = '^custom_private_';
        $config['wpsc_cookies'][]          = 'custom_currency';
        $config['wpsc_plugins'][]          = 'wp-content/custom-cache.php';
        $writes                            = [];
        $compat                            = $this->compatibility( $config, $writes );
        $this->assertTrue( $compat->synchronize()['success'] );

        $result = $compat->deactivate();

        $this->assertTrue( $result['success'] );
        $this->assertSame( [ '^custom_private_' ], $config['wpsc_rejected_cookies'] );
        $this->assertSame( [ 'custom_currency' ], $config['wpsc_cookies'] );
        $this->assertSame( [ 'wp-content/custom-cache.php' ], $config['wpsc_plugins'] );
        $this->assertSame( [], $config['directorist_page_cache_wpsc_vary_rules'] );
        $this->assertSame( 1, $config['wp_cache_slash_check'] );
        $this->assertSame( '/subdirectory/', $config['wp_cache_home_path'] );
        $this->assertSame( [], WP_Super_Cache_Compatibility::current() );
    }

    public function test_deactivation_explicitly_clears_owned_vary_rules_when_runtime_reports_empty() {
        $config = $this->ready_config();
        $writes = [];
        $compat = $this->compatibility( $config, $writes );

        $this->assertTrue( $compat->synchronize()['success'] );

        $config['directorist_page_cache_wpsc_vary_rules'] = [];
        $writes                                           = [];

        $this->assertTrue( $compat->deactivate()['success'] );
        $this->assertArrayHasKey( 'directorist_page_cache_wpsc_vary_rules', $writes );
        $this->assertSame( [], $writes['directorist_page_cache_wpsc_vary_rules'] );
    }

    private function compatibility( array &$config, array &$writes, array $overrides = [] ) {
        $runtime = [
            'read_config'         => static function () use ( &$config ) {
                return $config;
            },
            'write_config'        => static function ( $name, $value ) use ( &$config, &$writes ) {
                $writes[ $name ] = $value;
                $config[ $name ] = $value;

                return true;
            },
            'cookie_policy'       => static function () {
                return [
                    'reject_exact'    => [ 'PHPSESSID', 'atbdlc__selected_listings_id' ],
                    'reject_prefixes' => [ 'wordpress_logged_in_', 'comment_author_' ],
                    'vary'            => [
                        'wp-wpml_current_language' => [ 'pattern' => '^[a-z]+$', 'max_length' => 8 ],
                        'pll_language'             => [ 'pattern' => '^[a-z]+$', 'max_length' => 8 ],
                    ],
                ];
            },
            'permalink_structure' => static function () {
                return '/%postname%/';
            },
            'home_path'           => static function () {
                return '/subdirectory/';
            },
            'clock'               => static function () {
                return 1000;
            },
            'plugin_path'         => static function () {
                return 'wp-content/plugins/directorist/includes/cache/wp-super-cache-early-guard.php';
            },
            'refresh_rewrite'     => static function () {
                return true;
            },
        ];

        return new WP_Super_Cache_Compatibility( array_merge( $runtime, $overrides ) );
    }

    private function ready_config() {
        return [
            'wpsc_rejected_cookies'                  => [ '^(?:PHPSESSID|atbdlc__selected_listings_id|wordpress_logged_in_.*|comment_author_.*)(?#directorist-page-cache)$' ],
            'wpsc_cookies'                           => [ 'PHPSESSID', 'atbdlc__selected_listings_id', 'wordpress_logged_in_', 'comment_author_', 'pll_language', 'wp-wpml_current_language' ],
            'wpsc_plugins'                           => [ 'wp-content/plugins/directorist/includes/cache/wp-super-cache-early-guard.php' ],
            'directorist_page_cache_wpsc_vary_rules' => [
                'wp-wpml_current_language' => [ 'pattern' => '^[a-z]+$', 'max_length' => 8 ],
                'pll_language'             => [ 'pattern' => '^[a-z]+$', 'max_length' => 8 ],
            ],
            'wp_cache_slash_check'                   => 1,
            'wp_cache_home_path'                     => '/subdirectory/',
            'wp_cache_mod_rewrite'                   => 0,
        ];
    }
}
