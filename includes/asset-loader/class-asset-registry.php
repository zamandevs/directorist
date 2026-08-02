<?php
/**
 * Logical frontend asset registry.
 *
 * @author wpWax
 */

namespace Directorist\Asset_Loader;

defined( 'ABSPATH' ) || exit;

class Asset_Registry {
    /**
     * Logical asset requirements.
     *
     * Keep this map handle-focused and policy-free. Compatibility decisions
     * belong in Asset_Compatibility; enqueue execution belongs in Asset_Manager.
     *
     * @var array
     */
    protected static $asset_requirements = [
        'select2'               => [
            'styles'  => [ 'directorist-select2-style' ],
            'scripts' => [ 'directorist-select2-script' ],
        ],
        'swiper'                => [
            'styles'  => [ 'directorist-swiper-style' ],
            'scripts' => [ 'directorist-swiper' ],
        ],
        'listing-slider'        => [
            'assets'  => [ 'swiper' ],
            'scripts' => [ 'directorist-listing-slider' ],
        ],
        'sweetalert'            => [
            'styles'  => [ 'directorist-sweetalert-style' ],
            'scripts' => [ 'directorist-sweetalert' ],
        ],
        'media-uploader'        => [
            'styles'  => [ 'directorist-ez-media-uploader-style' ],
            'scripts' => [ 'directorist-ez-media-uploader' ],
        ],
        'plupload'              => [
            'scripts' => [ 'directorist-plupload' ],
        ],
        'range-slider'          => [
            'scripts' => [ 'directorist-range-slider' ],
        ],
        'geolocation'           => [
            'scripts' => [ 'directorist-geolocation' ],
        ],
        'formgent'              => [
            'styles'  => [ 'directorist-formgent-integration-style' ],
            'scripts' => [ 'directorist-formgent-integration' ],
        ],
        'widgets'               => [
            'scripts' => [ 'directorist-widgets' ],
        ],
        'all-listings'          => [
            'scripts' => [ 'directorist-all-listings' ],
        ],
        'search-form'           => [
            'scripts' => [ 'directorist-search-form' ],
        ],
        'dashboard'             => [
            'scripts' => [ 'directorist-dashboard' ],
        ],
        'all-authors'           => [
            'scripts' => [ 'directorist-all-authors' ],
        ],
        'author-profile'        => [
            'scripts' => [ 'directorist-author-profile' ],
        ],
        'all-location-category' => [
            'scripts' => [ 'directorist-all-location-category' ],
        ],
        'account'               => [
            'scripts' => [ 'directorist-account' ],
        ],
        'checkout'              => [
            'scripts' => [ 'directorist-checkout' ],
        ],
        'add-listing'           => [
            'scripts' => [ 'directorist-add-listing' ],
        ],
    ];

    protected static $field_assets = [
        'address'       => [ 'geolocation' ],
        'category'      => [ 'select2' ],
        'categories'    => [ 'select2' ],
        'color_picker'  => [ 'color-picker' ],
        'file'          => [ 'plupload' ],
        'image_upload'  => [ 'media-uploader' ],
        'location'      => [ 'select2' ],
        'locations'     => [ 'select2' ],
        'map'           => [ 'map' ],
        'number_range'  => [ 'range-slider' ],
        'radius_search' => [ 'range-slider' ],
        'select'        => [ 'select2' ],
        'social_info'   => [ 'sweetalert' ],
        'tag'           => [ 'select2' ],
        'tags'          => [ 'select2' ],
    ];

    protected static $template_field_types = [
        'listing-form/fields/address'               => 'address',
        'listing-form/fields/category'              => 'category',
        'listing-form/fields/image_upload'          => 'image_upload',
        'listing-form/fields/location'              => 'location',
        'listing-form/fields/map'                   => 'map',
        'listing-form/fields/social_info'           => 'social_info',
        'listing-form/fields/tag'                   => 'tag',
        'listing-form/custom-fields/color_picker'   => 'color_picker',
        'listing-form/custom-fields/file'           => 'file',
        'search-form/fields/category'               => 'category',
        'search-form/fields/location'               => 'location',
        'search-form/fields/radius_search'          => 'radius_search',
        'search-form/custom-fields/color_picker'    => 'color_picker',
        'search-form/custom-fields/number/dropdown' => 'select',
        'search-form/custom-fields/number/range'    => 'number_range',
        'search-form/custom-fields/select'          => 'select',
    ];

