<?php
/**
 * Asset compatibility policy for mixed core/extension/theme versions.
 *
 * @author wpWax
 */

namespace Directorist\Asset_Loader;

defined( 'ABSPATH' ) || exit;

class Asset_Compatibility {
    const MODE_AUTO   = 'auto';
    const MODE_LEGACY = 'legacy';
    const MODE_STRICT = 'strict';

    /**
     * Get current compatibility mode.
     *
     * @return string
     */
    public static function mode() {
        $mode = defined( 'DIRECTORIST_ASSET_COMPATIBILITY_MODE' ) ? DIRECTORIST_ASSET_COMPATIBILITY_MODE : self::MODE_AUTO;
        $mode = apply_filters( 'directorist_asset_compatibility_mode', $mode );
        $mode = is_string( $mode ) ? sanitize_key( $mode ) : self::MODE_AUTO;

        if ( ! in_array( $mode, [ self::MODE_AUTO, self::MODE_LEGACY, self::MODE_STRICT ], true ) ) {
            return self::MODE_AUTO;
        }

        return $mode;
    }

    /**
     * Whether compatibility fallback is disabled.
     *
     * @return bool
     */
    public static function is_strict() {
        return self::MODE_STRICT === self::mode();
    }

    /**
     * Whether legacy fallback should be forced.
     *
     * @return bool
     */
    public static function is_legacy() {
        return self::MODE_LEGACY === self::mode();
    }

    /**
     * Filterable frontend request decision.
     *
     * @param bool  $is_directorist Whether Directorist was detected.
     * @param array $features       Detected request features.
     *
     * @return bool
     */
    public static function should_enqueue_frontend_assets( $is_directorist, $features = [] ) {
        if ( self::is_legacy() ) {
            return true;
        }

        return (bool) apply_filters( 'directorist_should_enqueue_frontend_assets', $is_directorist, $features, self::mode() );
    }

    /**
     * Apply asset requirements/fallbacks for a normalized render context.
     *
     * @param array $context Render context.
     *
     * @return void
     */
    public static function handle_render_context( $context ) {
        $context         = is_array( $context ) ? $context : [];
        $fallback_assets = self::get_fallback_assets_for_context( $context );

        if ( $fallback_assets ) {
            Asset_Manager::require_asset( $fallback_assets, 'compatibility-fallback', $context );
        }
    }

