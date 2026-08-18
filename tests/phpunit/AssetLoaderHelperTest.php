<?php
/**
 * Behavior-lock tests for Directorist asset URL resolution.
 */

class Directorist_Asset_Loader_Helper_Test extends WP_UnitTestCase {
    public function test_asset_file_url_uses_minified_css_when_debugging_is_disabled() {
        update_option( 'atbdp_option', [ 'script_debugging' => false ] );

        $url = \Directorist\Asset_Loader\Helper::asset_file_url( DIRECTORIST_ICON_URL . 'font-awesome/css/all', 'css' );

        $this->assertStringEndsWith( '/assets/icons/font-awesome/css/all.min.css', $url );
    }

    public function test_asset_file_url_uses_unminified_css_when_script_debugging_is_enabled() {
        update_option( 'atbdp_option', [ 'script_debugging' => true ] );

        $url = \Directorist\Asset_Loader\Helper::asset_file_url( DIRECTORIST_ICON_URL . 'font-awesome/css/all', 'css' );

        $this->assertStringEndsWith( '/assets/icons/font-awesome/css/all.css', $url );
    }

    public function test_asset_file_url_falls_back_when_minified_file_does_not_exist() {
        update_option( 'atbdp_option', [ 'script_debugging' => false ] );

        $url = \Directorist\Asset_Loader\Helper::asset_file_url( DIRECTORIST_BUILD_ASSETS . 'css/public/main', 'css' );

        $this->assertStringEndsWith( '/assets/build/css/public/main.css', $url );
    }
}
