<?php
/**
 * Behavior locks for the Directorist SEO bootstrap boundary.
 */

class Directorist_SEO_Bootstrap_Behavior_Test extends WP_UnitTestCase {
    private $original_active_plugins;

    private $original_options;

    public function set_up() {
        parent::set_up();

        $this->original_active_plugins = get_option( 'active_plugins', [] );
        $this->original_options        = get_option( 'atbdp_option', [] );
    }

    public function tear_down() {
        update_option( 'atbdp_option', $this->original_options );
        update_option( 'active_plugins', $this->original_active_plugins );
        set_query_var( 'atbdp_category', '' );
        wp_reset_postdata();

        parent::tear_down();
    }

    public function test_disabled_bootstrap_defers_the_legacy_seo_class_and_instance() {
        $reflection = new ReflectionClass( directorist() );
        $property   = $reflection->getProperty( 'seo' );

        $property->setAccessible( true );

        $this->assertFalse( class_exists( 'ATBDP_SEO', false ) );
        $this->assertNull( $property->getValue( directorist() ) );
        $this->assertFalse( has_action( 'wp', [ directorist(), 'initialize_seo' ] ) );
    }

    public function test_public_seo_service_is_accessible_and_stable() {
        $seo = directorist()->seo;

        $this->assertInstanceOf( ATBDP_SEO::class, $seo );
        $this->assertSame( $seo, directorist()->seo );
        $this->assertTrue( isset( directorist()->seo ) );

        $replacement       = new ATBDP_SEO();
        directorist()->seo = $replacement;

        $this->assertSame( $replacement, directorist()->seo );

        directorist()->seo = $seo;
    }

    public function test_enabled_service_registers_existing_head_and_title_callbacks() {
        $this->enable_seo();

        $seo = new ATBDP_SEO();
        $seo->setup_seo();

        $this->assertSame( 10, has_filter( 'the_title', [ $seo, 'update_taxonomy_page_title' ] ) );
        $this->assertSame( 10, has_filter( 'single_post_title', [ $seo, 'update_taxonomy_single_page_title' ] ) );
        $this->assertSame( 10, has_filter( 'pre_get_document_title', [ $seo, 'atbdp_custom_page_title' ] ) );
        $this->assertSame( 10, has_action( 'wp_head', [ $seo, 'atbdp_add_meta_keywords' ] ) );
        $this->assertSame( 10, has_action( 'wp_head', [ $seo, 'add_opengraph_meta' ] ) );
        $this->assertSame( 10, has_action( 'wp_head', [ $seo, 'add_texonomy_canonical' ] ) );
        $this->assertSame( 10, has_action( 'wp', [ $seo, 'remove_duplicate_canonical' ] ) );
        $this->assertSame( 1, $this->callback_registration_count( 'wp_head', [ $seo, 'atbdp_add_meta_keywords' ] ) );
    }

    public function test_enabled_service_preserves_meta_description_on_an_ordinary_page() {
        $this->enable_seo();

        $page_id = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Ordinary Page',
                'post_excerpt' => 'Ordinary page SEO description.',
            ]
        );

        $this->go_to( get_permalink( $page_id ) );
        $GLOBALS['post'] = get_post( $page_id );
        setup_postdata( $GLOBALS['post'] );

        $seo = new ATBDP_SEO();

        ob_start();
        $seo->atbdp_add_meta_keywords();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'name="description"', $output );
        $this->assertStringContainsString( 'Ordinary page SEO description.', $output );
        $this->assertSame( '', ATBDP_SEO::get_directorist_current_page() );
    }

    public function test_yoast_compatibility_callbacks_keep_their_public_contract() {
        update_option( 'active_plugins', [ 'wordpress-seo/wp-seo.php' ] );

        $seo = new ATBDP_SEO( true );
        $seo->setup_seo();

        $this->assertSame( 10, has_filter( 'wpseo_title', [ $seo, 'wpseo_title' ] ) );
        $this->assertSame( 10, has_filter( 'wpseo_metadesc', [ $seo, 'wpseo_metadesc' ] ) );
        $this->assertSame( 10, has_filter( 'wpseo_canonical', [ $seo, 'directorist_canonical' ] ) );
        $this->assertSame( 10, has_filter( 'wpseo_opengraph_url', [ $seo, 'directorist_canonical' ] ) );
        $this->assertSame( 10, has_filter( 'wpseo_sitemap_exclude_taxonomy', [ $seo, 'yoast_sitemap_exclude_taxonomy' ] ) );
    }

    public function test_rank_math_compatibility_callbacks_keep_their_public_contract() {
        update_option( 'active_plugins', [ 'seo-by-rank-math/rank-math.php' ] );

        $seo = new ATBDP_SEO( true );
        $seo->setup_seo();

        $this->assertSame( 20, has_filter( 'rank_math/frontend/title', [ $seo, 'optimize_rankmath_frontend_meta_title' ] ) );
        $this->assertSame( 20, has_filter( 'rank_math/frontend/description', [ $seo, 'optimize_rankmath_frontend_meta_description' ] ) );
        $this->assertSame( 20, has_filter( 'rank_math/frontend/canonical', [ $seo, 'directorist_canonical' ] ) );
    }

    private function enable_seo() {
        $options                     = get_option( 'atbdp_option', [] );
        $options['atbdp_enable_seo'] = 1;

        update_option( 'atbdp_option', $options );
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