    /**
     * Preserve the pre-scoping asset contract for external renderers that
     * manually emit before_directorist_template_loaded.
     *
     * @param string $template Template key.
     * @param array  $args     Template arguments.
     * @param array  $context  Legacy render context.
     *
     * @return void
     */
    public static function handle_legacy_template_hook( $template, $args = [], $context = [] ) {
        $args    = is_array( $args ) ? $args : [];
        $context = is_array( $context ) ? $context : [];

        Asset_Manager::enqueue_template_assets( $template, $args );

        $assets = self::legacy_template_extra_assets( $template, $args, $context );

        if ( in_array( $template, [ 'archive-contents', 'sidebar-archive-contents' ], true ) && Helper::instant_search_enabled() ) {
            $assets[] = 'map';
        }

        if ( $assets ) {
            Asset_Manager::require_asset( array_values( array_unique( $assets ) ), 'legacy-template-hook', $context );
        }

        if ( 'listing-form/add-listing' === $template && function_exists( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }
    }

    /**
     * Get fallback assets for legacy/unknown render paths.
     *
     * @param array $context Render context.
     *
     * @return array
     */
    protected static function get_fallback_assets_for_context( $context ) {
        if ( self::is_strict() ) {
            return [];
        }

        if ( self::is_legacy() ) {
            return self::legacy_fallback_assets( 'legacy', $context );
        }

        $source = ! empty( $context['source'] ) ? $context['source'] : 'unknown';

        if ( 'extension' === $source ) {
            $extension = ! empty( $context['extension'] ) ? $context['extension'] : '';

            if ( self::extension_supports_scoped_assets( $extension, $context ) ) {
                return [];
            }

            $assets = self::extension_fallback_assets( $extension );

            return $assets ? $assets : self::legacy_fallback_assets( 'extension', $context );
        }

        if ( ! empty( $context['theme_override'] ) || 'theme' === $source ) {
            if ( self::theme_supports_scoped_assets( $context ) ) {
                return [];
            }

            return self::legacy_fallback_assets( 'theme', $context );
        }

        return [];
    }

    /**
     * First-party extension fallback registry.
     *
     * @return array
     */
    protected static function extension_registry() {
        $registry = [
            'directorist-booking'               => [ 'select2', 'sweetalert', 'range-slider' ],
            'directorist-business-hours'        => [ 'select2' ],
            'directorist-gb-integration'        => [ 'select2', 'listing-slider' ],
            'directorist-elementor-clean-fixed' => [ 'select2', 'listing-slider' ],
            'directorist-divi'                  => [ 'select2', 'listing-slider' ],
            'directorist-pricing-plans'         => [ 'checkout' ],
            'directorist-advanced-review'       => [ 'select2' ],
        ];

        return apply_filters( 'directorist_first_party_extension_asset_registry', $registry );
    }

    protected static function extension_fallback_assets( $extension ) {
        $registry = self::extension_registry();
        $assets   = [];

        if ( $extension && ! empty( $registry[ $extension ] ) ) {
            $assets = $registry[ $extension ];
        }

        return self::normalize_asset_list( $assets );
    }

    /**
     * Get only the broad assets removed from a template by renderer scoping.
     * The primary template requirements still come from Asset_Registry.
     *
     * @param string $template Template key.
     * @param array  $args     Template arguments.
     * @param array  $context  Legacy render context.
     *
     * @return array
     */
    protected static function legacy_template_extra_assets( $template, $args, $context ) {
        $requirements = [
            'archive-contents'             => [ 'listing-slider', 'select2', 'search-form' ],
            'sidebar-archive-contents'     => [ 'listing-slider', 'select2', 'search-form' ],
            'archive/search-form'          => [ 'select2', 'listing-slider' ],
            'archive/advance-search-form'  => [ 'select2', 'listing-slider' ],
            'archive/advanced-search-form' => [ 'select2', 'listing-slider' ],
            'archive/basic-search-form'    => [ 'select2', 'listing-slider' ],
            'archive/mobile-search-form'   => [ 'select2', 'listing-slider' ],
            'search-form-contents'         => [ 'select2', 'listing-slider' ],
            'search-form/adv-search'       => [ 'select2', 'listing-slider' ],
            'search-form/basic-search'     => [ 'select2', 'listing-slider' ],
            'search-form/form-box'         => [ 'select2', 'listing-slider' ],
            'widgets/search-form'          => [ 'select2', 'listing-slider' ],
            'listing-form/add-listing'     => [ 'select2' ],
            'dashboard-contents'           => [ 'dashboard-orders' ],
            'archive/grid-view'            => [ 'listing-slider' ],
            'search-form/fields/location'  => [ 'geolocation' ],
        ];

        $assets = $requirements[ $template ] ?? [];

        return self::normalize_asset_list(
            apply_filters( 'directorist_legacy_template_asset_requirements', $assets, $template, $args, $context )
        );
    }

    /**
     * Determine whether an extension has migrated every frontend renderer to
     * explicit asset requirements.
     *
     * Extensions declare support by adding their plugin-directory slug to the
     * directorist_asset_aware_extensions filter. Unknown/older extensions keep
     * the conservative automatic fallback.
     *
     * @param string $extension Plugin-directory slug.
     * @param array  $context   Render context.
     *
     * @return bool
     */
    protected static function extension_supports_scoped_assets( $extension, $context ) {
        if ( ! $extension ) {
            return false;
        }

        $extensions = self::normalize_asset_list(
            apply_filters( 'directorist_asset_aware_extensions', [], $context )
        );

        return in_array( sanitize_key( $extension ), $extensions, true );
    }

    /**
     * Determine whether the active theme declares its Directorist overrides
     * compatible with renderer-scoped assets.
     *
     * @param array $context Render context.
     *
     * @return bool
     */
    protected static function theme_supports_scoped_assets( $context ) {
        $supported = function_exists( 'current_theme_supports' )
            && current_theme_supports( 'directorist-scoped-assets' );

        return (bool) apply_filters( 'directorist_theme_supports_scoped_assets', $supported, $context );
    }

    protected static function legacy_fallback_assets( $scope, $context ) {
        $fallback = [
            'legacy'    => [ 'select2', 'listing-slider', 'sweetalert', 'media-uploader', 'plupload', 'range-slider' ],
            'extension' => [ 'select2', 'listing-slider', 'sweetalert', 'range-slider' ],
            'theme'     => [ 'select2', 'listing-slider', 'sweetalert' ],
        ];

        $assets = ! empty( $fallback[ $scope ] ) ? $fallback[ $scope ] : [];

        return self::normalize_asset_list(
            apply_filters( 'directorist_legacy_fallback_assets', $assets, $scope, $context, self::mode() )
        );
    }

    protected static function normalize_asset_list( $assets ) {
        $normalized = [];

        foreach ( (array) $assets as $asset ) {
            $asset = sanitize_key( $asset );

            if ( $asset ) {
                $normalized[] = $asset;
            }
        }

        return array_values( array_unique( $normalized ) );
    }
}
