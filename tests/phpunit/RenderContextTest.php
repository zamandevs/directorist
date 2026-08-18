<?php
/**
 * Behavior-lock tests for normalized Directorist render context.
 */

class Directorist_Render_Context_Test extends WP_UnitTestCase {
    protected $temp_dirs = [];

    public function set_up() {
        parent::set_up();

        $this->reset_asset_state();
        \Directorist\Asset_Loader\Asset_Loader::register_scripts();
    }

    public function tear_down() {
        foreach ( $this->temp_dirs as $dir ) {
            $file = $dir . '/fixture.php';

            if ( file_exists( $file ) ) {
                unlink( $file );
            }

            if ( is_dir( $dir ) ) {
                rmdir( $dir );
            }
        }

        remove_all_filters( 'directorist_asset_aware_extensions' );
        remove_all_filters( 'directorist_theme_supports_scoped_assets' );
        remove_theme_support( 'directorist-scoped-assets' );
        $this->reset_asset_state();

        parent::tear_down();
    }

    public function test_render_context_detects_theme_override_path() {
        $theme_dir        = $this->create_temp_template_dir();
        $theme_dir_filter = static function () use ( $theme_dir ) {
            return $theme_dir;
        };

        add_filter( 'stylesheet_directory', $theme_dir_filter );
        add_filter( 'template_directory', $theme_dir_filter );

        $file    = trailingslashit( $theme_dir ) . 'fixture.php';
        $context = \Directorist\Asset_Loader\Render_Context::before( 'archive/grid-view', $file );

        remove_filter( 'stylesheet_directory', $theme_dir_filter );
        remove_filter( 'template_directory', $theme_dir_filter );

        $this->assertSame( 'theme', $context['source'] );
        $this->assertTrue( $context['theme_override'] );
        $this->assertSame( 'archive/grid-view', $context['template'] );
    }

    public function test_extension_template_helper_fires_normalized_render_hooks() {
        $dir = $this->create_temp_template_dir();

        $captured_before = null;
        $captured_after  = null;

        $before = static function ( $template, $file, $args, $context ) use ( &$captured_before ) {
            if ( 'fixture' === $template ) {
                $captured_before = $context;
            }
        };

        $after = static function ( $template, $file, $args, $context ) use ( &$captured_after ) {
            if ( 'fixture' === $template ) {
                $captured_after = $context;
            }
        };

        add_action( 'directorist_before_template_render', $before, 20, 4 );
        add_action( 'directorist_after_template_render', $after, 20, 4 );

        ob_start();
        atbdp_get_extension_template( $dir, 'fixture', 'directorist-fixture', [ 'value' => 'yes' ] );
        $html = ob_get_clean();

        remove_action( 'directorist_before_template_render', $before, 20 );
        remove_action( 'directorist_after_template_render', $after, 20 );

        $this->assertSame( 'fixture', trim( $html ) );
        $this->assertIsArray( $captured_before );
        $this->assertIsArray( $captured_after );
        $this->assertSame( 'extension', $captured_before['source'] );
        $this->assertSame( 'fixture', $captured_before['template'] );
        $this->assertSame( $captured_before['file'], $captured_after['file'] );
    }

    public function test_auto_mode_applies_extension_compatibility_fallback() {
        \Directorist\Asset_Loader\Asset_Compatibility::handle_render_context(
            [
                'source'    => 'extension',
                'template'  => 'fixture',
                'extension' => 'directorist-fixture',
            ]
        );

        $this->assertTrue( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'directorist-select2-script', 'enqueued' ) );
        $this->assertContains( 'select2', directorist_get_required_assets() );
    }

    public function test_known_extension_registry_can_scope_fallback_assets() {
        \Directorist\Asset_Loader\Asset_Compatibility::handle_render_context(
            [
                'source'    => 'extension',
                'template'  => 'business-hours',
                'extension' => 'directorist-business-hours',
            ]
        );

        $this->assertContains( 'select2', directorist_get_required_assets() );
        $this->assertNotContains( 'listing-slider', directorist_get_required_assets() );
    }

    public function test_migrated_extension_declaration_disables_automatic_fallback_for_exact_slug() {
        add_filter(
            'directorist_asset_aware_extensions',
            static function ( $extensions ) {
                $extensions[] = 'directorist-fixture';
                return $extensions;
            }
        );

        \Directorist\Asset_Loader\Asset_Compatibility::handle_render_context(
            [
                'source'    => 'extension',
                'template'  => 'fixture',
                'extension' => 'directorist-fixture',
            ]
        );

        $this->assertSame( [], directorist_get_required_assets() );

        \Directorist\Asset_Loader\Asset_Compatibility::handle_render_context(
            [
                'source'    => 'extension',
                'template'  => 'older-extension-template',
                'extension' => 'directorist-older-fixture',
            ]
        );

        $this->assertContains( 'select2', directorist_get_required_assets() );
    }

    public function test_migrated_theme_declaration_disables_theme_override_fallback() {
        add_theme_support( 'directorist-scoped-assets' );

        \Directorist\Asset_Loader\Asset_Compatibility::handle_render_context(
            [
                'source'         => 'theme',
                'template'       => 'single/slider',
                'theme_override' => true,
            ]
        );

        $this->assertSame( [], directorist_get_required_assets() );
    }

    public function test_extension_slug_is_detected_from_plugin_directory_path() {
        $extension_dir = trailingslashit( WP_PLUGIN_DIR ) . 'directorist-render-fixture-' . wp_generate_uuid4();
        mkdir( $extension_dir );
        $this->temp_dirs[] = $extension_dir;

        $this->assertSame(
            sanitize_key( basename( $extension_dir ) ),
            \Directorist\Asset_Loader\Render_Context::extension_from_file( $extension_dir )
        );
    }

    public function test_strict_mode_disables_extension_compatibility_fallback() {
        $mode = static function () {
            return 'strict';
        };

        add_filter( 'directorist_asset_compatibility_mode', $mode );

        \Directorist\Asset_Loader\Asset_Compatibility::handle_render_context(
            [
                'source'    => 'extension',
                'template'  => 'fixture',
                'extension' => 'directorist-fixture',
            ]
        );

        remove_filter( 'directorist_asset_compatibility_mode', $mode );

        $this->assertFalse( wp_style_is( 'directorist-select2-style', 'enqueued' ) );
        $this->assertSame( [], directorist_get_required_assets() );
    }

    protected function create_temp_template_dir() {
        $dir = sys_get_temp_dir() . '/directorist-render-context-' . wp_generate_uuid4();
        mkdir( $dir );
        file_put_contents( $dir . '/fixture.php', '<?php echo "fixture";' );

        $this->temp_dirs[] = $dir;

        return $dir;
    }

    protected function reset_asset_state() {
        \Directorist\Asset_Loader\Asset_Manager::reset();

        $wp_scripts = wp_scripts();
        $wp_styles  = wp_styles();

        $wp_scripts->queue = [];
        $wp_scripts->done  = [];
        $wp_styles->queue  = [];
        $wp_styles->done   = [];
    }
}
