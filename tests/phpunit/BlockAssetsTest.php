<?php
/**
 * Behavior-lock tests for Directorist block asset declarations.
 */

class Directorist_Block_Assets_Test extends WP_UnitTestCase {
    public function tear_down() {
        remove_all_filters( 'directorist_asset_compatibility_mode' );

        parent::tear_down();
    }

    public function test_block_frontend_auto_mode_declares_only_base_style() {
        $styles = directorist_get_block_frontend_style_handles();

        $this->assertContains( 'directorist-main-style', $styles );
        $this->assertNotContains( 'directorist-select2-style', $styles );
        $this->assertNotContains( 'directorist-swiper-style', $styles );
        $this->assertNotContains( 'directorist-ez-media-uploader-style', $styles );
        $this->assertNotContains( 'directorist-sweetalert-style', $styles );
    }

    public function test_block_frontend_legacy_mode_keeps_broad_style_compatibility() {
        add_filter(
            'directorist_asset_compatibility_mode',
            static function () {
                return 'legacy';
            }
        );

        $styles = directorist_get_block_frontend_style_handles();

        $this->assertContains( 'directorist-main-style', $styles );
        $this->assertContains( 'directorist-select2-style', $styles );
        $this->assertContains( 'directorist-swiper-style', $styles );
        $this->assertContains( 'directorist-ez-media-uploader-style', $styles );
        $this->assertContains( 'directorist-sweetalert-style', $styles );
    }

    public function test_block_editor_styles_keep_broad_style_compatibility() {
        $styles = directorist_get_block_editor_style_handles();

        $this->assertContains( 'directorist-main-style', $styles );
        $this->assertContains( 'directorist-select2-style', $styles );
        $this->assertContains( 'directorist-swiper-style', $styles );
    }
}
