<?php
/**
 * Behavior locks for the legacy aggregate Directorist frontend data object.
 */

class Directorist_Localized_Data_Behavior_Test extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();

        $this->reset_localized_data();
        \Directorist\Asset_Loader\Asset_Compatibility::reset();
        $this->reset_script_queues();
        \Directorist\Asset_Loader\Asset_Loader::register_scripts();
    }

    public function tear_down() {
        remove_all_filters( 'directorist_localized_data' );
        remove_all_filters( 'directorist_localized_data_module' );
        remove_all_filters( 'directorist_localized_data_aware_extensions' );
        remove_all_filters( 'directorist_asset_aware_extensions' );
        remove_all_filters( 'directorist_theme_supports_scoped_localized_data' );
        remove_all_filters( 'directorist_theme_supports_scoped_assets' );
        remove_all_filters( 'directorist_asset_compatibility_mode' );
        remove_all_filters( 'directorist_should_enqueue_frontend_assets' );
        $this->reset_localized_data();
        \Directorist\Asset_Loader\Asset_Compatibility::reset();
        $this->reset_script_queues();

        parent::tear_down();
    }

    public function test_public_data_currently_contains_all_frontend_concerns() {
        $data = \Directorist\Asset_Loader\Localized_Data::public_data();

        $this->assertArrayHasKey( 'request_headers', $data );
        $this->assertArrayHasKey( 'directorist_nonce', $data );
        $this->assertArrayHasKey( 'icon_render_mode', $data );
        $this->assertArrayHasKey( 'script_debugging', $data );
        $this->assertArrayHasKey( 'redirect_url', $data );
        $this->assertArrayHasKey( 'directory_type_term_data', $data );
        $this->assertArrayHasKey( 'add_listing_data', $data );
        $this->assertArrayHasKey( 'category_custom_field_relations', $data['add_listing_data'] );
        $this->assertArrayHasKey( 'submission_form_fields', $data['directory_type_term_data'] );
        $this->assertArrayHasKey( 'search_form_fields', $data['directory_type_term_data'] );
    }

    public function test_legacy_filter_receives_complete_aggregate_and_can_mutate_it() {
        $calls  = 0;
        $filter = static function ( $data ) use ( &$calls ) {
            $calls++;

            if ( isset( $data['add_listing_data'], $data['directory_type_term_data'], $data['redirect_url'] ) ) {
                $data['behavior_lock_marker'] = 'complete-aggregate';
            }

            return $data;
        };

        add_filter( 'directorist_localized_data', $filter );

        $data = \Directorist\Asset_Loader\Localized_Data::public_data();

        remove_filter( 'directorist_localized_data', $filter );

        $this->assertSame( 1, $calls );
        $this->assertSame( 'complete-aggregate', $data['behavior_lock_marker'] );
    }

    public function test_public_data_preserves_search_form_array_union_precedence() {
        $data = \Directorist\Asset_Loader\Localized_Data::public_data();

        $this->assertArrayHasKey( 'search_max_radius_distance', $data['args'] );
        $this->assertArrayNotHasKey( 'directory_type_id', $data['args'] );
    }

    public function test_admin_data_remains_a_separate_complete_object() {
        $data = \Directorist\Asset_Loader\Localized_Data::admin_data();

        $this->assertArrayHasKey( 'import_page_link', $data );
        $this->assertArrayHasKey( 'capabilities', $data );
        $this->assertArrayHasKey( 'add_listing_data', $data );
        $this->assertArrayHasKey( 'ajax_url', $data );
    }

    public function test_formgent_data_remains_separate_from_public_data() {
        $public_data   = \Directorist\Asset_Loader\Localized_Data::public_data();
        $formgent_data = \Directorist\Asset_Loader\Localized_Data::formgent_data();

        $this->assertArrayNotHasKey( 'strings', $public_data );
        $this->assertArrayHasKey( 'strings', $formgent_data );
        $this->assertArrayHasKey( 'enquiries', $formgent_data['strings'] );
    }

    public function test_legacy_listings_data_preserves_its_complete_key_contract() {
        $expected = [
            'add_listing_data',
            'add_listing_url',
            'ajax_nonce',
            'ajaxurl',
            'assets_url',
            'completeSubmission',
            'currentDate',
            'current_page_id',
            'directorist_nonce',
            'duplicate_review_error',
            'dynamic_view_count_cache',
            'enable_reviewer_content',
            'enabled_multi_directory',
            'home_url',
            'icon_class_markup',
            'icon_markup',
            'icon_render_mode',
            'icon_url_markup',
            'is_admin',
            'lazy_load_taxonomy_fields',
            'listing_delete',
            'listing_error_text',
            'listing_error_title',
            'listing_remove_confirm_text',
            'listing_remove_text',
            'listing_remove_title',
            'loading_more_text',
            'login_alert_message',
            'nonce',
            'nonceName',
            'not_add_more_than_one',
            'payNow',
            'plugin_url',
            'rest_nonce',
            'rest_url',
            'review_approval_text',
            'review_cancel_btn_text',
            'review_delete_msg',
            'review_error',
            'review_have_not_for_delete',
            'review_loaded',
            'review_not_available',
            'review_success',
            'review_sure_msg',
            'review_want_to_remove',
            'review_wrong_msg',
            'rtl',
            'search_form_default_label',
            'search_form_default_placeholder',
            'site_name',
            'success',
            'upload_pro_pic_text',
            'upload_pro_pic_title',
            'waiting_msg',
            'warning',
        ];
        $actual   = array_keys( \Directorist\Asset_Loader\Localized_Data::get_listings_data() );

        sort( $expected );
        sort( $actual );

        $this->assertSame( $expected, $actual );
    }

    public function test_search_handle_gets_search_and_map_data_without_form_schemas() {
        $this->clear_script_data( 'directorist-search-form' );

        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-search-form' );

        $data = $this->get_script_data( 'directorist-search-form' );

        $this->assertStringContainsString( 'var directorist =', $data );
        $this->assertStringContainsString( 'category_selection', $data );
        $this->assertStringContainsString( 'location_selection', $data );
        $this->assertStringContainsString( 'show_more', $data );
        $this->assertStringContainsString( 'select_listing_map', $data );
        $this->assertStringNotContainsString( 'add_listing_data', $data );
        $this->assertStringNotContainsString( 'directory_type_term_data', $data );
        $this->assertStringNotContainsString( 'redirect_url', $data );
    }

    public function test_range_slider_data_excludes_directory_form_schemas() {
        $data = \Directorist\Asset_Loader\Localized_Data::get_range_slider_data();

        $this->assertSame( [ 'miles' ], array_keys( $data ) );
        $this->assertSame( ' Miles', $data['miles'] );
    }

    public function test_range_slider_data_preserves_request_distance_override() {
        $_GET['miles'] = '27';

        $data = \Directorist\Asset_Loader\Localized_Data::get_range_slider_data();

        unset( $_GET['miles'] );

        $this->assertSame( 27, $data['miles'] );
    }

    public function test_registered_handle_modules_cover_shipped_core_global_consumers() {
        $contracts = [
            'directorist-search-form'           => [
                'keys' => [ 'ajax_url', 'assets_url', 'countryRestriction', 'directorist_nonce', 'i18n_text', 'icon_class_markup', 'icon_markup', 'icon_render_mode', 'icon_url_markup', 'restricted_countries', 'script_debugging' ],
                'i18n' => [ 'select_listing_map', 'show_less', 'show_more' ],
            ],
            'directorist-range-slider'          => [
                'keys' => [ 'rtl' ],
            ],
            'directorist-all-listings'          => [
                'keys' => [ 'ajax_nonce', 'ajax_url', 'ajaxurl', 'assets_url', 'current_page_id', 'directorist_nonce', 'duplicate_review_error', 'enable_reviewer_content', 'i18n_text', 'icon_class_markup', 'icon_markup', 'icon_render_mode', 'icon_url_markup', 'lazy_load_taxonomy_fields', 'loading_more_text', 'nonce', 'nonceName', 'not_add_more_than_one', 'rest_url', 'review_approval_text', 'review_cancel_btn_text', 'review_delete_msg', 'review_error', 'review_have_not_for_delete', 'review_success', 'review_sure_msg', 'review_want_to_remove', 'review_wrong_msg', 'script_debugging', 'site_name', 'success', 'warning' ],
                'i18n' => [ 'added_favourite', 'please_login' ],
            ],
            'directorist-all-authors'           => [
                'keys' => [ 'ajaxurl' ],
            ],
            'directorist-all-location-category' => [
                'keys' => [ 'ajax_url', 'directorist_nonce' ],
            ],
            'directorist-dashboard'             => [
                'keys' => [ 'ajax_url', 'ajaxurl', 'directorist_nonce', 'i18n_text', 'listing_delete', 'listing_error_text', 'listing_error_title', 'listing_remove_confirm_text', 'listing_remove_text', 'listing_remove_title', 'review_cancel_btn_text' ],
                'i18n' => [ 'added_favourite', 'please_login' ],
            ],
            'directorist-author-profile'        => [
                'keys' => [ 'ajax_url', 'ajaxurl', 'directorist_nonce', 'i18n_text' ],
                'i18n' => [ 'added_favourite', 'please_login' ],
            ],
            'directorist-account'               => [
                'keys' => [ 'ajax_url', 'ajaxurl', 'loading_message', 'login_alert_message', 'login_error_message', 'redirect_url' ],
            ],
            'directorist-single-listing'        => [
                'keys' => [ 'ajax_url', 'ajaxurl', 'current_page_id', 'directorist_nonce', 'duplicate_review_error', 'enable_reviewer_content', 'i18n_text', 'loading_message', 'login_alert_message', 'login_error_message', 'not_add_more_than_one', 'redirect_url', 'review_approval_text', 'review_cancel_btn_text', 'review_delete_msg', 'review_error', 'review_have_not_for_delete', 'review_success', 'review_sure_msg', 'review_want_to_remove', 'review_wrong_msg', 'success', 'waiting_msg', 'warning' ],
                'i18n' => [ 'added_favourite', 'please_login' ],
            ],
            'directorist-widgets'               => [
                'keys' => [ 'ajax_url', 'directorist_nonce' ],
            ],
            'directorist-global-script'         => [
                'keys' => [ 'assets_url', 'icon_class_markup', 'icon_markup', 'icon_render_mode', 'icon_url_markup', 'lazy_load_taxonomy_fields', 'rest_url', 'script_debugging' ],
            ],
            'directorist-add-listing'           => [
                'keys' => [ 'add_listing_data', 'ajaxurl', 'directorist_nonce', 'is_admin', 'lazy_load_taxonomy_fields', 'request_headers', 'rest_nonce', 'rest_url' ],
            ],
            'directorist-geolocation'           => [
                'keys' => [ 'i18n_text' ],
                'i18n' => [ 'select_listing_map' ],
            ],
            'directorist-openstreet-map'        => [
                'keys' => [ 'assets_url', 'icon_class_markup', 'icon_markup', 'icon_render_mode', 'icon_url_markup', 'script_debugging' ],
            ],
            'directorist-google-map'            => [
                'keys' => [ 'assets_url', 'countryRestriction', 'icon_class_markup', 'icon_markup', 'icon_render_mode', 'icon_url_markup', 'restricted_countries', 'script_debugging' ],
            ],
            'directorist-plupload'              => [
                'keys' => [ 'assets_url', 'icon_class_markup', 'icon_markup', 'icon_render_mode', 'icon_url_markup', 'script_debugging' ],
            ],
        ];

        foreach ( $contracts as $handle => $contract ) {
            $data = [];

            foreach ( \Directorist\Asset_Loader\Localized_Data_Registry::get_modules( $handle ) as $module ) {
                $data = array_replace_recursive(
                    $data,
                    \Directorist\Asset_Loader\Localized_Data_Modules::get( $module )
                );
            }

            foreach ( $contract['keys'] as $key ) {
                $this->assertArrayHasKey( $key, $data, $handle . ' is missing directorist.' . $key );
            }

            foreach ( $contract['i18n'] ?? [] as $key ) {
                $this->assertArrayHasKey( $key, $data['i18n_text'], $handle . ' is missing directorist.i18n_text.' . $key );
            }
        }
    }

    public function test_add_listing_handle_gets_submission_data_without_search_or_login_schema() {
        $this->clear_script_data( 'directorist-add-listing' );

        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-add-listing' );

        $data = $this->get_script_data( 'directorist-add-listing' );

        $this->assertStringContainsString( 'add_listing_data', $data );
        $this->assertStringContainsString( 'category_custom_field_relations', $data );
        $this->assertStringNotContainsString( 'directory_type_term_data', $data );
        $this->assertStringNotContainsString( 'redirect_url', $data );
        $this->assertStringNotContainsString( 'review_approval_text', $data );
    }

    public function test_single_listing_handle_gets_only_its_runtime_concerns() {
        $this->clear_script_data( 'directorist-single-listing' );

        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-single-listing' );

        $data = $this->get_script_data( 'directorist-single-listing' );

        $this->assertStringContainsString( 'added_favourite', $data );
        $this->assertStringContainsString( 'review_approval_text', $data );
        $this->assertStringContainsString( 'redirect_url', $data );
        $this->assertStringContainsString( 'waiting_msg', $data );
        $this->assertStringNotContainsString( 'add_listing_data', $data );
        $this->assertStringNotContainsString( 'directory_type_term_data', $data );
        $this->assertStringNotContainsString( 'listing_remove_title', $data );
        $this->assertStringNotContainsString( 'payNow', $data );
    }

    public function test_multiple_renderers_deep_merge_modules_and_build_each_once() {
        $calls  = [];
        $filter = static function ( $data, $module ) use ( &$calls ) {
            $calls[] = $module;
            return $data;
        };
        add_filter( 'directorist_localized_data_module', $filter, 10, 2 );
        $this->clear_script_data( 'directorist-search-form' );
        $this->clear_script_data( 'directorist-single-listing' );

        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-search-form' );
        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-single-listing' );
        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-search-form' );

        remove_filter( 'directorist_localized_data_module', $filter );

        $data = $this->get_script_data( 'directorist-search-form' );

        $this->assertSame( 1, substr_count( $data, 'var directorist =' ) );
        $this->assertStringContainsString( 'w.directorist=m', $data );
        $this->assertStringContainsString( 'show_more', $data );
        $this->assertStringContainsString( 'added_favourite', $data );
        $this->assertStringContainsString( 'review_approval_text', $data );
        $this->assertSame( $calls, array_values( array_unique( $calls ) ) );
        $this->assertSame( '', $this->get_script_data( 'directorist-single-listing' ) );
    }

    public function test_localized_declaration_and_module_merges_print_before_owner_script() {
        $this->clear_script_data( 'directorist-search-form' );

        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-search-form' );
        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-single-listing' );
        wp_enqueue_script( 'directorist-search-form' );

        ob_start();
        wp_print_scripts( 'directorist-search-form' );
        $output = ob_get_clean();

        $declaration_position = strpos( $output, 'var directorist =' );
        $merge_position       = strpos( $output, 'w.directorist=m' );
        $owner_position       = strpos( $output, 'id="directorist-search-form-js"' );

        $this->assertNotFalse( $declaration_position );
        $this->assertNotFalse( $merge_position );
        $this->assertNotFalse( $owner_position );
        $this->assertLessThan( $merge_position, $declaration_position );
        $this->assertLessThan( $owner_position, $merge_position );
    }

    public function test_legacy_mode_keeps_complete_aggregate_for_mapped_handle() {
        add_filter(
            'directorist_asset_compatibility_mode',
            static function () {
                return \Directorist\Asset_Loader\Asset_Compatibility::MODE_LEGACY;
            }
        );
        $this->clear_script_data( 'directorist-search-form' );

        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-search-form' );

        $data = $this->get_script_data( 'directorist-search-form' );

        $this->assertStringContainsString( 'add_listing_data', $data );
        $this->assertStringContainsString( 'directory_type_term_data', $data );
        $this->assertStringContainsString( 'redirect_url', $data );
    }

    public function test_existing_legacy_filter_automatically_selects_complete_aggregate() {
        $filter = static function ( $data ) {
            $data['legacy_filter_marker'] = true;
            return $data;
        };
        add_filter( 'directorist_localized_data', $filter );
        $this->clear_script_data( 'directorist-search-form' );

        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-search-form' );

        remove_filter( 'directorist_localized_data', $filter );
        $data = $this->get_script_data( 'directorist-search-form' );

        $this->assertStringContainsString( 'legacy_filter_marker', $data );
        $this->assertStringContainsString( 'add_listing_data', $data );
    }

    public function test_unknown_extension_render_context_requires_legacy_data() {
        \Directorist\Asset_Loader\Asset_Compatibility::handle_render_context(
            [
                'source'    => 'extension',
                'extension' => 'directorist-unknown-extension',
            ]
        );

        $this->assertTrue( \Directorist\Asset_Loader\Asset_Compatibility::should_use_legacy_localized_data() );
        $this->assertStringContainsString( 'add_listing_data', $this->get_all_script_data() );
    }

    public function test_unknown_extension_upgrades_an_existing_modular_object() {
        $this->clear_script_data( 'directorist-search-form' );
        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-search-form' );

        \Directorist\Asset_Loader\Asset_Compatibility::handle_render_context(
            [
                'source'    => 'extension',
                'extension' => 'directorist-old-extension',
            ]
        );

        $data = $this->get_script_data( 'directorist-search-form' );

        $this->assertSame( 1, substr_count( $data, 'var directorist =' ) );
        $this->assertStringContainsString( 'w.directorist=m', $data );
        $this->assertStringContainsString( 'add_listing_data', $data );
        $this->assertStringContainsString( 'directory_type_term_data', $data );
    }

    public function test_data_aware_extension_keeps_modular_mode() {
        add_filter(
            'directorist_localized_data_aware_extensions',
            static function () {
                return [ 'directorist-aware-extension' ];
            }
        );
        add_filter(
            'directorist_asset_aware_extensions',
            static function () {
                return [ 'directorist-aware-extension' ];
            }
        );

        \Directorist\Asset_Loader\Asset_Compatibility::handle_render_context(
            [
                'source'    => 'extension',
                'extension' => 'directorist-aware-extension',
            ]
        );

        $this->assertFalse( \Directorist\Asset_Loader\Asset_Compatibility::should_use_legacy_localized_data() );
    }

    public function test_data_aware_theme_override_keeps_modular_mode() {
        add_filter( 'directorist_theme_supports_scoped_localized_data', '__return_true' );
        add_filter( 'directorist_theme_supports_scoped_assets', '__return_true' );

        \Directorist\Asset_Loader\Asset_Compatibility::handle_render_context(
            [
                'source'         => 'theme',
                'theme_override' => true,
            ]
        );

        $this->assertFalse( \Directorist\Asset_Loader\Asset_Compatibility::should_use_legacy_localized_data() );
    }

    public function test_theme_support_declaration_keeps_theme_override_in_modular_mode() {
        add_theme_support( 'directorist-scoped-localized-data' );

        \Directorist\Asset_Loader\Asset_Compatibility::handle_render_context(
            [
                'source'         => 'theme',
                'theme_override' => true,
            ]
        );

        remove_theme_support( 'directorist-scoped-localized-data' );

        $this->assertFalse( \Directorist\Asset_Loader\Asset_Compatibility::should_use_legacy_localized_data() );
    }

    public function test_strict_mode_does_not_enable_unknown_integration_fallback() {
        add_filter(
            'directorist_asset_compatibility_mode',
            static function () {
                return \Directorist\Asset_Loader\Asset_Compatibility::MODE_STRICT;
            }
        );

        \Directorist\Asset_Loader\Asset_Compatibility::handle_render_context(
            [
                'source'    => 'extension',
                'extension' => 'directorist-unknown-extension',
            ]
        );

        $this->assertFalse( \Directorist\Asset_Loader\Asset_Compatibility::should_use_legacy_localized_data() );
    }

    public function test_public_helper_emits_requested_modules_with_base_dependency() {
        $this->clear_script_data( 'directorist-account' );

        directorist_require_localized_data( 'authentication', 'directorist-account' );

        $data = $this->get_script_data( 'directorist-account' );

        $this->assertStringContainsString( 'rest_url', $data );
        $this->assertStringContainsString( 'redirect_url', $data );
        $this->assertStringNotContainsString( 'add_listing_data', $data );
    }

    public function test_module_filter_can_extend_one_module_without_forcing_legacy_mode() {
        $filter = static function ( $data, $module ) {
            if ( 'search' === $module ) {
                $data['search_module_marker'] = true;
            }

            return $data;
        };
        add_filter( 'directorist_localized_data_module', $filter, 10, 2 );
        $this->clear_script_data( 'directorist-search-form' );

        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-search-form' );

        remove_filter( 'directorist_localized_data_module', $filter );
        $data = $this->get_script_data( 'directorist-search-form' );

        $this->assertStringContainsString( 'search_module_marker', $data );
        $this->assertStringNotContainsString( 'add_listing_data', $data );
    }

    public function test_handle_registry_adds_base_dependency_and_ignores_unknown_modules() {
        $filter = static function ( $registry ) {
            $registry['directorist-test-integration'] = [ 'contact', 'not-a-module' ];
            return $registry;
        };
        add_filter( 'directorist_localized_data_handle_registry', $filter );

        $modules = \Directorist\Asset_Loader\Localized_Data_Registry::get_modules( 'directorist-test-integration' );

        remove_filter( 'directorist_localized_data_handle_registry', $filter );

        $this->assertSame( [ 'base', 'contact' ], $modules );
    }

    public function test_late_module_uses_owning_handle_after_jquery_is_done() {
        $this->clear_script_data( 'directorist-search-form' );
        wp_scripts()->done[] = 'jquery';
        wp_scripts()->done[] = 'jquery-core';

        \Directorist\Asset_Loader\Localized_Data::ensure_handle_data( 'directorist-search-form' );

        $this->assertStringContainsString( 'var directorist =', $this->get_script_data( 'directorist-search-form' ) );
    }

    public function test_print_time_rescan_catches_a_directly_enqueued_core_handle() {
        add_filter( 'directorist_should_enqueue_frontend_assets', '__return_true' );
        \Directorist\Asset_Loader\Asset_Manager::reset();
        $this->clear_script_data( 'jquery' );
        $this->clear_script_data( 'directorist-single-listing' );
        \Directorist\Asset_Loader\Localized_Data::ensure_modules( 'base', 'jquery' );
        wp_enqueue_script( 'directorist-single-listing' );

        \Directorist\Asset_Loader\Asset_Loader::localized_data();

        $data = $this->get_script_data( 'jquery' );

        $this->assertStringContainsString( 'review_approval_text', $data );
        $this->assertStringContainsString( 'redirect_url', $data );
        $this->assertStringContainsString( 'waiting_msg', $data );
        $this->assertStringNotContainsString( 'add_listing_data', $data );
    }

    public function test_localized_data_rescan_runs_before_wordpress_head_and_footer_printers() {
        $head_priority    = has_action( 'wp_print_scripts', [ \Directorist\Asset_Loader\Asset_Loader::class, 'localized_data' ] );
        $footer_priority  = has_action( 'wp_print_footer_scripts', [ \Directorist\Asset_Loader\Asset_Loader::class, 'localized_data' ] );
        $printer_priority = has_action( 'wp_print_footer_scripts', '_wp_footer_scripts' );

        $this->assertSame( 0, $head_priority );
        $this->assertSame( 0, $footer_priority );
        $this->assertIsInt( $printer_priority );
        $this->assertLessThan( $printer_priority, $footer_priority );
    }

    public function test_frontend_data_is_localized_only_on_the_first_eligible_handle() {
        wp_register_script( 'directorist-localization-first', 'https://example.test/first.js', [], '1.0.0', true );
        wp_register_script( 'directorist-localization-second', 'https://example.test/second.js', [], '1.0.0', true );

        \Directorist\Asset_Loader\Localized_Data::ensure_frontend_data( 'directorist-localization-first' );
        \Directorist\Asset_Loader\Localized_Data::ensure_frontend_data( 'directorist-localization-second' );

        $first_data  = (string) wp_scripts()->get_data( 'directorist-localization-first', 'data' );
        $second_data = (string) wp_scripts()->get_data( 'directorist-localization-second', 'data' );

        $this->assertStringContainsString( 'var directorist =', $first_data );
        $this->assertStringContainsString( 'add_listing_data', $first_data );
        $this->assertSame( '', $second_data );
    }

    public function test_done_handle_is_not_used_for_frontend_localization() {
        wp_register_script( 'directorist-localization-done', 'https://example.test/done.js', [], '1.0.0', true );
        wp_scripts()->done[] = 'directorist-localization-done';

        \Directorist\Asset_Loader\Localized_Data::ensure_frontend_data( 'directorist-localization-done' );

        $this->assertSame( '', (string) wp_scripts()->get_data( 'directorist-localization-done', 'data' ) );
    }

    protected function reset_localized_data() {
        \Directorist\Asset_Loader\Localized_Data::reset();
    }

    protected function reset_script_queues() {
        $scripts        = wp_scripts();
        $scripts->queue = [];
        $scripts->done  = [];

        foreach ( [ 'directorist-localization-first', 'directorist-localization-second', 'directorist-localization-done' ] as $handle ) {
            wp_deregister_script( $handle );
        }
    }

    protected function clear_script_data( $handle ) {
        $handle = 'jquery' === $handle ? 'jquery-core' : $handle;

        if ( isset( wp_scripts()->registered[ $handle ] ) ) {
            wp_scripts()->registered[ $handle ]->extra['data']   = '';
            wp_scripts()->registered[ $handle ]->extra['before'] = [];
        }
    }

    protected function get_script_data( $handle ) {
        $handle = 'jquery' === $handle ? 'jquery-core' : $handle;

        if ( ! isset( wp_scripts()->registered[ $handle ] ) ) {
            return '';
        }

        $extra = wp_scripts()->registered[ $handle ]->extra;

        return (string) ( $extra['data'] ?? '' ) . implode( '', (array) ( $extra['before'] ?? [] ) );
    }

    protected function get_all_script_data() {
        $data = '';

        foreach ( wp_scripts()->registered as $script ) {
            if ( ! empty( $script->extra['data'] ) ) {
                $data .= (string) $script->extra['data'];
            }

            if ( ! empty( $script->extra['before'] ) ) {
                $data .= implode( '', (array) $script->extra['before'] );
            }
        }

        return $data;
    }
}
