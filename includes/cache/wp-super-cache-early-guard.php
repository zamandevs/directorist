<?php
/**
 * Pre-WordPress validation for WP Super Cache cookie variations.
 *
 * Loaded only through WP Super Cache's external plugin API. This file has no
 * WordPress or Directorist runtime dependencies.
 */

if ( ! function_exists( 'directorist_page_cache_wpsc_validate_variation_cookies' ) ) {
    /** @return bool */
    function directorist_page_cache_wpsc_validate_variation_cookies() {
        $rules = isset( $GLOBALS['directorist_page_cache_wpsc_vary_rules'] ) && is_array( $GLOBALS['directorist_page_cache_wpsc_vary_rules'] ) ? $GLOBALS['directorist_page_cache_wpsc_vary_rules'] : [];

        foreach ( $rules as $name => $rule ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw cookie input is validated below before any use.
            if ( ! is_string( $name ) || ! isset( $_COOKIE[ $name ] ) || ! is_scalar( $_COOKIE[ $name ] ) || ! is_array( $rule ) ) {
                continue;
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- WordPress is not loaded; the raw value is validated below.
            $value      = (string) $_COOKIE[ $name ];
            $pattern    = isset( $rule['pattern'] ) && is_string( $rule['pattern'] ) ? $rule['pattern'] : '';
            $max_length = isset( $rule['max_length'] ) ? (int) $rule['max_length'] : 0;
            $valid      = '' !== $value
                && 1 <= $max_length
                && 64 >= $max_length
                && $max_length >= strlen( $value )
                && 1 !== preg_match( '/[^\x21-\x7e]/', $value )
                && '^' === substr( $pattern, 0, 1 )
                && '$' === substr( $pattern, -1 )
                && 1 === @preg_match( '~' . str_replace( '~', '\\~', $pattern ) . '~D', $value );

            if ( $valid ) {
                continue;
            }

            $GLOBALS['cache_enabled']       = false;
            $GLOBALS['super_cache_enabled'] = false;

            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }

            return false;
        }

        return true;
    }
}

if ( function_exists( 'add_cacheaction' ) ) {
    add_cacheaction( 'cache_init', 'directorist_page_cache_wpsc_validate_variation_cookies' );
}
