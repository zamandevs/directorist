<?php
/**
 * Renderer-level asset requirement manager.
 *
 * @author wpWax
 */

namespace Directorist\Asset_Loader;

defined( 'ABSPATH' ) || exit;

use Directorist\Icon_Manager;
use Directorist\Utils\Enqueue\Enqueue;

class Asset_Manager {
    protected static $frontend_request = null;

    protected static $required_assets = [];

    protected static $late_styles = [];

    protected static $pending_styles = [];

    protected static $pending_scripts = [];

    protected static $main_inline_style_added = false;

    protected static $dashboard_orders_localized = false;

    protected static $directorist_page_options = [
        'search_listing',
        'search_result_page',
        'add_listing_page',
        'all_listing_page',
        'all_categories_page',
        'single_category_page',
        'all_locations_page',
        'single_location_page',
        'single_tag_page',
        'author_profile_page',
        'user_dashboard',
        'custom_registration',
        'user_login',
        'signin_signup_page',
        'checkout_page',
        'payment_receipt_page',
        'transaction_failure_page',
    ];

    /**
     * Enqueue request-level frontend styles.
     *
     * @return void
     */
    public static function enqueue_frontend_assets() {
        if ( ! self::is_frontend_request() ) {
            return;
        }

        self::enqueue_main_style();

        $libraries = (bool) get_directorist_option( 'legacy_icon' )
            ? [ 'line-awesome', 'font-awesome', 'unicons' ]
            : [ 'line-awesome', 'font-awesome' ];

        Icon_Manager::enqueue_core_icon_styles( $libraries );
    }

    /**
     * Enqueue scripts owned by a single-listing request.
     *
     * @return void
     */
    public static function enqueue_single_listing_assets() {
        if ( ! defined( 'ATBDP_POST_TYPE' ) || ! is_singular( ATBDP_POST_TYPE ) ) {
            return;
        }

        self::require_script( 'directorist-single-listing', 'single-listing', 'singular-request' );

        if ( directorist_is_review_enabled() ) {
            wp_enqueue_script( 'wp-hooks' );
            wp_enqueue_script( 'comment-reply' );
            self::require_script( 'directorist-jquery-barrating', 'single-listing', 'review-render' );
        }
    }

    /**
     * Enqueue assets owned by a rendered Directorist template.
     *
     * @param string $template Template key.
     * @param array  $args     Template arguments.
     *
     * @return void
     */
    public static function enqueue_template_assets( $template, $args = [] ) {
        if ( empty( $template ) ) {
            return;
        }

        $context = [ 'template' => $template ];

        self::enqueue_main_style( 'template-render', $context );

        $field_assets = Asset_Registry::get_field_requirements( $template, $args );

        if ( $field_assets ) {
            self::require_asset( $field_assets, 'field-template', $context );
        }

        if ( Helper::is_widget_template( $template ) ) {
            self::require_asset( 'widgets', 'widget-template', $context );
        }

        $template_assets = Asset_Registry::get_template_requirements( $template );

        if ( $template_assets ) {
            self::require_asset( $template_assets, 'template-render', $context );
        }

        if ( 'all-authors' === $template || 'archive/grid-view' === $template ) {
            wp_enqueue_script( 'jquery-masonry' );
        } elseif ( in_array( $template, [ 'archive-contents', 'sidebar-archive-contents' ], true ) && Helper::instant_search_enabled() ) {
            wp_enqueue_script( 'jquery-masonry' );
        }
    }

    /**
     * Determine whether the current frontend request contains Directorist output.
     *
     * @return bool
     */
    public static function is_frontend_request() {
        if ( is_admin() ) {
            return false;
        }

        if ( null !== self::$frontend_request ) {
            return self::$frontend_request;
        }

        $contexts = self::detect_frontend_contexts();
        $detected = ! empty( $contexts );

        self::$frontend_request = Asset_Compatibility::should_enqueue_frontend_assets( $detected, (array) $contexts );

        return self::$frontend_request;
    }