    protected static $template_assets = [
        'archive-contents'                => [ 'all-listings' ],
        'sidebar-archive-contents'        => [ 'all-listings' ],
        'archive/search-form'             => [ 'search-form' ],
        'archive/advance-search-form'     => [ 'search-form' ],
        'archive/basic-search-form'       => [ 'search-form' ],
        'archive/mobile-search-form'      => [ 'search-form' ],
        'search-form-contents'            => [ 'search-form' ],
        'search-form/adv-search'          => [ 'search-form' ],
        'search-form/basic-search'        => [ 'search-form' ],
        'search-form/form-box'            => [ 'search-form' ],
        'widgets/search-form'             => [ 'search-form' ],
        'listing-form/add-listing'        => [ 'add-listing' ],
        'dashboard-contents'              => [ 'dashboard', 'select2', 'formgent' ],
        'dashboard/tab-orders'            => [ 'dashboard-orders' ],
        'all-authors'                     => [ 'all-authors' ],
        'author-contents'                 => [ 'listing-slider', 'author-profile' ],
        'taxonomies/categories-grid'      => [ 'all-location-category' ],
        'taxonomies/categories-list'      => [ 'all-location-category' ],
        'taxonomies/locations-grid'       => [ 'all-location-category' ],
        'taxonomies/locations-list'       => [ 'all-location-category' ],
        'archive/map-view'                => [ 'map' ],
        'single/fields/map'               => [ 'map' ],
        'widgets/single-map'              => [ 'map' ],
        'dashboard/profile-pic'           => [ 'media-uploader' ],
        'dashboard/listing-row'           => [ 'sweetalert' ],
        'single/slider'                   => [ 'listing-slider' ],
        'single/section-related_listings' => [ 'listing-slider' ],
        'account/login'                   => [ 'account' ],
        'account/registration'            => [ 'account' ],
        'account/login-registration-form' => [ 'account' ],
        'payment/checkout'                => [ 'checkout' ],
        'payment/payment-receipt'         => [ 'checkout' ],
        'payment/transaction-failure'     => [ 'checkout' ],
    ];

    /**
     * Get requirements for a logical asset key.
     *
     * @param string $asset Asset key.
     *
     * @return array
     */
    public static function get_requirements( $asset ) {
        $requirements = apply_filters( 'directorist_renderer_asset_requirements', self::$asset_requirements );
        $requirements = isset( $requirements[ $asset ] ) && is_array( $requirements[ $asset ] ) ? $requirements[ $asset ] : [];

        return [
            'assets'  => ! empty( $requirements['assets'] ) && is_array( $requirements['assets'] ) ? $requirements['assets'] : [],
            'styles'  => ! empty( $requirements['styles'] ) && is_array( $requirements['styles'] ) ? $requirements['styles'] : [],
            'scripts' => ! empty( $requirements['scripts'] ) && is_array( $requirements['scripts'] ) ? $requirements['scripts'] : [],
        ];
    }

    /**
     * Get logical assets owned by a rendered template.
     *
     * @param string $template Template key.
     *
     * @return array
     */
    public static function get_template_requirements( $template ) {
        $assets = self::$template_assets[ $template ] ?? [];

        return self::normalize_asset_list(
            apply_filters( 'directorist_template_asset_requirements', $assets, $template )
        );
    }

    /**
     * Get asset keys for a template-rendered field.
     *
     * @param string $template Template key.
     * @param array  $args     Template arguments.
     *
     * @return array
     */
    public static function get_field_requirements( $template, $args = [] ) {
        $field_type = self::$template_field_types[ $template ] ?? '';
        $assets     = $field_type ? self::get_field_type_requirements( $field_type, $template, $args ) : [];

        if ( 'search-form/fields/location' === $template && self::search_location_uses_map_source( $args ) ) {
            $assets = [ 'geolocation' ];
        }

        return self::normalize_asset_list(
            apply_filters( 'directorist_field_template_asset_requirements', $assets, $template, $args, $field_type )
        );
    }

    /**
     * Get asset keys for a logical field type.
     *
     * @param string $field_type Field type.
     * @param string $context    Template/context key.
     * @param array  $args       Context arguments.
     *
     * @return array
     */
    protected static function get_field_type_requirements( $field_type, $context = '', $args = [] ) {
        $field_type = sanitize_key( $field_type );
        $assets     = self::$field_assets[ $field_type ] ?? [];

        return self::normalize_asset_list(
            apply_filters( 'directorist_field_asset_requirements', $assets, $field_type, $context, $args )
        );
    }

    protected static function search_location_uses_map_source( $args ) {
        $data = ! empty( $args['data'] ) && is_array( $args['data'] ) ? $args['data'] : [];

        return ! empty( $data['location_source'] ) && 'from_map_api' === $data['location_source'];
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