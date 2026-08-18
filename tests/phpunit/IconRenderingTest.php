<?php
/**
 * Behavior-lock tests for Directorist icon rendering.
 */

class Directorist_Icon_Rendering_Test extends WP_UnitTestCase {
    public function tear_down() {
        remove_all_filters( 'directorist_icon_render_mode' );
        remove_all_filters( 'directorist_icon_requires_mask_fallback' );
        remove_all_filters( 'directorist_icon_should_render_mask_fallback' );
        remove_all_actions( 'directorist_icon_rendered' );
        wp_dequeue_style( 'directorist-font-icon-base' );
        wp_deregister_style( 'directorist-font-icon-base' );
        wp_deregister_style( 'directorist-font-awesome' );
        wp_deregister_style( 'directorist-line-awesome' );

        parent::tear_down();
    }

    public function test_legacy_icon_src_api_still_returns_svg_url() {
        $icon_src = \Directorist\Helper::get_icon_src( 'fas fa-star' );

        $this->assertStringContainsString( 'assets/icons/font-awesome/svgs/solid/star.svg', $icon_src );
    }

    public function test_directorist_icon_renders_class_based_markup_without_svg_url() {
        $html = directorist_icon( 'fas fa-star', false );

        $this->assertStringContainsString( 'directorist-icon--font', $html );
        $this->assertStringContainsString( 'directorist-icon-mask', $html );
        $this->assertStringContainsString( 'fas fa-star', $html );
        $this->assertStringNotContainsString( '--directorist-icon', $html );
        $this->assertStringNotContainsString( 'url(', $html );
    }

    public function test_class_icons_enqueue_one_guard_that_disables_the_legacy_mask_pseudo_element() {
        wp_register_style( 'directorist-font-awesome', 'font-awesome.css', [], ATBDP_VERSION );
        wp_register_style( 'directorist-line-awesome', 'line-awesome.css', [], ATBDP_VERSION );

        directorist_icon( 'fas fa-anchor', false );
        directorist_icon( 'las la-adjust', false );

        $handle       = 'directorist-font-icon-base';
        $inline_style = wp_styles()->get_data( $handle, 'after' );

        $this->assertTrue( wp_style_is( $handle, 'registered' ) );
        $this->assertTrue( wp_style_is( $handle, 'enqueued' ) );
        $this->assertCount( 1, $inline_style );
        $this->assertStringContainsString( '.directorist-icon--font.directorist-icon-mask::after', $inline_style[0] );
        $this->assertStringContainsString( 'content:none!important', $inline_style[0] );
        $this->assertStringContainsString( 'display:none!important', $inline_style[0] );
    }

    public function test_localized_icon_markup_preserves_legacy_url_contract_and_exposes_class_markup() {
        $data = \Directorist\Asset_Loader\Localized_Data::get_listings_data();

        $this->assertStringContainsString( '##URL##', $data['icon_markup'] );
        $this->assertStringContainsString( '--directorist-icon', $data['icon_markup'] );
        $this->assertStringContainsString( '##CLASS##', $data['icon_class_markup'] );
        $this->assertStringContainsString( 'directorist-icon--font', $data['icon_class_markup'] );
        $this->assertStringNotContainsString( '##URL##', $data['icon_class_markup'] );
        $this->assertSame( 'auto', $data['icon_render_mode'] );
    }

    public function test_localized_icon_mode_exposes_legacy_mask_decision_to_dynamic_renderers() {
        add_filter(
            'directorist_icon_render_mode',
            static function () {
                return 'legacy_mask';
            }
        );

        $data = \Directorist\Asset_Loader\Localized_Data::get_listings_data();

        $this->assertSame( 'legacy_mask', $data['icon_render_mode'] );
        $this->assertStringContainsString( '##URL##', $data['icon_url_markup'] );
    }

    public function test_directorist_icon_preserves_extra_classes() {
        $html = directorist_icon( 'fas fa-star', false, 'star-empty' );

        $this->assertStringContainsString( 'star-empty', $html );
        $this->assertStringContainsString( 'fas fa-star', $html );
    }

    public function test_badge_icon_uses_class_markup_and_preserves_color() {
        wp_register_style( 'directorist-line-awesome', 'line-awesome.css', [], ATBDP_VERSION );

        $html = \Directorist\Helper::badge_icon_markup(
            'la la-bolt',
            [
                'badge_icon_color' => '#123456',
            ]
        );

        $this->assertStringContainsString( 'directorist-icon--font', $html );
        $this->assertStringContainsString( 'directorist-badge-icon-mask', $html );
        $this->assertStringContainsString( 'la la-bolt', $html );
        $this->assertStringContainsString( 'style="color:#123456"', $html );
        $this->assertStringNotContainsString( '--directorist-icon', $html );
        $this->assertStringNotContainsString( '.svg', $html );
    }

