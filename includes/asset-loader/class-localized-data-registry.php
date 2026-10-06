<?php
/**
 * Frontend localized-data requirements by script handle.
 *
 * @author wpWax
 */

namespace Directorist\Asset_Loader;

defined( 'ABSPATH' ) || exit;

class Localized_Data_Registry {
    const MODULE_BASE           = 'base';
    const MODULE_SEARCH         = 'search';
    const MODULE_SUBMISSION     = 'submission';
    const MODULE_FAVORITES      = 'favorites';
    const MODULE_REVIEWS        = 'reviews';
    const MODULE_AUTHENTICATION = 'authentication';
    const MODULE_DASHBOARD      = 'dashboard';
    const MODULE_CONTACT        = 'contact';
    const MODULE_PAYMENT        = 'payment';
    const MODULE_MAP            = 'map';

    /**
     * Get the localized-data modules required by a registered script handle.
     *
     * @param string $handle Script handle.
     *
     * @return array
     */
    public static function get_modules( $handle ) {
        $handle   = sanitize_key( $handle );
        $registry = self::registry();
        $modules  = ! empty( $registry[ $handle ] ) ? $registry[ $handle ] : [];
        $modules  = apply_filters( 'directorist_localized_data_handle_modules', $modules, $handle, $registry );

        return self::expand_modules( $modules );
    }

    /**
     * Normalize modules and include their dependencies in deterministic order.
     *
     * @param string|array $modules Module identifiers.
     *
     * @return array
     */
    public static function expand_modules( $modules ) {
        $requested = self::normalize_modules( $modules );
        $expanded  = [];

        foreach ( $requested as $module ) {
            foreach ( self::dependencies( $module ) as $dependency ) {
                $expanded[ $dependency ] = true;
            }

            $expanded[ $module ] = true;
        }

        return array_values(
            array_filter(
                self::module_order(),
                static function ( $module ) use ( $expanded ) {
                    return isset( $expanded[ $module ] );
                }
            )
        );
    }

    /**
     * Get every handle with an explicit modular data contract.
     *
     * @return array
     */
    public static function get_handles() {
        return array_keys( self::registry() );
    }

    protected static function registry() {
        $registry = [
            'directorist-global-script'         => [ self::MODULE_BASE ],
            'directorist-widgets'               => [ self::MODULE_BASE ],
            'directorist-all-listings'          => [ self::MODULE_BASE, self::MODULE_SEARCH, self::MODULE_FAVORITES, self::MODULE_REVIEWS, self::MODULE_MAP ],
            'directorist-search-form'           => [ self::MODULE_BASE, self::MODULE_SEARCH, self::MODULE_MAP ],
            'directorist-dashboard'             => [ self::MODULE_BASE, self::MODULE_FAVORITES, self::MODULE_DASHBOARD ],
            'directorist-all-authors'           => [ self::MODULE_BASE ],
            'directorist-author-profile'        => [ self::MODULE_BASE, self::MODULE_FAVORITES ],
            'directorist-all-location-category' => [ self::MODULE_BASE ],
            'directorist-account'               => [ self::MODULE_BASE, self::MODULE_AUTHENTICATION ],
            'directorist-range-slider'          => [ self::MODULE_BASE ],
            'directorist-geolocation'           => [ self::MODULE_BASE, self::MODULE_MAP ],
            'directorist-add-listing'           => [ self::MODULE_BASE, self::MODULE_SUBMISSION ],
            'directorist-single-listing'        => [ self::MODULE_BASE, self::MODULE_FAVORITES, self::MODULE_REVIEWS, self::MODULE_AUTHENTICATION, self::MODULE_CONTACT ],
            'directorist-plupload'              => [ self::MODULE_BASE ],
            'directorist-openstreet-map'        => [ self::MODULE_BASE, self::MODULE_MAP ],
            'directorist-google-map'            => [ self::MODULE_BASE, self::MODULE_MAP ],
        ];

        $registry = apply_filters( 'directorist_localized_data_handle_registry', $registry );

        return is_array( $registry ) ? $registry : [];
    }

    protected static function dependencies( $module ) {
        if ( self::MODULE_BASE === $module ) {
            return [];
        }

        return [ self::MODULE_BASE ];
    }

    protected static function module_order() {
        return [
            self::MODULE_BASE,
            self::MODULE_SEARCH,
            self::MODULE_SUBMISSION,
            self::MODULE_FAVORITES,
            self::MODULE_REVIEWS,
            self::MODULE_AUTHENTICATION,
            self::MODULE_DASHBOARD,
            self::MODULE_CONTACT,
            self::MODULE_PAYMENT,
            self::MODULE_MAP,
        ];
    }

    protected static function normalize_modules( $modules ) {
        $allowed    = self::module_order();
        $normalized = [];

        foreach ( (array) $modules as $module ) {
            $module = sanitize_key( $module );

            if ( $module && in_array( $module, $allowed, true ) ) {
                $normalized[ $module ] = true;
            }
        }

        return array_keys( $normalized );
    }
}
