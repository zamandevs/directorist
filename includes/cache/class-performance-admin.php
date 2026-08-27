<?php

namespace Directorist\Cache;

/**
 * Scoped shell and assets for the Directorist Performance SPA.
 */
final class Performance_Admin {
    const PAGE_SLUG     = 'directorist-performance';
    const HOOK_SUFFIX   = 'at_biz_dir_page_directorist-performance';
    const SCRIPT_HANDLE = 'directorist-performance-admin';
    const STYLE_HANDLE  = 'directorist-performance-admin';
    const ROOT_ID       = 'directorist-performance-app';

    /** @var bool */
    private $registered = false;

    /** @return bool */
    public function register() {
        if ( $this->registered ) {
            return false;
        }

        add_action( 'admin_menu', [ $this, 'add_menu' ], 80 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'admin_body_class', [ $this, 'admin_body_class' ] );
        $this->registered = true;

        return true;
    }

    /** @return void */
    public function add_menu() {
        add_submenu_page(
            'edit.php?post_type=' . ATBDP_POST_TYPE,
            __( 'Directorist Performance', 'directorist' ),
            __( 'Performance', 'directorist' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ],
            14
        );
    }

    /**
     * @param string $hook_suffix Current admin hook.
     * @return bool
     */
    public function enqueue_assets( $hook_suffix ) {
        if ( self::HOOK_SUFFIX !== $hook_suffix ) {
            return false;
        }

        $asset_path       = ATBDP_DIR . 'assets/build/js/react/admin/performance.asset.php';
        $style_path       = ATBDP_DIR . 'assets/build/css/admin/performance.css';
        $style_asset_path = ATBDP_DIR . 'assets/build/css/admin/performance.asset.php';
        $asset            = is_file( $asset_path ) ? include $asset_path : [];
        $dependencies     = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
            ? $asset['dependencies']
            : [ 'wp-api-fetch', 'wp-components', 'wp-dom-ready', 'wp-element', 'wp-i18n', 'wp-icons' ];
        $version          = isset( $asset['version'] ) ? (string) $asset['version'] : ATBDP_VERSION;
        $style_version    = $this->asset_version( $style_asset_path, $style_path, $version );

        wp_enqueue_style( 'wp-components' );
        wp_enqueue_style(
            self::STYLE_HANDLE,
            ATBDP_URL . 'assets/build/css/admin/performance.css',
            [ 'wp-components' ],
            $style_version
        );
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            ATBDP_URL . 'assets/build/js/react/admin/performance.js',
            $dependencies,
            $version,
            true
        );
        wp_set_script_translations( self::SCRIPT_HANDLE, 'directorist' );
        wp_add_inline_script(
            self::SCRIPT_HANDLE,
            'window.directoristPerformance = ' . wp_json_encode(
                [
                    'rootId'   => self::ROOT_ID,
                    'restPath' => '/directorist/v1/admin/performance',
                    'nonce'    => wp_create_nonce( 'wp_rest' ),
                    'homeUrl'  => home_url( '/' ),
                ]
            ) . ';',
            'before'
        );

        return true;
    }

    /**
     * Resolve one asset version without coupling CSS and JavaScript browser caches.
     *
     * @param string $manifest_path Generated asset manifest.
     * @param string $asset_path Generated asset file.
     * @param string $fallback Fallback version.
     * @return string
     */
    private function asset_version( $manifest_path, $asset_path, $fallback ) {
        if ( is_file( $manifest_path ) ) {
            try {
                $manifest = include $manifest_path;
            } catch ( \Throwable $exception ) {
                unset( $exception );
                $manifest = [];
            }

            if ( is_array( $manifest ) && ! empty( $manifest['version'] ) ) {
                return (string) $manifest['version'];
            }
        }

        $modified = is_file( $asset_path ) ? filemtime( $asset_path ) : false;

        return false === $modified ? (string) $fallback : (string) $modified;
    }

    /**
     * @param string      $classes Existing body classes.
     * @param string|null $hook_suffix Explicit test boundary.
     * @return string
     */
    public function admin_body_class( $classes, $hook_suffix = null ) {
        if ( null === $hook_suffix ) {
            $screen      = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            $hook_suffix = $screen && isset( $screen->id ) ? $screen->id : '';
        }

        if ( self::HOOK_SUFFIX !== $hook_suffix ) {
            return $classes;
        }

        return trim( $classes . ' directorist-settings-redesign-page directorist-performance-page' );
    }

    /** @return void */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap directorist-performance-wrap">
            <div id="<?php echo esc_attr( self::ROOT_ID ); ?>" class="directorist-performance-app">
                <p class="directorist-performance-app__loading"><?php esc_html_e( 'Loading Directorist Performance...', 'directorist' ); ?></p>
            </div>
            <noscript><?php esc_html_e( 'Directorist Performance requires JavaScript in the WordPress admin.', 'directorist' ); ?></noscript>
        </div>
        <?php
    }
}