    public function test_badge_icon_preserves_legacy_mask_mode() {
        add_filter(
            'directorist_icon_render_mode',
            static function () {
                return 'legacy_mask';
            }
        );

        $html = \Directorist\Helper::badge_icon_markup( 'la la-bolt' );

        $this->assertStringContainsString( '--directorist-icon', $html );
        $this->assertStringContainsString( 'assets/icons/line-awesome/svgs/bolt-solid.svg', $html );
        $this->assertStringNotContainsString( 'directorist-icon--font', $html );
    }

    public function test_icon_manager_normalizes_legacy_line_awesome_classes() {
        $classes = \Directorist\Icon_Manager::get_icon_classes( 'la times' );

        $this->assertSame( 'la la-times', $classes );
    }

    public function test_icon_manager_normalizes_prefixed_line_awesome_name_classes() {
        $classes = \Directorist\Icon_Manager::get_icon_classes( 'la las-clock' );

        $this->assertSame( 'la la-clock', $classes );
    }

    public function test_unsupported_icon_rendering_falls_back_to_mask_markup() {
        $html = directorist_icon( 'uis uis-home', false );

        $this->assertStringContainsString( 'directorist-icon-mask', $html );
        $this->assertStringContainsString( '--directorist-icon', $html );
        $this->assertStringContainsString( 'assets/icons/unicons/svgs/solid/home.svg', $html );
    }

    public function test_known_missing_css_icon_falls_back_to_mask_markup() {
        $html = directorist_icon( 'fab fa-tripadvisor', false );

        $this->assertStringContainsString( 'directorist-icon-mask', $html );
        $this->assertStringContainsString( '--directorist-icon', $html );
        $this->assertStringContainsString( 'assets/icons/font-awesome/svgs/brands/tripadvisor.svg', $html );
        $this->assertStringNotContainsString( 'directorist-icon--font', $html );
    }

    public function test_legacy_mask_mode_forces_svg_mask_markup() {
        add_filter(
            'directorist_icon_render_mode',
            static function () {
                return 'legacy_mask';
            }
        );

        $html = directorist_icon( 'fas fa-star', false );

        $this->assertStringContainsString( 'directorist-icon-mask', $html );
        $this->assertStringContainsString( '--directorist-icon', $html );
        $this->assertStringContainsString( 'assets/icons/font-awesome/svgs/solid/star.svg', $html );
        $this->assertStringNotContainsString( 'directorist-icon--font', $html );
    }

    public function test_class_mode_forces_class_markup_without_svg_url_for_missing_css_icon() {
        add_filter(
            'directorist_icon_render_mode',
            static function () {
                return 'class';
            }
        );

        $html = directorist_icon( 'fab fa-tripadvisor', false );

        $this->assertStringContainsString( 'directorist-icon--font', $html );
        $this->assertStringContainsString( 'fab fa-tripadvisor', $html );
        $this->assertStringNotContainsString( '--directorist-icon', $html );
        $this->assertStringNotContainsString( 'url(', $html );
    }

    public function test_invalid_icon_mode_falls_back_to_auto() {
        add_filter(
            'directorist_icon_render_mode',
            static function () {
                return 'invalid-mode';
            }
        );

        $this->assertSame( 'auto', \Directorist\Icon_Manager::render_mode() );
    }

    public function test_missing_css_icon_filter_can_force_mask_fallback() {
        add_filter(
            'directorist_icon_requires_mask_fallback',
            static function ( $missing, $library, $prefix, $name ) {
                return 'font-awesome' === $library && 'fas' === $prefix && 'fa-star' === $name;
            },
            10,
            4
        );

        $html = directorist_icon( 'fas fa-star', false, 'forced-mask' );

        $this->assertStringContainsString( '--directorist-icon', $html );
        $this->assertStringContainsString( 'assets/icons/font-awesome/svgs/solid/star.svg', $html );
    }

    public function test_icon_manager_notifies_an_opt_in_profiler_about_render_outcomes() {
        $events   = [];
        $listener = static function ( $event ) use ( &$events ) {
            $events[] = $event;
        };

        add_action( 'directorist_icon_rendered', $listener );

        directorist_icon( 'fas fa-star', false );
        directorist_icon( 'fab fa-tripadvisor', false );
        directorist_icon( 'uis uis-home', false );

        remove_action( 'directorist_icon_rendered', $listener );

        $outcomes = array_column( $events, 'outcome' );

        $this->assertSame( 1, count( array_keys( $outcomes, 'class', true ) ) );
        $this->assertSame( 2, count( array_keys( $outcomes, 'mask', true ) ) );
        $this->assertCount( 1, array_filter( array_column( $events, 'missing_css' ) ) );
        $this->assertCount( 1, array_filter( array_column( $events, 'unsupported' ) ) );
    }
}
