<?php
/**
 * Behavior locks for request-scoped service bootstrap.
 */

class Directorist_Request_Scope_Behavior_Test extends WP_UnitTestCase {
    public function test_request_context_separates_admin_screens_from_ajax_requests() {
        $request = new Directorist\Request_Context(
            [
                'is_admin'    => true,
                'is_ajax'     => true,
                'is_cron'     => false,
                'is_cli'      => false,
                'ajax_action' => 'directorist_instant_search',
            ]
        );

        $this->assertTrue( $request->is_ajax() );
        $this->assertFalse( $request->is_admin_screen() );
        $this->assertFalse( $request->is_cron() );
        $this->assertFalse( $request->is_cli() );
        $this->assertSame( 'directorist_instant_search', $request->ajax_action() );
    }

    public function test_core_ajax_handler_owns_the_complete_existing_action_catalog() {
        $actions = ATBDP_Ajax_Handler::get_ajax_actions();

        $expected_actions = [
            'atbdp_social_info_handler',
            'remove_listing',
            'update_user_profile',
            'update_user_preferences',
            'atbdp_format_total_amount',
            'atbdp_public_report_abuse',
            'atbdp_public_send_contact_email',
            'atbdp_public_add_remove_favorites',
            'bdas_public_dropdown_terms',
            'atbdp_custom_fields_search',
            'atbdp-favourites-all-listing',
            'atbdp_post_attachment_upload',
            'ajaxlogin',
            'atbdp_ajax_quick_login',
            'atbdp_upgrade_old_pages',
            'atbdp_listing_default_type',
            'directorist_type_slug_change',
            'atbdp_guest_reception',
            'directorist_load_category_custom_fields',
            'atbdp_listing_types_form',
            'directorist_category_custom_field_search',
            'directorist_get_category_options',
            'directorist_get_tag_options',
            'directorist_get_location_options',
            'atbdp_become_author',
            'atbdp_user_type_approved',
            'atbdp_user_type_deny',
            'directorist_prepare_listings_export_file',
            'directorist_ajax_quick_login',
            'directorist_author_alpha_sorting',
            'directorist_author_pagination',
            'directorist_instant_search',
            'directorist_send_confirmation_email',
            'directorist_zipcode_search',
            'directorist_generate_nonce',
            'directorist_taxonomy_pagination',
            'directorist_update_view_count',
            'atbdp_reject_listing',
        ];

        $this->assertSame( $expected_actions, array_keys( $actions ) );
        $this->assertSame(
            61,
            array_sum(
                array_map(
                    static function ( $definition ) {
                        return empty( $definition['public'] ) ? 1 : 2;
                    },
                    $actions
                )
            )
        );
    }

    public function test_core_ajax_handler_registers_every_owned_callback() {
        $handler = new ATBDP_Ajax_Handler();

        foreach ( ATBDP_Ajax_Handler::get_ajax_actions() as $action => $definition ) {
            $callback = is_string( $definition['callback'] )
                ? [ $handler, $definition['callback'] ]
                : $definition['callback'];

            $this->assertIsCallable( $callback, $action );
            $this->assertSame( 10, has_action( 'wp_ajax_' . $action, $callback ), $action );

            if ( ! empty( $definition['public'] ) ) {
                $this->assertSame( 10, has_action( 'wp_ajax_nopriv_' . $action, $callback ), $action );
            }
        }
    }

    public function test_core_ajax_handler_can_register_only_the_current_action() {
        $handler = new ATBDP_Ajax_Handler( 'directorist_instant_search' );

        $this->assertSame( 10, has_action( 'wp_ajax_directorist_instant_search', [ $handler, 'instant_search' ] ) );
        $this->assertSame( 10, has_action( 'wp_ajax_nopriv_directorist_instant_search', [ $handler, 'instant_search' ] ) );
        $this->assertFalse( has_action( 'wp_ajax_remove_listing', [ $handler, 'remove_listing' ] ) );
    }

