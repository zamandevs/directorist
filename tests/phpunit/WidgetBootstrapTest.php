<?php
/**
 * Tests request-scoped classic widget registration.
 */

class Directorist_Widget_Bootstrap_Test extends WP_UnitTestCase {
    public function test_widget_hook_registrar_is_idempotent_and_instance_api_remains_available() {
        Directorist\Widgets\Init::register_hooks();
        Directorist\Widgets\Init::register_hooks();

        $this->assertSame( 10, has_action( 'widgets_init', [ Directorist\Widgets\Init::class, 'register_widgets' ] ) );
        $this->assertSame( Directorist\Widgets\Init::instance(), Directorist\Widgets\Init::instance() );
    }

    public function test_admin_compatibility_mode_returns_full_widget_catalog() {
        $widgets = Directorist\Widgets\Init::get_widgets_for_request( [], true );

        $this->assertCount( 13, $widgets );
        $this->assertArrayHasKey( 'bdpl_widget', $widgets );
        $this->assertArrayHasKey( 'bdmw_widget', $widgets );
    }

    public function test_frontend_without_active_directorist_widgets_registers_none() {
        $widgets = Directorist\Widgets\Init::get_widgets_for_request(
            [
                'sidebar-1' => [ 'search-1', 'text-2' ],
            ],
            false
        );

        $this->assertSame( [], $widgets );
    }

    public function test_frontend_registers_only_active_directorist_widget_classes() {
        $widgets = Directorist\Widgets\Init::get_widgets_for_request(
            [
                'right-sidebar-listing' => [ 'bdpl_widget-2', 'bdmw_widget-4' ],
                'wp_inactive_widgets'   => [ 'bdco_widget-3' ],
            ],
            false
        );

        $this->assertSame( [ 'bdpl_widget', 'bdmw_widget' ], array_keys( $widgets ) );
        $this->assertSame( Directorist\Widgets\Popular_Listings::class, $widgets['bdpl_widget']['class'] );
        $this->assertSame( Directorist\Widgets\Single_Map::class, $widgets['bdmw_widget']['class'] );
    }

    public function test_widget_classes_remain_autoloadable_for_direct_usage() {
        $this->assertTrue( class_exists( Directorist\Widgets\Search_Form::class ) );
        $this->assertTrue( is_subclass_of( Directorist\Widgets\Search_Form::class, WP_Widget::class ) );
    }
}
