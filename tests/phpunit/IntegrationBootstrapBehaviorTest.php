<?php
/**
 * Behavior locks for bundled and optional integration bootstrap boundaries.
 */

class Directorist_Integration_Bootstrap_Behavior_Test extends WP_UnitTestCase {
    public function test_multilingual_initializer_keeps_its_existing_hook_contract() {
        $this->assertTrue( class_exists( 'Directorist_Multilingual' ) );
        $this->assertSame( 20, has_action( 'plugins_loaded', [ Directorist_Multilingual::class, 'init' ] ) );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_absent_polylang_provider_defers_adapter_but_keeps_direct_class_access() {
        $adapter_file = wp_normalize_path( DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/classes/class-multilingual-polylang.php' );
        $files        = array_map( 'wp_normalize_path', get_included_files() );

        $this->assertFalse( class_exists( 'Directorist_Multilingual_Polylang', false ) );
        $this->assertNotContains( $adapter_file, $files );
        $this->assertTrue( class_exists( 'Directorist_Multilingual_Polylang' ) );

        $reflection = new ReflectionClass( 'Directorist_Multilingual_Polylang' );

        $this->assertSame( $adapter_file, wp_normalize_path( $reflection->getFileName() ) );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_polylang_adapter_keeps_hooks_priorities_and_taxonomy_results() {
        if ( ! function_exists( 'PLL' ) ) {
            function PLL() {
                return null;
            }
        }

        $adapter = new Directorist_Multilingual_Polylang();

        $this->assertSame( 10, has_filter( 'atbdp_import_default_directory', '__return_false' ) );
        $this->assertSame( 10, has_filter( 'pll_get_post_types', [ $adapter, 'enable_translation_to_custom_post_types' ] ) );
        $this->assertSame( 10, has_filter( 'pll_get_taxonomies', [ $adapter, 'enable_translation_to_custom_taxonomies' ] ) );
        $this->assertSame( 10, has_filter( 'directorist_register_directory_taxonomy_args', [ $adapter, 'polylang_directory_taxonomy_args' ] ) );
        $this->assertSame( 20, has_filter( 'directorist_localized_data', [ $adapter, 'polylang_localized_data' ] ) );
        $this->assertSame( 20, has_action( 'directorist_ajax_before_request_handling', [ $adapter, 'polylang_switch_current_language_in_ajax' ] ) );
        $this->assertSame( 50, has_filter( 'post_type_link', [ $adapter, 'polylang_switch_permalinks_language_in_ajax' ] ) );
        $this->assertSame( 20, has_filter( 'pll_the_language_link', [ $adapter, 'term_language_link_update' ] ) );

        $post_types = $adapter->enable_translation_to_custom_post_types( [ 'post' => 'post' ] );
        $taxonomies = $adapter->enable_translation_to_custom_taxonomies( [ 'category' => 'category' ] );

        $this->assertSame( ATBDP_POST_TYPE, $post_types[ ATBDP_POST_TYPE ] );
        $this->assertSame( ATBDP_DIRECTORY_TYPE, $taxonomies[ ATBDP_DIRECTORY_TYPE ] );
        $this->assertSame( ATBDP_CATEGORY, $taxonomies[ ATBDP_CATEGORY ] );
        $this->assertSame( ATBDP_LOCATION, $taxonomies[ ATBDP_LOCATION ] );
        $this->assertSame( ATBDP_TAGS, $taxonomies[ ATBDP_TAGS ] );
        $this->assertTrue( $adapter->polylang_directory_taxonomy_args( [] )['show_ui'] );
    }

    public function test_frontend_bootstrap_does_not_initialize_appsero_or_wpml() {
        $this->assertFalse( class_exists( 'Directorist\\Appsero\\Client', false ) );
        $this->assertFalse( class_exists( 'Directorist\\Appsero\\Insights', false ) );
        $this->assertFalse( has_filter( 'wpml_current_language' ) );
        $this->assertNull( apply_filters( 'wpml_current_language', null ) );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_appsero_public_service_and_registration_contract_remain_available() {
        directorist()->init_appsero();

        $insights = directorist()->insights;
        $client   = $this->get_protected_property( $insights, 'client' );

        $this->assertInstanceOf( Directorist\Appsero\Insights::class, $insights );
        $this->assertInstanceOf( Directorist\Appsero\Client::class, $client );
        $this->assertSame( 10, has_filter( 'plugin_action_links_' . $client->basename, [ $insights, 'plugin_action_links' ] ) );
        $this->assertSame( 10, has_action( 'admin_footer', [ $insights, 'deactivate_scripts' ] ) );
        $this->assertSame( 10, has_action( 'admin_notices', [ $insights, 'admin_notice' ] ) );
        $this->assertSame( 10, has_action( 'admin_init', [ $insights, 'handle_optin_optout' ] ) );
        $this->assertSame( 10, has_action( 'wp_ajax_' . $client->slug . '_submit-uninstall-reason', [ $insights, 'uninstall_reason_submission' ] ) );
        $this->assertSame( 10, has_filter( 'cron_schedules', [ $insights, 'add_weekly_schedule' ] ) );
        $this->assertSame( 10, has_action( $client->slug . '_tracker_send_event', [ $insights, 'send_tracking_data' ] ) );
    }

    private function get_protected_property( $object, $property_name ) {
        $reflection = new ReflectionObject( $object );
        $property   = $reflection->getProperty( $property_name );

        $property->setAccessible( true );

        return $property->getValue( $object );
    }
}