    public function test_core_ajax_handler_boots_only_for_its_own_actions() {
        $instant_search = new Directorist\Request_Context(
            [
                'is_admin'    => true,
                'is_ajax'     => true,
                'ajax_action' => 'directorist_instant_search',
            ]
        );
        $heartbeat      = new Directorist\Request_Context(
            [
                'is_admin'    => true,
                'is_ajax'     => true,
                'ajax_action' => 'heartbeat',
            ]
        );

        $this->assertTrue( ATBDP_Ajax_Handler::should_boot( $instant_search ) );
        $this->assertFalse( ATBDP_Ajax_Handler::should_boot( $heartbeat ) );
    }

    public function test_admin_service_action_ownership_is_service_defined() {
        $this->assertTrue( Directorist_Setup_Wizard::handles_ajax_action( 'directorist_setup_wizard' ) );
        $this->assertTrue( ATBDP_Metabox::handles_ajax_action( 'atbdp_dynamic_admin_listing_form' ) );
        $this->assertTrue( ATBDP_Tools::handles_ajax_action( 'directorist_import_listings' ) );
        $this->assertTrue( ATBDP_Extensions::handles_ajax_action( 'atbdp_activate_plugin' ) );
        $this->assertTrue( Directorist\Directorist_Template_Hooks::handles_dashboard_ajax_action( 'directorist_dashboard_listing_tab' ) );

        $this->assertFalse( Directorist_Setup_Wizard::handles_ajax_action( 'heartbeat' ) );
        $this->assertFalse( ATBDP_Metabox::handles_ajax_action( 'heartbeat' ) );
        $this->assertFalse( ATBDP_Tools::handles_ajax_action( 'heartbeat' ) );
        $this->assertFalse( ATBDP_Extensions::handles_ajax_action( 'heartbeat' ) );
        $this->assertFalse( Directorist\Directorist_Template_Hooks::handles_dashboard_ajax_action( 'heartbeat' ) );
    }

    public function test_admin_services_can_register_only_the_current_ajax_action() {
        $administrator_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

        wp_set_current_user( $administrator_id );
        set_current_screen( 'edit-at_biz_dir' );

        $tools      = new ATBDP_Tools( 'directorist_import_listings' );
        $extensions = new ATBDP_Extensions( 'atbdp_activate_plugin' );
        $extensions->setup_ajax_actions();

        $this->assertSame( 10, has_action( 'wp_ajax_directorist_import_listings', [ $tools, 'handle_import_listings' ] ) );
        $this->assertFalse( has_action( 'wp_ajax_directorist_update_csv_columns_to_listing_fields_table', [ $tools, 'update_csv_columns_to_listing_fields_table' ] ) );
        $this->assertSame( 10, has_action( 'wp_ajax_atbdp_activate_plugin', [ $extensions, 'activate_plugin' ] ) );
        $this->assertFalse( has_action( 'wp_ajax_atbdp_update_plugins', [ $extensions, 'handle_plugins_update_request' ] ) );

        wp_set_current_user( 0 );
        set_current_screen( 'front' );
    }

    public function test_background_image_process_boots_only_for_cron_or_its_async_action() {
        $async_request  = new Directorist\Request_Context(
            [
                'is_admin'    => true,
                'is_ajax'     => true,
                'ajax_action' => Directorist\Background_Image_Process::get_ajax_action(),
            ]
        );
        $unrelated_ajax = new Directorist\Request_Context(
            [
                'is_admin'    => true,
                'is_ajax'     => true,
                'ajax_action' => 'heartbeat',
            ]
        );
        $cron           = new Directorist\Request_Context( [ 'is_cron' => true ] );

        $this->assertTrue( Directorist\Background_Image_Process::should_boot( $async_request ) );
        $this->assertFalse( Directorist\Background_Image_Process::should_boot( $unrelated_ajax ) );
        $this->assertTrue( Directorist\Background_Image_Process::should_boot( $cron ) );
    }

    public function test_dashboard_ajax_keeps_its_original_callback_owner_on_its_request() {
        $request = new Directorist\Request_Context(
            [
                'is_admin'    => true,
                'is_ajax'     => true,
                'ajax_action' => 'directorist_dashboard_listing_tab',
            ]
        );

        Directorist\Directorist_Template_Hooks::register_dashboard_ajax( $request );
        $dashboard = Directorist\Directorist_Listing_Dashboard::instance();

        $this->assertSame( 10, has_action( 'wp_ajax_directorist_dashboard_listing_tab', [ $dashboard, 'ajax_listing_tab' ] ) );
    }
}
