<?php
/**
 * Directorist Performance SPA shell behavior locks.
 */

use Directorist\Cache\Performance_Admin;

final class Directorist_Page_Cache_Performance_Admin_Test extends WP_UnitTestCase {
    protected function tearDown(): void {
        wp_dequeue_script( Performance_Admin::SCRIPT_HANDLE );
        wp_deregister_script( Performance_Admin::SCRIPT_HANDLE );
        wp_dequeue_style( Performance_Admin::STYLE_HANDLE );
        wp_deregister_style( Performance_Admin::STYLE_HANDLE );
        parent::tearDown();
    }

    public function test_registers_one_scoped_menu_and_asset_boundary() {
        $admin = new Performance_Admin();

        $this->assertTrue( $admin->register() );
        $this->assertFalse( $admin->register() );
        $this->assertFalse( has_action( 'rest_api_init', [ $admin, 'register_rest_routes' ] ) );
        $this->assertSame( 80, has_action( 'admin_menu', [ $admin, 'add_menu' ] ) );
        $this->assertFalse( has_action( 'admin_post_directorist_performance_action' ) );
    }

    public function test_rest_boundary_is_registered_independently_of_the_admin_screen() {
        $this->assertSame( 10, has_action( 'rest_api_init', 'directorist_page_cache_register_performance_rest_routes' ) );
    }

    public function test_screen_renders_only_the_spa_mount_and_accessible_fallback() {
        $administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $administrator );

        ob_start();
        ( new Performance_Admin() )->render_page();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'id="directorist-performance-app"', $html );
        $this->assertStringContainsString( 'Loading Directorist Performance', $html );
        $this->assertStringContainsString( '<noscript>', $html );
        $this->assertStringNotContainsString( '<form', $html );
        $this->assertStringNotContainsString( 'Cache actions', $html );
        $this->assertStringNotContainsString( 'filesystem', $html );
    }

    public function test_assets_and_boot_config_enqueue_only_for_the_performance_screen() {
        $admin = new Performance_Admin();

        $this->assertFalse( $admin->enqueue_assets( 'plugins.php' ) );
        $this->assertTrue( $admin->enqueue_assets( Performance_Admin::HOOK_SUFFIX ) );
        $this->assertTrue( wp_script_is( Performance_Admin::SCRIPT_HANDLE, 'enqueued' ) );
        $this->assertTrue( wp_style_is( Performance_Admin::STYLE_HANDLE, 'enqueued' ) );

        $before = wp_scripts()->get_data( Performance_Admin::SCRIPT_HANDLE, 'before' );
        $this->assertIsArray( $before );
        $this->assertStringContainsString( 'directoristPerformance', implode( "\n", $before ) );
        $this->assertStringContainsString( 'directorist\\/v1\\/admin\\/performance', implode( "\n", $before ) );
    }

    public function test_style_and_script_manifests_can_supply_independent_cache_versions() {
        $root = sys_get_temp_dir() . '/directorist-performance-assets-' . wp_generate_uuid4();
        wp_mkdir_p( $root );
        file_put_contents( $root . '/performance.css', 'body{}' );
        file_put_contents( $root . '/performance.asset.php', "<?php return ['version' => 'css-version'];" );

        $method = new ReflectionMethod( Performance_Admin::class, 'asset_version' );
        $method->setAccessible( true );
        $version = $method->invoke( new Performance_Admin(), $root . '/performance.asset.php', $root . '/performance.css', 'fallback' );

        $this->assertSame( 'css-version', $version );

        unlink( $root . '/performance.asset.php' );
        unlink( $root . '/performance.css' );
        rmdir( $root );
    }

    public function test_body_class_matches_the_settings_workspace_only_on_this_screen() {
        $admin = new Performance_Admin();

        $this->assertSame( 'existing', $admin->admin_body_class( 'existing', 'plugins.php' ) );
        $this->assertStringContainsString(
            'directorist-performance-page',
            $admin->admin_body_class( 'existing', Performance_Admin::HOOK_SUFFIX )
        );
        $this->assertStringContainsString(
            'directorist-settings-redesign-page',
            $admin->admin_body_class( 'existing', Performance_Admin::HOOK_SUFFIX )
        );
    }
}