    /**
     * Enqueue one or more logical assets.
     *
     * @param string|array $assets  Asset key or list of keys.
     * @param string       $reason  Why the asset is required.
     * @param array        $context Optional render/request context.
     *
     * @return void
     */
    public static function require_asset( $assets, $reason = 'manual', $context = [] ) {
        foreach ( (array) $assets as $asset ) {
            $asset = sanitize_key( $asset );

            if ( ! $asset ) {
                continue;
            }

            self::$required_assets[ $asset ] = true;
            self::record( $asset, '', 'asset', $reason, $context );

            if ( 'map' === $asset ) {
                self::require_map_assets( $reason, $context );
                continue;
            }

            if ( 'geolocation' === $asset ) {
                self::require_geolocation_assets( $reason, $context );
                continue;
            }

            if ( 'media-uploader' === $asset ) {
                self::require_media_uploader_assets( $reason, $context );
                continue;
            }

            if ( 'color-picker' === $asset ) {
                self::require_color_picker_assets( $reason, $context );
                continue;
            }

            if ( 'dashboard-orders' === $asset ) {
                self::require_dashboard_orders_assets( $reason, $context );
                continue;
            }

            $requirements = Asset_Registry::get_requirements( $asset );

            foreach ( $requirements['assets'] as $dependency ) {
                self::require_asset( $dependency, 'dependency:' . $asset, $context );
            }

            foreach ( $requirements['styles'] as $style ) {
                self::require_style( $style, $asset, $reason, $context );
            }

            foreach ( $requirements['scripts'] as $script ) {
                self::require_script( $script, $asset, $reason, $context );
            }
        }
    }

    /**
     * Get logical assets required in the current request.
     *
     * @return array
     */
    public static function get_required_assets() {
        return array_keys( self::$required_assets );
    }

    /**
     * Get styles requested after head styles printed.
     *
     * @return array
     */
    public static function get_late_styles() {
        return self::$late_styles;
    }

    /**
     * Resolve handles registered after the initial asset pass, then print styles.
     *
     * @return void
     */
    public static function flush_footer_assets() {
        self::flush_pending_assets();
        self::print_late_styles();
    }

    /**
     * Print styles first requested after the normal head style pass.
     *
     * @return void
     */
    public static function print_late_styles() {
        if ( ! self::$late_styles ) {
            return;
        }

        $handles = array_filter(
            array_keys( self::$late_styles ),
            static function ( $handle ) {
                return ! wp_style_is( $handle, 'done' );
            }
        );

        if ( $handles ) {
            wp_print_styles( $handles );
        }
    }

    /**
     * Reset request-local asset state. Intended for tests.
     *
     * @return void
     */
    public static function reset() {
        self::$frontend_request           = null;
        self::$required_assets            = [];
        self::$late_styles                = [];
        self::$pending_styles             = [];
        self::$pending_scripts            = [];
        self::$main_inline_style_added    = false;
        self::$dashboard_orders_localized = false;
    }

    /**
     * Enqueue the shared frontend stylesheet and generate its dynamic CSS once.
     *
     * @return void
     */
    public static function enqueue_main_style( $reason = 'request-detected', $context = [] ) {
        self::require_style( 'directorist-main-style', 'base', $reason, $context );
        self::add_main_inline_style();
    }

    /**
     * Attach the dynamic stylesheet only when the main stylesheet is used.
     *
     * @return void
     */
    public static function add_main_inline_style() {
        if ( self::$main_inline_style_added || ! wp_style_is( 'directorist-main-style', 'registered' ) ) {
            return;
        }

        if ( ! apply_filters( 'directorist_load_inline_style', true ) ) {
            return;
        }

        wp_add_inline_style( 'directorist-main-style', Helper::dynamic_style() );
        self::$main_inline_style_added = true;
    }

    /**
     * Register runtime assets that are only enqueued by explicit render paths.
     *
     * @return void
     */
    public static function register_runtime_assets() {
        Enqueue::register_style( 'directorist/frontend', 'build/css/public/app', [ 'wp-components' ] );
        Enqueue::register_script( 'directorist-payment-receipt', 'build/js/react/frontend/payment-receipt.js', [ 'jquery', 'wp-api-fetch' ], true );
        Enqueue::register_script( 'directorist-listing-owner-dashboard', 'build/js/react/frontend/listing-owner-dashboard', [], true );
    }

