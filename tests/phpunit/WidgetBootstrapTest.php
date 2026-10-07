<?php
/**
 * Tests classic widget registration compatibility.
 */

class Directorist_Widget_Bootstrap_Test extends WP_UnitTestCase {
    public function test_widget_singleton_owns_the_wordpress_registration_hook() {
        $widgets = Directorist\Widgets\Init::instance();

        $this->assertSame( 10, has_action( 'widgets_init', [ $widgets, 'register_widgets' ] ) );
        $this->assertSame( $widgets, Directorist\Widgets\Init::instance() );
    }

    public function test_all_directorist_widgets_are_registered_with_wordpress() {
        global $wp_widget_factory;

        $definitions = Directorist\Widgets\Init::get_widget_definitions();

        $this->assertCount( 13, $definitions );

        foreach ( $definitions as $definition ) {
            $this->assertArrayHasKey( $definition['class'], $wp_widget_factory->widgets );
            $this->assertInstanceOf( WP_Widget::class, $wp_widget_factory->widgets[ $definition['class'] ] );
        }
    }

    public function test_the_widget_can_render_a_directorist_widget() {
        wp_set_current_user( 0 );

        ob_start();
        the_widget( Directorist\Widgets\Login_Form::class, [ 'single_only' => 0 ] );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'directorist-widget-authentication', $output );
    }

    public function test_rest_widget_type_catalog_contains_directorist_widgets() {
        $controller = new WP_REST_Widget_Types_Controller();
        $request    = new WP_REST_Request( 'GET', '/wp/v2/widget-types/bdsw_widget' );
        $request->set_param( 'id', 'bdsw_widget' );

        $response = $controller->get_item( $request );

        $this->assertNotWPError( $response );
        $this->assertSame( 'bdsw_widget', $response->get_data()['id'] );
    }
}
