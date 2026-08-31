<?php

namespace Directorist\Cache;

/**
 * Bounded request guard loaded by WP Rocket before WordPress boots.
 */
final class WP_Rocket_Early_Guard {
    /**
     * @param array $server  Server request values.
     * @param array $query   Parsed query values.
     * @param array $cookies Parsed cookie values.
     * @param array $policy  Generated Directorist policy.
     * @return bool
     */
    public static function should_bypass( array $server, array $query, array $cookies, array $policy ) {
        $method = isset( $server['REQUEST_METHOD'] ) && is_scalar( $server['REQUEST_METHOD'] ) ? strtoupper( (string) $server['REQUEST_METHOD'] ) : '';

        if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
            return true;
        }

        $request_uri = isset( $server['REQUEST_URI'] ) && is_scalar( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '';
        $path        = (string) parse_url( $request_uri, PHP_URL_PATH );

        if ( self::private_path( $path ) || self::private_headers( $server ) ) {
            return true;
        }

        foreach ( array_keys( $query ) as $name ) {
            $name = strtolower( (string) $name );

            if ( in_array( $name, [ '_wpnonce', '_ajax_nonce', 'atbdp_nonce', 'atbdp_nonce_js', 'nonce', 'security', 'rest_route', 'preview', 'preview_id', 'preview_nonce' ], true ) || false !== strpos( $name, 'nonce' ) ) {
                return true;
            }
        }

        $reject_exact    = self::string_list( isset( $policy['reject_exact'] ) ? $policy['reject_exact'] : [] );
        $reject_prefixes = self::string_list( isset( $policy['reject_prefixes'] ) ? $policy['reject_prefixes'] : [] );
        $ignore_exact    = self::string_list( isset( $policy['ignore_exact'] ) ? $policy['ignore_exact'] : [] );
        $ignore_prefixes = self::string_list( isset( $policy['ignore_prefixes'] ) ? $policy['ignore_prefixes'] : [] );

        foreach ( $cookies as $name => $value ) {
            $name = (string) $name;

            if ( in_array( $name, $ignore_exact, true ) || self::matches_prefix( $name, $ignore_prefixes ) ) {
                continue;
            }

            if ( in_array( $name, $reject_exact, true ) || self::matches_prefix( $name, $reject_prefixes ) ) {
                return true;
            }

            if ( isset( $policy['vary'][ $name ] ) && ! self::valid_variation( $value, $policy['vary'][ $name ] ) ) {
                return true;
            }
        }

        $managed = self::string_list( isset( $policy['query_arguments'] ) ? $policy['query_arguments'] : [] );

        if ( empty( array_intersect( array_map( 'strval', array_keys( $query ) ), $managed ) ) ) {
            return false;
        }

        foreach ( array_keys( $query ) as $name ) {
            $name = (string) $name;

            if ( ! in_array( $name, $managed, true ) && ! self::provider_owned_argument( $name ) && ! self::ignored_tracking_argument( $name ) ) {
                return true;
            }
        }

        return false;
    }

    /** @return bool */
    private static function private_path( $path ) {
        $path = '/' . ltrim( (string) $path, '/' );

        return 1 === preg_match( '#/(?:wp-admin(?:/|$)|wp-json(?:/|$)|wp-cron\.php$|xmlrpc\.php$)#i', $path );
    }

    /** @return bool */
    private static function private_headers( array $server ) {
        foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW', 'AUTH_TYPE', 'HTTP_X_WP_NONCE' ] as $name ) {
            if ( isset( $server[ $name ] ) && '' !== trim( (string) $server[ $name ] ) ) {
                return true;
            }
        }

        $cache_control = isset( $server['HTTP_CACHE_CONTROL'] ) ? strtolower( (string) $server['HTTP_CACHE_CONTROL'] ) : '';

        foreach ( [ 'no-cache', 'no-store', 'max-age=0', 'private' ] as $directive ) {
            if ( false !== strpos( $cache_control, $directive ) ) {
                return true;
            }
        }

        if ( isset( $server['HTTP_PRAGMA'] ) && false !== strpos( strtolower( (string) $server['HTTP_PRAGMA'] ), 'no-cache' ) ) {
            return true;
        }

        return isset( $server['HTTP_X_REQUESTED_WITH'] ) && 'xmlhttprequest' === strtolower( (string) $server['HTTP_X_REQUESTED_WITH'] );
    }

    /** @return bool */
    private static function valid_variation( $value, $rule ) {
        if ( ! is_scalar( $value ) || ! is_array( $rule ) ) {
            return false;
        }

        $value      = (string) $value;
        $pattern    = isset( $rule['pattern'] ) && is_string( $rule['pattern'] ) ? $rule['pattern'] : '';
        $max_length = isset( $rule['max_length'] ) ? (int) $rule['max_length'] : 0;

        return '' !== $value
            && 0 < $max_length
            && $max_length >= strlen( $value )
            && 1 === @preg_match( '~' . str_replace( '~', '\\~', $pattern ) . '~D', $value );
    }

    /** @return bool */
    private static function matches_prefix( $name, array $prefixes ) {
        foreach ( $prefixes as $prefix ) {
            if ( 0 === strpos( $name, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    /** @return bool */
    private static function provider_owned_argument( $name ) {
        return in_array( $name, [ 'lang', 's', 'permalink_name', 'lp-variation-id' ], true );
    }

    /** @return bool */
    private static function ignored_tracking_argument( $name ) {
        return 0 === strpos( $name, 'utm_' ) || in_array( $name, [ 'fbclid', 'gclid', 'dclid', 'msclkid', '_ga' ], true );
    }

    /** @return string[] */
    private static function string_list( $values ) {
        $result = [];

        foreach ( is_array( $values ) ? $values : [] as $value ) {
            if ( is_string( $value ) && '' !== $value ) {
                $result[] = $value;
            }
        }

        return array_values( array_unique( $result ) );
    }
}
