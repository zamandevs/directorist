<?php
/**
 * Verifies the WordPress test harness loads Directorist correctly.
 */

class Directorist_Bootstrap_Test extends WP_UnitTestCase {
    public function test_directorist_plugin_constants_are_loaded() {
        $this->assertTrue( defined( 'ATBDP_VERSION' ) );
        $this->assertTrue( defined( 'ATBDP_POST_TYPE' ) );
        $this->assertSame( 'at_biz_dir', ATBDP_POST_TYPE );
    }

    public function test_directorist_registers_core_content_types() {
        $this->assertTrue( post_type_exists( ATBDP_POST_TYPE ) );
        $this->assertTrue( taxonomy_exists( ATBDP_CATEGORY ) );
        $this->assertTrue( taxonomy_exists( ATBDP_LOCATION ) );
        $this->assertTrue( taxonomy_exists( ATBDP_TYPE ) );
    }

    public function test_directorist_registers_core_shortcodes() {
        global $shortcode_tags;

        $this->assertArrayHasKey( 'directorist_all_listing', $shortcode_tags );
        $this->assertArrayHasKey( 'directorist_search_listing', $shortcode_tags );
        $this->assertArrayHasKey( 'directorist_add_listing', $shortcode_tags );
    }
}
