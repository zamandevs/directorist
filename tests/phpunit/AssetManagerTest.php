<?php
/**
 * Behavior-lock tests for frontend asset scoping.
 */

class Directorist_Asset_Manager_Test extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();

        $this->reset_scoped_asset_cache();
        $this->reset_asset_queues();
        $this->reset_localized_data();
        \Directorist\Asset_Loader\Asset_Loader::register_scripts();
    }

    public function tear_down() {
        $this->reset_scoped_asset_cache();
        $this->reset_asset_queues();
        $this->reset_localized_data();

        parent::tear_down();
    }

    public function test_plain_frontend_page_does_not_enqueue_directorist_assets() {
        $this->go_to_page_with_content( '<p>Plain content</p>' );

        \Directorist\Asset_Loader\Asset_Loader::enqueue_styles();

        $this->assertFalse( wp_style_is( 'directorist-main-style', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-swiper-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-listing-owner-dashboard', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'wp-components', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'react', 'enqueued' ) );
    }

    public function test_plain_frontend_page_does_not_build_localized_data_or_dynamic_css() {
        $this->go_to_page_with_content( '<p>Plain content</p>' );
        $this->clear_script_data( 'jquery' );
        $this->clear_style_inline_data( 'directorist-main-style' );

        \Directorist\Asset_Loader\Asset_Loader::register_scripts();
        \Directorist\Asset_Loader\Localized_Data::load_localized_data();
        \Directorist\Asset_Loader\Asset_Loader::enqueue_styles();

        $this->assertStringNotContainsString( 'var directorist', $this->get_script_data( 'jquery' ) );
        $this->assertSame( [], $this->get_style_inline_data( 'directorist-main-style' ) );
    }

    public function test_directorist_request_adds_localized_data_and_dynamic_css_once() {
        $this->go_to_page_with_content( '[directorist_all_listing]' );
        $this->clear_script_data( 'jquery' );
        $this->clear_style_inline_data( 'directorist-main-style' );

        \Directorist\Asset_Loader\Localized_Data::load_localized_data();
        \Directorist\Asset_Loader\Asset_Loader::enqueue_styles();
        \Directorist\Asset_Loader\Localized_Data::load_localized_data();
        \Directorist\Asset_Loader\Asset_Loader::enqueue_styles();

        $this->assertStringContainsString( 'var directorist', $this->get_script_data( 'jquery' ) );
        $this->assertCount( 1, $this->get_style_inline_data( 'directorist-main-style' ) );
    }

    public function test_renderer_requirement_lazily_adds_frontend_localized_data() {
        $this->go_to_page_with_content( '<p>Extension renderer shell</p>' );
        $this->clear_script_data( 'directorist-search-form' );

        \Directorist\Asset_Loader\Asset_Manager::require_asset( 'search-form', 'extension-renderer' );

        $this->assertStringContainsString( 'var directorist', $this->get_script_data( 'directorist-search-form' ) );
    }

    public function test_late_renderer_localizes_onto_required_footer_script_after_jquery_printed() {
        $this->go_to_page_with_content( '<p>Late extension renderer shell</p>' );
        $this->clear_script_data( 'directorist-search-form' );
        wp_scripts()->done[] = 'jquery';
        wp_scripts()->done[] = 'jquery-core';

        \Directorist\Asset_Loader\Asset_Manager::require_asset( 'search-form', 'late-extension-renderer' );

        $this->assertStringContainsString( 'var directorist', $this->get_script_data( 'directorist-search-form' ) );
    }

    public function test_template_renderer_recovers_base_style_when_request_detection_misses() {
        $this->go_to_page_with_content( '<p>Extension renderer shell</p>' );
        $this->mark_head_styles_printed();

        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'search-form-contents' );

        $this->assertTrue( wp_style_is( 'directorist-main-style', 'enqueued' ) );
        $this->assertArrayHasKey( 'directorist-main-style', \Directorist\Asset_Loader\Asset_Manager::get_late_styles() );
    }

    public function test_integration_style_requested_after_head_is_printed_by_footer_flush() {
        $this->go_to_page_with_content( '<p>Late integration renderer shell</p>' );
        wp_register_style( 'directorist-integration-test', 'https://example.test/integration.css', [], '1.0.0' );
        $this->mark_head_styles_printed();

        directorist_require_style( 'directorist-integration-test', 'phpunit-renderer' );

        ob_start();
        \Directorist\Asset_Loader\Asset_Manager::print_late_styles();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'integration.css', $output );
        $this->assertTrue( wp_style_is( 'directorist-integration-test', 'done' ) );
    }

    public function test_footer_flush_recovers_integration_style_registered_after_renderer_request() {
        $this->go_to_page_with_content( '<p>Early integration renderer shell</p>' );

        directorist_require_style( 'directorist-delayed-integration-style', 'phpunit-early-renderer' );

        $this->assertFalse( wp_style_is( 'directorist-delayed-integration-style', 'enqueued' ) );

        wp_register_style( 'directorist-delayed-integration-style', 'https://example.test/delayed-integration.css', [], '1.0.0' );
        $this->mark_head_styles_printed();

        ob_start();
        \Directorist\Asset_Loader\Asset_Manager::flush_footer_assets();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'delayed-integration.css', $output );
        $this->assertTrue( wp_style_is( 'directorist-delayed-integration-style', 'done' ) );
    }

    public function test_footer_flush_recovers_integration_script_registered_after_renderer_request() {
        \Directorist\Asset_Loader\Asset_Manager::require_script(
            'directorist-delayed-integration-script',
            'integration',
            'phpunit-early-renderer'
        );

        $this->assertFalse( wp_script_is( 'directorist-delayed-integration-script', 'enqueued' ) );

        wp_register_script( 'directorist-delayed-integration-script', 'https://example.test/delayed-integration.js', [], '1.0.0', true );

        \Directorist\Asset_Loader\Asset_Manager::flush_footer_assets();

        $this->assertTrue( wp_script_is( 'directorist-delayed-integration-script', 'enqueued' ) );
    }

    public function test_renderer_runtime_scripts_are_registered_for_footer_output() {
        $scripts = wp_scripts();

        $this->assertSame( 1, $scripts->registered['directorist-payment-receipt']->extra['group'] );
        $this->assertSame( 1, $scripts->registered['directorist-listing-owner-dashboard']->extra['group'] );
    }

    public function test_listing_shortcode_page_enqueues_listing_assets_without_dashboard_react() {
        $this->go_to_page_with_content( '[directorist_all_listing]' );

        \Directorist\Asset_Loader\Asset_Loader::enqueue_styles();

        $this->assertTrue( wp_style_is( 'directorist-main-style', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-swiper-style', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-ez-media-uploader-style', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-sweetalert-style', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist/frontend', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-listing-owner-dashboard', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'react', 'enqueued' ) );
    }

    public function test_gb_integration_listing_page_is_detected_without_dashboard_react() {
        $this->go_to_page_with_content( '<!-- wp:directorist-gb-integration/listings-loop --><!-- /wp:directorist-gb-integration/listings-loop -->' );

        \Directorist\Asset_Loader\Asset_Loader::enqueue_styles();

        $this->assertTrue( wp_style_is( 'directorist-main-style', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-listing-owner-dashboard', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'react', 'enqueued' ) );
    }

    public function test_blocks_common_editor_style_is_not_enqueued_on_plain_frontend_page() {
        $this->go_to_page_with_content( '<p>Plain content</p>' );

        do_action( 'enqueue_block_assets' );

        $this->assertFalse( wp_style_is( 'directorist-blocks-common', 'enqueued' ) );
    }

    public function test_blocks_common_editor_style_is_not_enqueued_on_frontend_block_page() {
        $this->go_to_page_with_content( '<!-- wp:directorist/all-listing --><!-- /wp:directorist/all-listing -->' );

        do_action( 'enqueue_block_assets' );

        $this->assertFalse( wp_style_is( 'directorist-blocks-common', 'enqueued' ) );
    }

    public function test_blocks_common_editor_style_is_enqueued_in_block_editor() {
        directorist_register_blocks_common_assets();

        $this->assertTrue( wp_style_is( 'directorist-blocks-common', 'enqueued' ) );
    }

    public function test_dashboard_orders_template_enqueues_owner_dashboard_react_assets() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'dashboard/tab-orders' );

        $this->assertTrue( wp_style_is( 'directorist/frontend', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-listing-owner-dashboard', 'enqueued' ) );
    }

    public function test_search_form_wrapper_enqueues_form_behavior_without_field_vendor_assets() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'search-form-contents' );

        $this->assertTrue( wp_script_is( 'directorist-search-form', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-select2-script', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-swiper-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-swiper', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-listing-slider', 'enqueued' ) );
        $this->assertSame( [], \Directorist\Asset_Loader\Asset_Manager::get_late_styles() );
    }

    public function test_search_select_field_templates_enqueue_select2_only() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'search-form/fields/category' );

        $this->assertTrue( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-select2-script', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-swiper-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-listing-slider', 'enqueued' ) );

        $this->reset_and_register_assets();

        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'search-form/custom-fields/number/dropdown' );

        $this->assertTrue( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-select2-script', 'enqueued' ) );
    }

    public function test_search_location_field_scopes_assets_by_location_source() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts(
            'search-form/fields/location',
            '',
            [
                'data' => [
                    'location_source' => 'from_listing',
                ],
            ]
        );

        $this->assertTrue( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-select2-script', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-geolocation', 'enqueued' ) );

        $this->reset_and_register_assets();

        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts(
            'search-form/fields/location',
            '',
            [
                'data' => [
                    'location_source' => 'from_map_api',
                ],
            ]
        );

        $this->assertTrue( wp_script_is( 'directorist-geolocation', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-select2-script', 'enqueued' ) );
    }

    public function test_range_field_templates_enqueue_range_slider_without_select2() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'search-form/fields/radius_search' );

        $this->assertTrue( wp_script_is( 'directorist-range-slider', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-select2-script', 'enqueued' ) );

        $this->reset_and_register_assets();

        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'search-form/custom-fields/number/range' );

        $this->assertTrue( wp_script_is( 'directorist-range-slider', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-select2-script', 'enqueued' ) );
    }

    public function test_listing_form_taxonomy_fields_enqueue_select2() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'listing-form/fields/category' );

        $this->assertTrue( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-select2-script', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-listing-slider', 'enqueued' ) );
    }

    public function test_listing_form_image_field_enqueues_media_uploader_without_select2() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'listing-form/fields/image_upload' );

        $this->assertTrue( wp_style_is( 'directorist-ez-media-uploader-style', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-ez-media-uploader', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-select2-script', 'enqueued' ) );
    }

    public function test_archive_grid_view_waits_for_gallery_renderer_before_enqueueing_slider() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'archive/grid-view' );

        $this->assertFalse( wp_style_is( 'directorist-swiper-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-swiper', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-listing-slider', 'enqueued' ) );
    }

    public function test_archive_wrapper_enqueues_listing_behavior_without_map_or_slider() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'archive-contents' );

        $this->assertTrue( wp_script_is( 'directorist-all-listings', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-openstreet-map-leaflet', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-openstreet-map', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-swiper-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-listing-slider', 'enqueued' ) );
    }

    public function test_archive_search_templates_enqueue_search_behavior_without_vendor_assets() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'archive/search-form' );

        $this->assertTrue( wp_script_is( 'directorist-search-form', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-select2-script', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-swiper-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-listing-slider', 'enqueued' ) );
    }

    public function test_archive_map_view_requires_map_assets() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'archive/map-view' );

        $this->assertTrue( wp_style_is( 'directorist-openstreet-map-leaflet', 'enqueued' ) );
        $this->assertTrue( wp_style_is( 'directorist-openstreet-map-openstreet', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-openstreet-map', 'enqueued' ) );
    }

    public function test_single_shell_does_not_enqueue_slider_until_slider_renderer() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'single-contents' );

        $this->assertFalse( wp_style_is( 'directorist-swiper-style', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-listing-slider', 'enqueued' ) );

        $this->reset_and_register_assets();

        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'single/slider' );

        $this->assertTrue( wp_style_is( 'directorist-swiper-style', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-listing-slider', 'enqueued' ) );
    }

    public function test_add_listing_wrapper_does_not_eager_load_field_vendor_assets() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'listing-form/add-listing' );

        $this->assertTrue( wp_script_is( 'directorist-add-listing', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-ez-media-uploader-style', 'enqueued' ) );
        $this->assertFalse( wp_style_is( 'directorist-sweetalert-style', 'enqueued' ) );
    }

    public function test_dashboard_wrapper_scopes_order_react_to_order_tab() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'dashboard-contents' );

        $this->assertTrue( wp_script_is( 'directorist-dashboard', 'enqueued' ) );
        $this->assertTrue( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-formgent-integration', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-listing-owner-dashboard', 'enqueued' ) );

        $this->reset_and_register_assets();

        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'dashboard/tab-orders' );

        $this->assertTrue( wp_script_is( 'directorist-listing-owner-dashboard', 'enqueued' ) );
    }

    public function test_account_and_payment_templates_enqueue_only_their_renderer_scripts() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'account/login' );

        $this->assertTrue( wp_script_is( 'directorist-account', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-checkout', 'enqueued' ) );

        $this->reset_and_register_assets();

        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'payment/checkout' );

        $this->assertTrue( wp_script_is( 'directorist-checkout', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-account', 'enqueued' ) );
    }

    public function test_widget_templates_enqueue_widget_script_and_renderer_assets() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'widgets/search-form' );

        $this->assertTrue( wp_script_is( 'directorist-widgets', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-search-form', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-listing-slider', 'enqueued' ) );
    }

    public function test_color_picker_field_templates_enqueue_wp_color_picker_assets() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'search-form/custom-fields/color_picker' );

        $this->assertTrue( wp_style_is( 'wp-color-picker', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'iris', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'wp-color-picker', 'enqueued' ) );
    }

    public function test_renderer_requirement_after_head_print_tracks_and_prints_late_component_styles() {
        $this->mark_head_styles_printed();

        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'single/slider' );

        $late_styles = \Directorist\Asset_Loader\Asset_Manager::get_late_styles();

        $this->assertArrayHasKey( 'directorist-swiper-style', $late_styles );
        $this->assertTrue( wp_style_is( 'directorist-swiper-style', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-swiper', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-listing-slider', 'enqueued' ) );

        ob_start();
        $printed = print_late_styles();
        $html    = ob_get_clean();

        $this->assertContains( 'directorist-swiper-style', $printed );
        $this->assertStringContainsString( 'swiper', $html );
        $this->assertTrue( wp_style_is( 'directorist-swiper-style', 'done' ) );
    }

    public function test_duplicate_renderer_requirements_do_not_duplicate_script_or_style_queues() {
        \Directorist\Asset_Loader\Asset_Manager::require_asset( 'select2' );
        \Directorist\Asset_Loader\Asset_Manager::require_asset( [ 'select2', 'listing-slider', 'listing-slider' ] );

        $wp_scripts = wp_scripts();
        $wp_styles  = wp_styles();

        $this->assertSame( 1, count( array_keys( $wp_scripts->queue, 'directorist-select2-script', true ) ) );
        $this->assertSame( 1, count( array_keys( $wp_styles->queue, 'directorist-select2-style', true ) ) );
        $this->assertSame( 1, count( array_keys( $wp_scripts->queue, 'directorist-swiper', true ) ) );
        $this->assertSame( 1, count( array_keys( $wp_styles->queue, 'directorist-swiper-style', true ) ) );
        $this->assertSame( 1, count( array_keys( $wp_scripts->queue, 'directorist-listing-slider', true ) ) );
    }

    public function test_renderer_requirements_before_handle_registration_flush_after_registration() {
        wp_deregister_script( 'directorist-search-form' );
        wp_deregister_script( 'directorist-range-slider' );

        \Directorist\Asset_Loader\Asset_Manager::reset();
        \Directorist\Asset_Loader\Asset_Manager::require_asset( [ 'search-form', 'range-slider' ], 'early-render' );

        $this->assertFalse( wp_script_is( 'directorist-search-form', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'directorist-range-slider', 'enqueued' ) );

        \Directorist\Asset_Loader\Asset_Loader::register_scripts();

        $this->assertTrue( wp_script_is( 'directorist-search-form', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-range-slider', 'enqueued' ) );
    }

    public function test_dashboard_order_localization_waits_for_late_runtime_registration() {
        wp_deregister_style( 'directorist/frontend' );
        wp_deregister_script( 'directorist-listing-owner-dashboard' );

        \Directorist\Asset_Loader\Asset_Manager::reset();
        \Directorist\Asset_Loader\Asset_Manager::require_asset( 'dashboard-orders', 'early-dashboard-render' );

        $this->assertFalse( wp_script_is( 'directorist-listing-owner-dashboard', 'registered' ) );

        \Directorist\Asset_Loader\Asset_Loader::register_scripts();

        $this->assertTrue( wp_script_is( 'directorist-listing-owner-dashboard', 'enqueued' ) );
        $this->assertStringContainsString(
            'directorist_admin_order',
            (string) wp_scripts()->get_data( 'directorist-listing-owner-dashboard', 'data' )
        );
    }

    public function test_map_renderer_requirement_enqueues_openstreet_styles_and_script() {
        \Directorist\Asset_Loader\Asset_Loader::load_template_scripts( 'single/fields/map' );

        $this->assertTrue( wp_style_is( 'directorist-openstreet-map-leaflet', 'enqueued' ) );
        $this->assertTrue( wp_style_is( 'directorist-openstreet-map-openstreet', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-openstreet-map', 'enqueued' ) );
    }

    public function test_global_renderer_asset_helper_uses_requirement_registry() {
        directorist_require_asset( 'sweetalert', 'phpunit-integration', [ 'surface' => 'fixture' ] );

        $this->assertTrue( wp_style_is( 'directorist-sweetalert-style', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-sweetalert', 'enqueued' ) );
        $this->assertContains( 'sweetalert', directorist_get_required_assets() );
    }

    public function test_asset_manager_notifies_an_opt_in_profiler_with_reason_and_context() {
        $records  = [];
        $listener = static function ( $record ) use ( &$records ) {
            $records[] = $record;
        };

        add_action( 'directorist_asset_requirement_recorded', $listener );

        directorist_require_asset( 'sweetalert', 'extension-button-render', [ 'extension' => 'fixture' ] );

        remove_action( 'directorist_asset_requirement_recorded', $listener );

        $this->assertNotEmpty( $records );
        $this->assertSame( 'sweetalert', $records[0]['asset'] );
        $this->assertSame( 'extension-button-render', $records[0]['reason'] );
        $this->assertSame( 'fixture', $records[0]['context']['extension'] );
    }

    public function test_asset_compatibility_mode_defaults_to_auto() {
        $this->assertSame( 'auto', \Directorist\Asset_Loader\Asset_Compatibility::mode() );
    }

    public function test_asset_compatibility_legacy_mode_forces_frontend_assets() {
        $mode_filter = static function () {
            return 'legacy';
        };

        add_filter( 'directorist_asset_compatibility_mode', $mode_filter );

        $this->assertTrue( \Directorist\Asset_Loader\Asset_Compatibility::should_enqueue_frontend_assets( false, [] ) );

        remove_filter( 'directorist_asset_compatibility_mode', $mode_filter );
    }

    protected function go_to_page_with_content( $content ) {
        $page_id = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_content' => $content,
            ]
        );

        $this->go_to( get_permalink( $page_id ) );
        $this->reset_scoped_asset_cache();
    }

    protected function reset_scoped_asset_cache() {
        \Directorist\Asset_Loader\Asset_Manager::reset();

        global $wp_actions;

        $wp_actions['wp_print_styles']         = 0;
        $wp_actions['wp_print_footer_scripts'] = 0;
    }

    protected function mark_head_styles_printed() {
        global $wp_actions;

        $wp_actions['wp_print_styles'] = 1;
    }

    protected function reset_asset_queues() {
        $wp_scripts = wp_scripts();
        $wp_styles  = wp_styles();

        $wp_scripts->queue = [];
        $wp_scripts->done  = [];
        $wp_styles->queue  = [];
        $wp_styles->done   = [];
    }

    protected function reset_and_register_assets() {
        $this->reset_scoped_asset_cache();
        $this->reset_asset_queues();
        $this->reset_localized_data();
        \Directorist\Asset_Loader\Asset_Loader::register_scripts();
    }

    protected function reset_localized_data() {
        if ( method_exists( \Directorist\Asset_Loader\Localized_Data::class, 'reset' ) ) {
            \Directorist\Asset_Loader\Localized_Data::reset();
        }
    }

    protected function clear_script_data( $handle ) {
        $handle = 'jquery' === $handle ? 'jquery-core' : $handle;

        if ( isset( wp_scripts()->registered[ $handle ] ) ) {
            wp_scripts()->registered[ $handle ]->extra['data'] = '';
        }
    }

    protected function get_script_data( $handle ) {
        $handle = 'jquery' === $handle ? 'jquery-core' : $handle;

        return isset( wp_scripts()->registered[ $handle ]->extra['data'] )
            ? (string) wp_scripts()->registered[ $handle ]->extra['data']
            : '';
    }

    protected function clear_style_inline_data( $handle ) {
        if ( isset( wp_styles()->registered[ $handle ] ) ) {
            wp_styles()->registered[ $handle ]->extra['after'] = [];
        }
    }

    protected function get_style_inline_data( $handle ) {
        return isset( wp_styles()->registered[ $handle ]->extra['after'] )
            ? (array) wp_styles()->registered[ $handle ]->extra['after']
            : [];
    }
}
