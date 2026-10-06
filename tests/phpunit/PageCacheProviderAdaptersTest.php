<?php
/**
 * Guarded third-party cache adapter tests.
 */

use Directorist\Cache\Cache_Enabler_Provider;
use Directorist\Cache\LiteSpeed_Cache_Provider;
use Directorist\Cache\Plugin_Version;
use Directorist\Cache\Provider_Capabilities;
use Directorist\Cache\WP_Fastest_Cache_Provider;
use Directorist\Cache\WP_Rocket_Provider;
use Directorist\Cache\WP_Super_Cache_Provider;

class Directorist_Page_Cache_Provider_Adapters_Test extends WP_UnitTestCase {
    public function test_wp_super_cache_purges_exact_urls_when_representable() {
        $calls    = [];
        $provider = new WP_Super_Cache_Provider(
            [
                'delete_url' => static function ( $url ) use ( &$calls ) {
                    $calls[] = [ 'url', $url ];

                    return true;
                },
                'purge_site' => static function () use ( &$calls ) {
                    $calls[] = [ 'site' ];

                    return true;
                },
                'version'    => '3.1.1',
            ]
        );

        $result = $provider->invalidate( $this->plan( [ 'https://example.org/directory/one/' ] ) );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'purged_urls', $result['code'] );
        $this->assertSame( [ [ 'url', 'https://example.org/directory/one/' ] ], $calls );
    }

    public function test_wp_super_cache_query_root_and_generation_degrade_to_site_purge() {
        $calls    = [];
        $provider = new WP_Super_Cache_Provider(
            [
                'delete_url' => static function ( $url ) use ( &$calls ) {
                    $calls[] = [ 'url', $url ];

                    return true;
                },
                'purge_site' => static function ( $site_id ) use ( &$calls ) {
                    $calls[] = [ 'site', $site_id ];

                    return true;
                },
            ]
        );

        $query_result = $provider->invalidate( $this->plan( [ 'https://example.org/search/?q=one' ] ) );
        $root_result  = $provider->invalidate( $this->plan( [ home_url( '/' ) ] ) );
        $gen_result   = $provider->invalidate( $this->plan( [], [ 'directorist:1:collection:listings' ] ) );

        $this->assertSame( 'purged_site', $query_result['code'] );
        $this->assertSame( 'purged_site', $root_result['code'] );
        $this->assertSame( 'purged_site', $gen_result['code'] );
        $this->assertSame( [ [ 'site', 1 ], [ 'site', 1 ], [ 'site', 1 ] ], $calls );
    }

    public function test_cache_enabler_batches_exact_urls_and_degrades_generation() {
        $calls    = [];
        $provider = new Cache_Enabler_Provider(
            [
                'delete_url' => static function ( $url ) use ( &$calls ) {
                    $calls[] = [ 'url', $url ];
                },
                'purge_site' => static function ( $site_id ) use ( &$calls ) {
                    $calls[] = [ 'site', $site_id ];
                },
            ]
        );

        $exact = $provider->invalidate( $this->plan( [ 'https://example.org/a/', 'https://example.org/b/' ] ) );
        $broad = $provider->invalidate( $this->plan( [], [ 'directorist:1:settings' ] ) );

        $this->assertSame( 'purged_urls', $exact['code'] );
        $this->assertSame( 'purged_site', $broad['code'] );
        $this->assertSame(
            [
                [ 'url', 'https://example.org/a/' ],
                [ 'url', 'https://example.org/b/' ],
                [ 'site', 1 ],
            ],
            $calls
        );
    }

    public function test_wp_fastest_cache_advertises_only_proven_site_purge() {
        $calls    = 0;
        $provider = new WP_Fastest_Cache_Provider(
            [
                'purge_site' => static function () use ( &$calls ) {
                    ++$calls;
                },
                'version'    => '1.5.1',
            ]
        );

        $result = $provider->invalidate( $this->plan( [ 'https://example.org/a/' ] ) );

        $this->assertTrue( $provider->supports( Provider_Capabilities::PURGE_SITE ) );
        $this->assertFalse( $provider->supports( Provider_Capabilities::PURGE_URL ) );
        $this->assertSame( 'purged_site', $result['code'] );
        $this->assertSame( 1, $calls );
    }

    public function test_wp_fastest_cache_disabled_hook_is_unavailable() {
        $provider = new WP_Fastest_Cache_Provider( [ 'disabled' => true ] );

        $this->assertFalse( $provider->is_available() );
        $this->assertSame( 'provider_unavailable', $provider->invalidate( $this->plan( [ 'https://example.org/a/' ] ) )['code'] );
    }

    public function test_installed_but_disabled_cache_engines_are_unavailable() {
        $operation = static function () {};

        $wpsc          = new WP_Super_Cache_Provider( [ 'delete_url' => $operation, 'enabled' => false ] );
        $cache_enabler = new Cache_Enabler_Provider( [ 'delete_url' => $operation, 'enabled' => false ] );
        $wpfc          = new WP_Fastest_Cache_Provider( [ 'purge_site' => $operation, 'enabled' => false ] );

        $this->assertFalse( $wpsc->is_available() );
        $this->assertFalse( $cache_enabler->is_available() );
        $this->assertFalse( $wpfc->is_available() );
    }

    public function test_wp_super_cache_runtime_requires_its_master_cache_switch() {
        $previous_cache_enabled       = $GLOBALS['cache_enabled'] ?? null;
        $previous_super_cache_enabled = $GLOBALS['super_cache_enabled'] ?? null;
        $had_cache_enabled            = array_key_exists( 'cache_enabled', $GLOBALS );
        $had_super_cache_enabled      = array_key_exists( 'super_cache_enabled', $GLOBALS );

        try {
            $GLOBALS['cache_enabled']       = false;
            $GLOBALS['super_cache_enabled'] = true;

            $this->assertFalse( ( new WP_Super_Cache_Provider() )->is_available() );
        } finally {
            if ( $had_cache_enabled ) {
                $GLOBALS['cache_enabled'] = $previous_cache_enabled;
            } else {
                unset( $GLOBALS['cache_enabled'] );
            }

            if ( $had_super_cache_enabled ) {
                $GLOBALS['super_cache_enabled'] = $previous_super_cache_enabled;
            } else {
                unset( $GLOBALS['super_cache_enabled'] );
            }
        }
    }

    public function test_wp_rocket_uses_batch_api_and_domain_fallback() {
        $calls    = [];
        $provider = new WP_Rocket_Provider(
            [
                'delete_urls' => static function ( array $urls ) use ( &$calls ) {
                    $calls[] = [ 'urls', $urls ];
                },
                'purge_site'  => static function () use ( &$calls ) {
                    $calls[] = [ 'site' ];
                },
                'version'     => 'test',
            ]
        );

        $provider->invalidate( $this->plan( [ 'https://example.org/a/', 'https://example.org/b/' ] ) );
        $provider->invalidate( $this->plan( [], [ 'directorist:1:template' ] ) );

        $this->assertSame(
            [
                [ 'urls', [ 'https://example.org/a/', 'https://example.org/b/' ] ],
                [ 'site' ],
            ],
            $calls
        );
    }

    public function test_litespeed_uses_documented_hooks_through_guarded_operations() {
        $calls    = [];
        $provider = new LiteSpeed_Cache_Provider(
            [
                'delete_url' => static function ( $url ) use ( &$calls ) {
                    $calls[] = [ 'url', $url ];
                },
                'purge_site' => static function () use ( &$calls ) {
                    $calls[] = [ 'site' ];
                },
                'version'    => '7.9',
                'server'     => true,
            ]
        );

        $provider->invalidate( $this->plan( [ 'https://example.org/a/' ] ) );
        $provider->invalidate( $this->plan( [], [ 'directorist:1:site' ] ) );

        $this->assertSame( [ [ 'url', 'https://example.org/a/' ], [ 'site' ] ], $calls );
    }

    public function test_litespeed_requires_a_real_cache_server_type_even_when_cache_flag_is_set() {
        $operation     = static function () {};
        $apache        = new LiteSpeed_Cache_Provider(
            [
                'purge_site'  => $operation,
                'server_type' => 'NONE',
                'cache_on'    => true,
            ]
        );
        $openlitespeed = new LiteSpeed_Cache_Provider(
            [
                'purge_site'  => $operation,
                'server_type' => 'LITESPEED_SERVER_OLS',
                'cache_on'    => true,
            ]
        );
        $disabled      = new LiteSpeed_Cache_Provider(
            [
                'purge_site'  => $operation,
                'server_type' => 'LITESPEED_SERVER_ENT',
                'cache_on'    => false,
            ]
        );

        $this->assertFalse( $apache->is_available() );
        $this->assertTrue( $openlitespeed->is_available() );
        $this->assertFalse( $disabled->is_available() );
    }

    public function test_provider_version_resolver_prefers_the_bounded_plugin_header() {
        $path = wp_tempnam( 'directorist-provider-version.php' );
        file_put_contents( $path, "<?php\n/**\n * Plugin Name: Cache Provider\n * Version: 3.1.3\n */\n" );

        $this->assertSame( '3.1.3', Plugin_Version::resolve( [ $path ], '1.12.1' ) );
        $this->assertSame( '1.12.1', Plugin_Version::resolve( [ '/missing/provider.php' ], '1.12.1' ) );
    }

    public function test_provider_version_resolver_does_not_scan_beyond_the_header_window() {
        $path = wp_tempnam( 'directorist-provider-version-bounded.php' );
        file_put_contents( $path, "<?php\n" . str_repeat( ' ', Plugin_Version::MAX_READ_BYTES ) . "\n * Version: 9.9.9\n" );

        $this->assertSame( 'unknown', Plugin_Version::resolve( [ $path ], 'unknown' ) );
    }

    public function test_operation_failure_and_exception_are_explicit_and_fail_open() {
        $failed = new Cache_Enabler_Provider(
            [
                'delete_url' => static function () {
                    return false;
                },
            ]
        );
        $thrown = new WP_Rocket_Provider(
            [
                'delete_urls' => static function () {
                    throw new RuntimeException( 'adapter failure' );
                },
            ]
        );

        $this->assertSame( 'provider_operation_failed', $failed->invalidate( $this->plan( [ 'https://example.org/a/' ] ) )['code'] );
        $this->assertSame( 'provider_exception', $thrown->invalidate( $this->plan( [ 'https://example.org/a/' ] ) )['code'] );
    }

    public function test_dependency_only_plan_degrades_to_site_and_empty_plan_is_noop() {
        $calls    = 0;
        $provider = new Cache_Enabler_Provider(
            [
                'delete_url' => static function () {},
                'purge_site' => static function () use ( &$calls ) {
                    ++$calls;
                },
            ]
        );

        $dependency = $provider->invalidate( $this->plan( [], [], [ 'directorist:1:listing:10' ] ) );
        $empty      = $provider->invalidate( $this->plan() );

        $this->assertSame( 'purged_site', $dependency['code'] );
        $this->assertSame( 'no_changes', $empty['code'] );
        $this->assertSame( 1, $calls );
    }

    public function test_warm_is_explicitly_unsupported_without_a_proven_api() {
        $provider = new Cache_Enabler_Provider( [ 'delete_url' => static function () {} ] );

        $this->assertSame( 'unsupported_warm', $provider->warm( [ 'https://example.org/a/' ] )['code'] );
    }

    public function test_external_provider_can_delegate_to_provider_neutral_warm_queue() {
        $calls    = [];
        $provider = new WP_Super_Cache_Provider(
            [
                'purge_site' => static function () {},
                'warm_urls'  => static function ( array $urls ) use ( &$calls ) {
                    $calls[] = $urls;

                    return [ 'success' => true, 'code' => 'queued' ];
                },
            ]
        );

        $result = $provider->warm( [ 'https://example.org/a/' ] );

        $this->assertTrue( $provider->supports( Provider_Capabilities::WARM_URLS ) );
        $this->assertTrue( $result['success'] );
        $this->assertSame( [ [ 'https://example.org/a/' ] ], $calls );
    }

    public function test_wp_super_cache_reports_only_stored_provider_wide_inventory() {
        $provider = new WP_Super_Cache_Provider(
            [
                'purge_site' => static function () {},
                'stats'      => [
                    'generated'  => 1000,
                    'supercache' => [ 'cached' => 8, 'expired' => 2, 'fsize' => 4096 ],
                    'wpcache'    => [ 'cached' => 3, 'expired' => 1, 'fsize' => 1024 ],
                ],
            ]
        );

        $inventory = $provider->get_status()['inventory'];

        $this->assertSame( 'provider_site', $inventory['scope'] );
        $this->assertSame( 11, $inventory['entries'] );
        $this->assertSame( 3, $inventory['orphans'] );
        $this->assertSame( 5120, $inventory['bytes'] );
        $this->assertSame( 1000, $inventory['generated_at'] );
    }

    private function plan( array $urls = [], array $generations = [], array $dependencies = [] ) {
        return [
            'site_id'      => 1,
            'urls'         => $urls,
            'dependencies' => $dependencies,
            'generations'  => $generations,
            'conservative' => false,
            'reason'       => '',
        ];
    }
}
