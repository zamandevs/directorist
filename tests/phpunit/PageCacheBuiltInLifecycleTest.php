<?php
/**
 * Built-in/external provider lifecycle state behavior locks.
 */

use Directorist\Cache\Built_In\Atomic_Writer;
use Directorist\Cache\Built_In\Dropin_Installer;
use Directorist\Cache\Built_In\Lifecycle;
use Directorist\Cache\Built_In\Runtime_Manager;
use Directorist\Cache\Built_In\WP_Cache_Config;

final class Directorist_Page_Cache_Built_In_Lifecycle_Test extends WP_UnitTestCase {
    private $root;

    private $content_dir;

    private $plugin_dir;

    private $wp_config;

    private $states = [];

    private $generation_bumps = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->root        = sys_get_temp_dir() . '/directorist-built-in-lifecycle-' . bin2hex( random_bytes( 6 ) );
        $this->content_dir = $this->root . '/wp-content';
        $this->plugin_dir  = $this->content_dir . '/plugins/directorist';
        $this->wp_config   = $this->root . '/wp-config.php';

        mkdir( $this->plugin_dir . '/includes/cache/built-in', 0777, true );
        file_put_contents( $this->wp_config, "<?php\n// WordPress configuration.\n" );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
        parent::tearDown();
    }

    public function test_no_external_provider_activates_the_built_in_runtime_by_default() {
        $result = $this->lifecycle()->reconcile( 'activation' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'built_in', $result['state'] );
        $this->assertSame( 'directorist-cache', $result['provider'] );
        $this->assertFileExists( $this->content_dir . '/advanced-cache.php' );
        $this->assertFileExists( $this->content_dir . '/cache/directorist-page-cache/config.json' );
        $this->assertStringContainsString( "define( 'WP_CACHE', true );", file_get_contents( $this->wp_config ) );
        $this->assertSame( 1, $this->generation_bumps );
    }

    public function test_external_provider_wins_and_retires_only_the_owned_built_in_runtime() {
        $lifecycle = $this->lifecycle();
        $lifecycle->reconcile( 'activation' );

        $result = $this->lifecycle( [ 'code' => 'selected', 'provider' => 'wp-super-cache' ], 'directorist-cache' )->reconcile( 'external_activation' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'external', $result['state'] );
        $this->assertSame( 'wp-super-cache', $result['provider'] );
        $this->assertFileDoesNotExist( $this->content_dir . '/advanced-cache.php' );
        $this->assertFileDoesNotExist( $this->content_dir . '/cache/directorist-page-cache/config.json' );
        $this->assertStringNotContainsString( 'DIRECTORIST PAGE CACHE WP_CACHE', file_get_contents( $this->wp_config ) );
    }

    public function test_unknown_foreign_dropin_blocks_caching_without_mutation() {
        $path   = $this->content_dir . '/advanced-cache.php';
        $source = "<?php\n// foreign cache\n";
        file_put_contents( $path, $source );

        $result = $this->lifecycle( [ 'code' => 'none', 'provider' => '' ], 'unknown' )->reconcile( 'health' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'blocked', $result['state'] );
        $this->assertSame( 'unknown_dropin', $result['code'] );
        $this->assertSame( $source, file_get_contents( $path ) );
        $this->assertStringNotContainsString( 'WP_CACHE', file_get_contents( $this->wp_config ) );
    }

    public function test_multiple_external_providers_disable_owned_delivery_and_report_conflict() {
        $this->lifecycle()->reconcile( 'activation' );

        $result = $this->lifecycle(
            [ 'code' => 'multiple_providers', 'provider' => '', 'candidates' => [ 'wp-rocket', 'wp-super-cache' ] ],
            'directorist-cache'
        )->reconcile( 'health' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'blocked', $result['state'] );
        $this->assertSame( 'multiple_providers', $result['code'] );
        $this->assertFileDoesNotExist( $this->content_dir . '/advanced-cache.php' );
    }

    public function test_explicit_disable_invalidates_then_removes_only_owned_runtime() {
        $this->lifecycle()->reconcile( 'activation' );
        $before = $this->generation_bumps;

        $result = $this->lifecycle( [ 'code' => 'none', 'provider' => '' ], 'directorist-cache', false )->reconcile( 'setting' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'disabled', $result['state'] );
        $this->assertFileDoesNotExist( $this->content_dir . '/advanced-cache.php' );
        $this->assertSame( $before + 1, $this->generation_bumps );
    }

    public function test_failed_activation_reports_unavailable_and_leaves_no_partial_runtime() {
        file_put_contents( $this->content_dir . '/advanced-cache.php', "<?php\n// foreign cache\n" );

        $result = $this->lifecycle( [ 'code' => 'none', 'provider' => '' ], 'none' )->reconcile( 'activation' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'unavailable', $result['state'] );
        $this->assertSame( 'foreign_dropin', $result['code'] );
    }

    public function test_per_site_multisite_runtime_is_unavailable_without_touching_files() {
        $result = $this->lifecycle(
            [ 'code' => 'none', 'provider' => '' ],
            'none',
            true,
            [ 'is_multisite' => true, 'network_wide' => false ]
        )->reconcile( 'activation' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'unavailable', $result['state'] );
        $this->assertSame( 'network_activation_required', $result['code'] );
        $this->assertFileDoesNotExist( $this->content_dir . '/advanced-cache.php' );
    }

    public function test_pre_activation_handoff_applies_only_to_supported_external_cache_plugins() {
        $lifecycle = $this->lifecycle();
        $lifecycle->reconcile( 'activation' );

        $ignored = $lifecycle->prepare_external_activation( 'unrelated/plugin.php' );
        $this->assertSame( 'not_external_cache', $ignored['code'] );
        $this->assertFileExists( $this->content_dir . '/advanced-cache.php' );

        $prepared = $lifecycle->prepare_external_activation( 'wp-super-cache/wp-cache.php' );
        $this->assertTrue( $prepared['success'] );
        $this->assertSame( 'external_activation_prepared', $prepared['code'] );
        $this->assertFileDoesNotExist( $this->content_dir . '/advanced-cache.php' );
    }

    public function test_owned_runtime_refreshes_filtered_cookie_policy_and_generation() {
        $this->lifecycle()->reconcile( 'activation' );
        $before   = $this->generation_bumps;
        $callback = static function ( $policy ) {
            $policy['reject_prefixes'][] = 'private_extension_';

            return $policy;
        };
        add_filter( 'directorist_page_cache_cookie_policy', $callback );
        $result = $this->lifecycle( [ 'code' => 'none', 'provider' => '' ], 'directorist-cache' )->reconcile( 'plugin_lifecycle' );
        remove_filter( 'directorist_page_cache_cookie_policy', $callback );
        $config = json_decode( file_get_contents( $this->content_dir . '/cache/directorist-page-cache/config.json' ), true );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'built_in', $result['state'] );
        $this->assertContains( 'private_extension_', $config['cookie_policy']['reject_prefixes'] );
        $this->assertSame( $before + 1, $this->generation_bumps );
    }

    private function lifecycle( array $external = null, $owner = 'none', $enabled = true, array $overrides = [] ) {
        $external = null === $external ? [ 'code' => 'none', 'provider' => '', 'candidates' => [] ] : $external;
        $runtime  = new Runtime_Manager(
            new Dropin_Installer( $this->content_dir, $this->plugin_dir, new Atomic_Writer() ),
            new WP_Cache_Config( $this->wp_config, new Atomic_Writer() )
        );
        $options  = array_merge(
            [
                'external_probe'   => static function () use ( $external ) {
                    return $external;
                },
                'owner_resolver'   => static function () use ( $owner ) {
                    return $owner;
                },
                'enabled_resolver' => static function () use ( $enabled ) {
                    return $enabled;
                },
                'generation_bump'  => function () {
                    ++$this->generation_bumps;

                    return [ 'success' => true, 'code' => 'generations_bumped' ];
                },
                'state_writer'     => function ( array $state ) {
                    $this->states[] = $state;

                    return true;
                },
                'is_multisite'     => false,
                'network_wide'     => true,
            ],
            $overrides
        );

        return new Lifecycle( $runtime, $options );
    }

    private function remove_tree( $path ) {
        if ( is_file( $path ) || is_link( $path ) ) {
            unlink( $path );

            return;
        }

        if ( ! is_dir( $path ) ) {
            return;
        }

        foreach ( array_diff( scandir( $path ), [ '.', '..' ] ) as $item ) {
            $this->remove_tree( $path . '/' . $item );
        }

        rmdir( $path );
    }
}
