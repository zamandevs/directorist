<?php
/**
 * Base class for loading all assets.
 *
 * @author wpWax
 */

namespace Directorist\Asset_Loader;

defined( 'ABSPATH' ) || exit;

use Directorist\Utils\Enqueue\Enqueue;

class Asset_Loader {
    /**
     * Initialize
     *
     * @return void
     */
    public static function init() {
        // Frontend scripts
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_scripts' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ], 12 );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_single_listing_scripts' ], 12 );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'localized_data' ], 15 );

        add_action( 'enqueue_block_assets', [ __CLASS__, 'register_scripts' ] );
        add_action( 'enqueue_block_assets', [ __CLASS__, 'localized_data' ], 15 );
        add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_inline_style' ], 15 );

        // Admin Scripts
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'register_scripts' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_scripts' ], 12 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'localized_data' ], 15 );

        // Enqueue conditional scripts depending on loaded template
        add_action( 'directorist_before_template_render', [ __CLASS__, 'load_template_scripts' ], 10, 4 );
        add_action( 'wp_footer', [ Asset_Manager::class, 'flush_footer_assets' ], 5 );
    }

    /**
     * Enqueue request-level frontend styles.
     *
     * @return void
     */
    public static function enqueue_styles() {
        Asset_Manager::enqueue_frontend_assets();
    }

    /**
     * Enqueue scripts in Single listing page.
     *
     * @return void
     */
    public static function enqueue_single_listing_scripts() {
        Asset_Manager::enqueue_single_listing_assets();
    }

    /**
     * Enqueue conditional scripts depending on loaded template.
     *
     * @param string $template
     * @param string $file
     * @param array  $args
     *
     * @return void
     */
    public static function load_template_scripts( $template, $file = '', $args = [], $context = [] ) {
        Asset_Compatibility::handle_render_context( $context );
        Asset_Manager::enqueue_template_assets( $template, $args );
    }

    /**
     * Enqueue conditional admin scripts depending on current admin screen.
     *
     * @return void
     */
    public static function admin_scripts( string $hook_suffix ) {

        if ( Helper::is_admin_page( 'builder-archive' ) ) {
            wp_enqueue_style( 'directorist-unicons' );
            wp_enqueue_style( 'directorist-admin-style' );
            wp_enqueue_script( 'directorist-admin-script' );
            wp_enqueue_script( 'directorist-admin-builder-archive' );
            wp_enqueue_script( 'directorist-tooltip' );
        } elseif ( Helper::is_admin_page( 'builder-edit' ) ) {
            wp_enqueue_style( 'directorist-admin-style' );
            wp_enqueue_script( 'directorist-icon-picker' );
            wp_enqueue_style( 'directorist-unicons' );
            wp_enqueue_script( 'directorist-multi-directory-builder' );
            wp_enqueue_script( 'wp-tinymce' );
            wp_enqueue_script( 'wp-media' );
            wp_enqueue_media();
            do_action( 'directorist_builder_edit_assets_enqueued', $hook_suffix );
        } elseif ( Helper::is_admin_page( 'settings' ) ) {
            wp_enqueue_style( 'directorist-admin-style' );
            wp_enqueue_style( 'directorist-unicons' );
            wp_enqueue_script( 'directorist-settings-manager' );
            wp_enqueue_media();
        } elseif ( Helper::is_admin_page( 'extensions' ) ) {
            wp_enqueue_style( 'directorist-admin-style' );
            wp_enqueue_script( 'directorist-admin-script' );
            wp_enqueue_script( 'directorist-tooltip' );

            // Inline styles
            $load_inline_style = apply_filters( 'directorist_load_inline_style', true );
            if ( $load_inline_style ) {
                wp_add_inline_style( 'directorist-admin-style', Helper::dynamic_style() );
            }
        } elseif ( Helper::is_admin_page( 'wp-plugins' ) ) {
            wp_enqueue_style( 'directorist-admin-style' );
            wp_enqueue_script( 'directorist-plugins' );
        } elseif ( Helper::is_admin_page( 'wp-users' ) ) {
            wp_enqueue_script( 'directorist-admin-script' );
        } elseif ( Helper::is_admin_page( 'taxonomy' ) ) {
            wp_enqueue_style( 'directorist-admin-style' );
            wp_enqueue_script( 'directorist-admin-script' );
            wp_enqueue_script( 'directorist-icon-picker' );
            wp_enqueue_script( 'directorist-tooltip' );
            wp_enqueue_style( 'directorist-select2-style' );
            wp_enqueue_script( 'directorist-select2-script' );
            wp_enqueue_media();
        } elseif ( Helper::is_admin_page( 'import_export' ) ) {
            wp_enqueue_style( 'directorist-admin-style' );
            wp_enqueue_script( 'directorist-admin-script' );
            wp_enqueue_script( 'directorist-import-export' );
        } elseif ( Helper::is_admin_page( 'all_listings' ) ) {
            wp_enqueue_style( 'directorist-font-awesome' );
            wp_enqueue_style( 'directorist-admin-style' );
            wp_enqueue_script( 'directorist-admin-script' );
        } elseif ( Helper::is_admin_page( 'add_listing' ) ) {
            global $pagenow;

            wp_enqueue_style( 'directorist-admin-style' );
            wp_enqueue_style( 'directorist-unicons' );
            wp_enqueue_script( 'directorist-admin-script' );
            wp_enqueue_script( 'directorist-plupload' );
            wp_enqueue_script( 'directorist-select2-script' );
            wp_enqueue_script( 'directorist-add-listing' );
            wp_enqueue_media();

            if ( in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) && function_exists( 'wp_enqueue_editor' ) ) {
                wp_enqueue_editor();
            }

            wp_enqueue_script( 'iris', admin_url( 'js/iris.min.js' ), [ 'jquery-ui-draggable', 'jquery-ui-slider', 'jquery-touch-punch' ], Helper::get_script_version() );
            wp_enqueue_script( 'wp-color-picker', admin_url( 'js/color-picker.min.js' ), [ 'iris', 'wp-i18n' ], Helper::get_script_version() );

            self::enqueue_map_styles();

            // Inline styles
            $load_inline_style = apply_filters( 'directorist_load_inline_style', true );
            if ( $load_inline_style ) {
                wp_add_inline_style( 'directorist-admin-style', Helper::dynamic_style() );
            }
        }

        if ( 'at_biz_dir_page_directorist-orders' === $hook_suffix ) {
            Enqueue::style( 'directorist/admin-order-dataview', 'build/css/admin/style-app', ['wp-components'] );
            Enqueue::style( 'directorist/admin-app', 'build/css/admin/app' );
            Enqueue::script( 'directorist/admin-order', 'build/js/react/admin/order' );
        
            $c_position = directorist_get_currency_position();
            $currency   = directorist_get_currency();
            $symbol     = atbdp_currency_symbol( $currency );
        
            wp_localize_script(
                'directorist/admin-order', 'directorist_admin_order', [
                    'symbol_position' => $c_position,
                    'currency'        => $currency,
                    'symbol'          => $symbol,
                    'admin_url'       => admin_url(),
                ]
            );
            wp_enqueue_style( 'directorist-admin-style' );
        }

        if ( 'at_biz_dir_page_directorist-notifications-pro-log' === $hook_suffix ) {
            wp_enqueue_style( 'directorist-admin-style' );
        }
    }

    public static function register_scripts() {
        Helper::register_all_scripts( Scripts::get_all_scripts() );
        Asset_Manager::register_runtime_assets();
        Asset_Manager::flush_pending_assets();
    }

    /**
     * Preserve dynamic frontend styling inside the block editor.
     *
     * @return void
     */
    public static function enqueue_block_editor_inline_style() {
        Asset_Manager::add_main_inline_style();
    }

    public static function localized_data() {
        Localized_Data::load_localized_data();
    }

    public static function enqueue_map_styles() {
        if ( Helper::map_type() === 'openstreet' ) {
            wp_enqueue_style( 'directorist-openstreet-map-leaflet' );
            wp_enqueue_style( 'directorist-openstreet-map-openstreet' );
        }
    }

    public static function enqueue_map_scripts() {
        if ( Helper::map_type() === 'openstreet' ) {
            wp_enqueue_script( 'directorist-openstreet-map' );
        } elseif ( Helper::map_type() === 'google' ) {
            wp_enqueue_script( 'directorist-google-map' );
        }
    }
}
