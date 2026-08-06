<?php
/**
 * Request entry-point registration behavior locks.
 */

class Directorist_Request_Registration_Test extends WP_UnitTestCase {
    public function test_dynamic_blocks_are_registered_with_callable_renderers() {
        $registry = WP_Block_Type_Registry::get_instance();

        foreach ( [ 'directorist/all-listing', 'directorist/search-listing', 'directorist/single-listing' ] as $block_name ) {
            $this->assertTrue( $registry->is_registered( $block_name ) );
            $this->assertIsCallable( $registry->get_registered( $block_name )->render_callback );
        }
    }

    public function test_rest_routes_are_registered_only_when_rest_api_initializes() {
        $server = rest_get_server();

        do_action( 'rest_api_init', $server );

        $routes = $server->get_routes();

        $this->assertArrayHasKey( '/directorist/v1/listings', $routes );
        $this->assertArrayHasKey( '/directorist/v1/listings/categories', $routes );
        $this->assertArrayHasKey( '/directorist/v1/listings/locations', $routes );
        $this->assertArrayHasKey( '/directorist/v1/directories', $routes );
        $this->assertArrayHasKey( '/directorist/v2/listings', $routes );
    }

    public function test_admin_services_keep_their_screen_and_ajax_hooks() {
        set_current_screen( 'edit-at_biz_dir' );

        $admin_menu   = new Directorist\AdminMenu();
        $setup_wizard = new Directorist_Setup_Wizard();
        $metabox      = new ATBDP_Metabox();
        $tools        = new ATBDP_Tools();

        $this->assertSame( 10, has_action( 'admin_menu', [ $admin_menu, 'action_admin_menu' ] ) );
        $this->assertSame( 10, has_action( 'wp_ajax_directorist_setup_wizard', [ $setup_wizard, 'directorist_setup_wizard' ] ) );
        $this->assertSame( 10, has_action( 'wp_ajax_atbdp_dynamic_admin_listing_form', [ $metabox, 'atbdp_dynamic_admin_listing_form' ] ) );
        $this->assertSame( 10, has_action( 'wp_ajax_directorist_import_listings', [ $tools, 'handle_import_listings' ] ) );

        set_current_screen( 'front' );
    }
}