    /**
     * Flush renderer assets requested before WordPress handles were registered.
     *
     * @return void
     */
    public static function flush_pending_assets() {
        $pending_styles  = self::$pending_styles;
        $pending_scripts = self::$pending_scripts;

        self::$pending_styles  = [];
        self::$pending_scripts = [];

        foreach ( $pending_styles as $item ) {
            self::require_style( $item['handle'], $item['asset'], $item['reason'], $item['context'] );
        }

        foreach ( $pending_scripts as $item ) {
            self::require_script( $item['handle'], $item['asset'], $item['reason'], $item['context'] );

            if ( 'directorist-listing-owner-dashboard' === $item['handle'] ) {
                self::localize_dashboard_orders();
            }
        }
    }

    /**
     * Enqueue a renderer-level style and track late requests.
     *
     * @param string $handle  Style handle.
     * @param string $asset   Logical asset key.
     * @param string $reason  Why the asset is required.
     * @param array  $context Optional render/request context.
     *
     * @return void
     */
    public static function require_style( $handle, $asset = '', $reason = 'manual', $context = [] ) {
        if ( ! $handle || wp_style_is( $handle, 'done' ) ) {
            return;
        }

        if ( ! wp_style_is( $handle, 'registered' ) ) {
            self::queue_pending_style( $handle, $asset, $reason, $context );
            return;
        }

        if ( did_action( 'wp_print_styles' ) ) {
            self::$late_styles[ $handle ] = true;
        }

        if ( ! wp_style_is( $handle, 'enqueued' ) ) {
            wp_enqueue_style( $handle );
        }

        self::record( $asset, $handle, 'style', $reason, $context );
    }

    /**
     * Enqueue a renderer-level script.
     *
     * @param string $handle  Script handle.
     * @param string $asset   Logical asset key.
     * @param string $reason  Why the asset is required.
     * @param array  $context Optional render/request context.
     *
     * @return void
     */
    public static function require_script( $handle, $asset = '', $reason = 'manual', $context = [] ) {
        if ( ! $handle || wp_script_is( $handle, 'done' ) ) {
            return;
        }

        if ( ! wp_script_is( $handle, 'registered' ) ) {
            self::queue_pending_script( $handle, $asset, $reason, $context );
            return;
        }

        Localized_Data::ensure_frontend_data( $handle );

        if ( 'directorist-formgent-integration' === $handle ) {
            Localized_Data::ensure_formgent_data();
        }

        if ( ! wp_script_is( $handle, 'enqueued' ) ) {
            wp_enqueue_script( $handle );
        }

        self::record( $asset, $handle, 'script', $reason, $context );
    }

    protected static function require_map_assets( $reason, $context ) {
        if ( Helper::map_type() === 'openstreet' ) {
            self::require_style( 'directorist-openstreet-map-leaflet', 'map', $reason, $context );
            self::require_style( 'directorist-openstreet-map-openstreet', 'map', $reason, $context );
            self::require_script( 'directorist-openstreet-map', 'map', $reason, $context );
        } elseif ( Helper::map_type() === 'google' ) {
            self::require_script( 'directorist-google-map', 'map', $reason, $context );
        }
    }

    protected static function require_geolocation_assets( $reason, $context ) {
        if ( Helper::map_type() === 'google' ) {
            self::require_script( 'google-map-api', 'geolocation', $reason, $context );
        }

        self::require_script( 'directorist-geolocation', 'geolocation', $reason, $context );
    }

    protected static function require_media_uploader_assets( $reason, $context ) {
        $requirements = Asset_Registry::get_requirements( 'media-uploader' );

        foreach ( $requirements['styles'] as $style ) {
            self::require_style( $style, 'media-uploader', $reason, $context );
        }

        foreach ( $requirements['scripts'] as $script ) {
            self::require_script( $script, 'media-uploader', $reason, $context );
        }

        wp_enqueue_media();
    }

    protected static function require_color_picker_assets( $reason, $context ) {
        self::require_style( 'wp-color-picker', 'color-picker', $reason, $context );

        wp_enqueue_script( 'iris', admin_url( 'js/iris.min.js' ), [ 'jquery-ui-draggable', 'jquery-ui-slider', 'jquery-touch-punch' ], Helper::get_script_version() );
        wp_enqueue_script( 'wp-color-picker', admin_url( 'js/color-picker.min.js' ), [ 'iris', 'wp-i18n' ], Helper::get_script_version() );

        self::record( 'color-picker', 'iris', 'script', $reason, $context );
        self::record( 'color-picker', 'wp-color-picker', 'script', $reason, $context );
    }

