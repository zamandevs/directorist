<?php
/**
 * Directorist Performance admin screen behavior locks.
 */

use Directorist\Cache\Performance_Admin;
use Directorist\Cache\Performance_Event_Log;
use Directorist\Cache\Performance_Operations;
use Directorist\Cache\Performance_Settings;
use Directorist\Cache\Performance_Status;

class Directorist_Page_Cache_Performance_Admin_Test extends WP_UnitTestCase {
    private $admin_id;

    protected function setUp(): void {
        parent::setUp();
        $this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin_id );
        delete_option( Performance_Settings::OPTION_NAME );
        delete_option( Performance_Event_Log::OPTION_NAME );
    }

    protected function tearDown(): void {
        wp_dequeue_style( Performance_Admin::STYLE_HANDLE );
        delete_option( Performance_Settings::OPTION_NAME );
        delete_option( Performance_Event_Log::OPTION_NAME );
        parent::tearDown();
    }

    public function test_request_guard_rejects_capability_nonce_and_unknown_actions() {
        $admin = $this->admin();

        $this->assertSame( 'forbidden', $admin->process_request( [ 'operation' => 'purge' ], false, true )['code'] );
        $this->assertSame( 'invalid_nonce', $admin->process_request( [ 'operation' => 'purge' ], true, false )['code'] );
        $this->assertSame( 'unknown_operation', $admin->process_request( [ 'operation' => 'invalid' ], true, true )['code'] );

        $saved = $admin->process_request( [ 'operation' => 'save_settings', 'sample_rate' => 0, 'history_limit' => 50 ], true, true );
        $this->assertFalse( $saved['settings']['enabled'] );
    }

    public function test_screen_renders_provider_route_flow_controls_settings_and_history() {
        ( new Performance_Event_Log() )->record( 'success', 'cache-purged', [ 'provider' => 'none' ] );
        $admin = $this->admin();

        ob_start();
        $admin->render_page();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'Directorist Performance', $html );
        $this->assertStringContainsString( 'Provider ownership', $html );
        $this->assertStringContainsString( 'Route state', $html );
        $this->assertStringContainsString( 'Eligible', $html );
        $this->assertStringContainsString( 'Bypassed', $html );
        $this->assertStringContainsString( 'Warm queue', $html );
        $this->assertStringContainsString( 'cache-purged', $html );
        $this->assertStringContainsString( 'directorist_performance_nonce', $html );
        $this->assertStringNotContainsString( '<script>', $html );
    }

    public function test_assets_enqueue_only_for_the_performance_screen() {
        $admin = $this->admin();

        $this->assertFalse( $admin->enqueue_assets( 'plugins.php' ) );
        $this->assertTrue( $admin->enqueue_assets( Performance_Admin::HOOK_SUFFIX ) );
        $this->assertTrue( wp_style_is( Performance_Admin::STYLE_HANDLE, 'enqueued' ) );
    }

    private function admin() {
        $settings   = new Performance_Settings();
        $events     = new Performance_Event_Log( $settings );
        $provider   = directorist_page_cache()->get_provider();
        $operations = new Performance_Operations( $provider, $settings, $events );
        $status     = new Performance_Status( $provider, $settings, $events );

        return new Performance_Admin( $operations, $status, $settings );
    }
}
