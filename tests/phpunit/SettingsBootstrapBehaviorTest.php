<?php
/**
 * Behavior locks for the Directorist settings bootstrap boundary.
 */

class Directorist_Settings_Bootstrap_Behavior_Test extends WP_UnitTestCase {
    private $original_options;

    public function set_up() {
        parent::set_up();

        $this->original_options = get_option( 'atbdp_option', [] );
    }

    public function tear_down() {
        update_option( 'atbdp_option', $this->original_options );
        set_current_screen( 'front' );

        parent::tear_down();
    }

    public function test_default_bootstrap_uses_the_lightweight_lifecycle_boundary() {
        $reflection = new ReflectionClass( directorist() );
        $property   = $reflection->getProperty( 'settings_panel' );

        $property->setAccessible( true );

        $this->assertFalse( class_exists( 'ATBDP_Settings_Panel', false ) );
        $this->assertNull( $property->getValue( directorist() ) );
        $this->assertSame( 10, has_action( 'directorist_installed', [ Directorist_Base::class, 'update_settings_init_options' ] ) );
        $this->assertSame( 10, has_action( 'directorist_updated', [ Directorist_Base::class, 'update_settings_init_options' ] ) );
    }

    public function test_public_settings_service_is_accessible_stable_and_mutable() {
        $settings = directorist()->settings_panel;

        $this->assertInstanceOf( ATBDP_Settings_Panel::class, $settings );
        $this->assertSame( $settings, directorist()->settings_panel );
        $this->assertTrue( isset( directorist()->settings_panel ) );

        $replacement = new ATBDP_Settings_Panel();

        directorist()->settings_panel = $replacement;

        $this->assertSame( $replacement, directorist()->settings_panel );

        directorist()->settings_panel = $settings;
    }

    public function test_lifecycle_callbacks_preserve_identity_priority_and_option_result() {
        $settings = directorist()->settings_panel;
        $settings->run();

        $this->assertSame( 10, has_action( 'directorist_installed', [ $settings, 'update_init_options' ] ) );
        $this->assertSame( 10, has_action( 'directorist_updated', [ $settings, 'update_init_options' ] ) );

        update_directorist_option( 'lazy_load_taxonomy_fields', null );
        $settings->update_init_options();

        $this->assertSame( directorist_has_no_listing(), (bool) get_directorist_option( 'lazy_load_taxonomy_fields' ) );
    }

    public function test_admin_registration_keeps_existing_callbacks_and_one_effective_save_handler() {
        set_current_screen( 'edit-at_biz_dir' );

        $settings = new ATBDP_Settings_Panel();
        $settings->run();

        $this->assertSame( 10, has_action( 'admin_menu', [ $settings, 'add_menu_pages' ] ) );
        $this->assertSame( 10, has_action( 'wp_ajax_save_settings_data', [ $settings, 'handle_save_settings_data_request' ] ) );
        $this->assertSame( 1, $this->callback_registration_count( 'wp_ajax_save_settings_data', [ $settings, 'handle_save_settings_data_request' ] ) );
        $this->assertSame( 10, has_filter( 'atbdp_listing_type_settings_field_list', [ $settings, 'register_setting_fields' ] ) );
        $this->assertSame( 20, has_filter( 'elementor/editor-one/menu/theme_builder_url', [ $settings, 'fix_elementor_theme_builder_url' ] ) );

        $fields = $settings->register_setting_fields( [] );

        $this->assertArrayHasKey( 'script_debugging', $fields );
        $this->assertArrayHasKey( 'enable_multi_directory', $fields );
        $this->assertArrayHasKey( 'restore_default_settings', $fields );
    }

    private function callback_registration_count( $hook_name, $callback ) {
        global $wp_filter;

        if ( empty( $wp_filter[ $hook_name ]->callbacks ) ) {
            return 0;
        }

        $count = 0;
        foreach ( $wp_filter[ $hook_name ]->callbacks as $callbacks ) {
            foreach ( $callbacks as $registered ) {
                if ( $registered['function'] === $callback ) {
                    ++$count;
                }
            }
        }

        return $count;
    }
}