    protected static function require_dashboard_orders_assets( $reason, $context ) {
        self::require_style( 'directorist/frontend', 'dashboard-orders', $reason, $context );
        self::require_script( 'directorist-listing-owner-dashboard', 'dashboard-orders', $reason, $context );

        self::localize_dashboard_orders();
    }

    /**
     * Localize the owner order bundle after its handle is registered.
     *
     * @return void
     */
    protected static function localize_dashboard_orders() {
        if ( self::$dashboard_orders_localized || ! wp_script_is( 'directorist-listing-owner-dashboard', 'registered' ) ) {
            return;
        }

        $currency = directorist_get_currency();

        wp_localize_script(
            'directorist-listing-owner-dashboard',
            'directorist_admin_order',
            [
                'checkout_page_url' => get_permalink( get_directorist_option( 'checkout_page', 0 ) ),
                'symbol_position'   => directorist_get_currency_position(),
                'currency'          => $currency,
                'symbol'            => atbdp_currency_symbol( $currency ),
            ]
        );
        self::$dashboard_orders_localized = true;
    }

    protected static function record( $asset, $handle, $type, $reason, $context ) {
        if ( ! defined( 'DIRECTORIST_ASSET_PROFILING' ) || ! DIRECTORIST_ASSET_PROFILING ) {
            return;
        }

        do_action(
            'directorist_asset_requirement_recorded',
            [
                'asset'   => $asset,
                'handle'  => $handle,
                'type'    => $type,
                'reason'  => $reason,
                'context' => is_array( $context ) ? $context : [],
            ]
        );
    }

    protected static function queue_pending_style( $handle, $asset, $reason, $context ) {
        self::$pending_styles[ $handle ] = [
            'handle'  => $handle,
            'asset'   => $asset,
            'reason'  => $reason,
            'context' => is_array( $context ) ? $context : [],
        ];

        self::record( $asset, $handle, 'style-pending', $reason, $context );
    }

    protected static function queue_pending_script( $handle, $asset, $reason, $context ) {
        self::$pending_scripts[ $handle ] = [
            'handle'  => $handle,
            'asset'   => $asset,
            'reason'  => $reason,
            'context' => is_array( $context ) ? $context : [],
        ];

        self::record( $asset, $handle, 'script-pending', $reason, $context );
    }

    protected static function detect_frontend_contexts() {
        $contexts = [];

        if ( defined( 'ATBDP_POST_TYPE' ) && is_singular( ATBDP_POST_TYPE ) ) {
            $contexts[] = 'single';
        }

        if ( defined( 'ATBDP_POST_TYPE' ) && is_post_type_archive( ATBDP_POST_TYPE ) ) {
            $contexts[] = 'archive';
        }

        if ( defined( 'ATBDP_CATEGORY' ) && defined( 'ATBDP_LOCATION' ) && defined( 'ATBDP_TAGS' ) && is_tax( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ] ) ) {
            $contexts[] = 'taxonomy';
        }

        if ( self::is_configured_directorist_page() ) {
            $contexts[] = 'configured-page';
        }

        $post = get_post();

        if ( $post && self::content_contains_directorist_output( $post->post_content ) ) {
            $contexts[] = 'content';
        }

        return array_values( array_unique( $contexts ) );
    }

    protected static function is_configured_directorist_page() {
        $page_id = get_queried_object_id();

        if ( ! $page_id ) {
            return false;
        }

        foreach ( self::$directorist_page_options as $option ) {
            if ( $page_id === absint( get_directorist_option( $option ) ) ) {
                return true;
            }
        }

        return false;
    }

    protected static function content_contains_directorist_output( $content ) {
        if ( ! is_string( $content ) || '' === $content ) {
            return false;
        }

        foreach ( [ '[directorist_', '[atbdp_', '<!-- wp:directorist/', '<!-- wp:directorist-gb-integration/' ] as $needle ) {
            if ( false !== strpos( $content, $needle ) ) {
                return true;
            }
        }

        return false;
    }
}
