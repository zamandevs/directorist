<?php

namespace Directorist\Cache\Built_In;

/**
 * Classifies cookies before WordPress loads and derives bounded key variation.
 */
final class Cookie_Policy {
    const MAX_COOKIE_HEADER_BYTES = 8192;
    const MAX_COOKIES             = 64;
    const MAX_NAME_BYTES          = 128;
    const MAX_VARIATION_BYTES     = 64;
    const WORDPRESS_LOGIN_PREFIX  = 'wordpress_logged_in_';

    /** @var string[] */
    private $ignore_exact;

    /** @var string[] */
    private $ignore_prefixes;

    /** @var string[] */
    private $reject_exact;

    /** @var string[] */
    private $reject_prefixes;

    /** @var array<string,array{pattern:string,max_length:int}> */
    private $vary;

    /**
     * @param array $policy Additional generated policy declarations.
     */
    public function __construct( array $policy = [] ) {
        $defaults = self::defaults();

        $this->ignore_exact    = $this->string_list( array_merge( $defaults['ignore_exact'], isset( $policy['ignore_exact'] ) ? (array) $policy['ignore_exact'] : [] ) );
        $this->ignore_prefixes = $this->string_list( array_merge( $defaults['ignore_prefixes'], isset( $policy['ignore_prefixes'] ) ? (array) $policy['ignore_prefixes'] : [] ) );
        $this->reject_exact    = $this->string_list( array_merge( $defaults['reject_exact'], isset( $policy['reject_exact'] ) ? (array) $policy['reject_exact'] : [] ) );
        $this->reject_prefixes = $this->string_list( array_merge( $defaults['reject_prefixes'], isset( $policy['reject_prefixes'] ) ? (array) $policy['reject_prefixes'] : [] ) );
        $this->vary            = $this->normalize_vary_rules( array_merge( $defaults['vary'], isset( $policy['vary'] ) && is_array( $policy['vary'] ) ? $policy['vary'] : [] ) );
    }

    /** @return array */
    public static function defaults() {
        return [
            'ignore_exact'    => [ 'wordpress_test_cookie' ],
            'ignore_prefixes' => [ '_ga', '_gid', '_gat', '_gcl_', 'sbjs_' ],
            'reject_exact'    => [
                'PHPSESSID',
                'woocommerce_items_in_cart',
                'woocommerce_cart_hash',
                'woocommerce_recently_viewed',
                'woocommerce_demo_store',
                'edd_items_in_cart',
                'edd_saved_cart',
                'edd_cart_token',
                'atbdlc__selected_listings_id',
            ],
            'reject_prefixes' => [
                'wordpress_logged_in_',
                'wordpress_sec_',
                'wordpress_',
                'wp-postpass_',
                'comment_author_',
                'wp_woocommerce_session_',
                'store_notice',
            ],
            'vary'            => [
                'wp-wpml_current_language' => [
                    'pattern'    => '^[a-z][a-z0-9_-]{0,31}$',
                    'max_length' => 32,
                ],
                'pll_language'             => [
                    'pattern'    => '^[a-z][a-z0-9_-]{0,31}$',
                    'max_length' => 32,
                ],
            ],
        ];
    }

    /** @return array */
    public function to_array() {
        return [
            'ignore_exact'    => $this->ignore_exact,
            'ignore_prefixes' => $this->ignore_prefixes,
            'reject_exact'    => $this->reject_exact,
            'reject_prefixes' => $this->reject_prefixes,
            'vary'            => $this->vary,
        ];
    }

    /**
     * @param array  $cookies Parsed cookie map.
     * @param string $raw_header Raw Cookie header.
     * @return array
     */
    public function evaluate( array $cookies, $raw_header = '' ) {
        $normalized = $this->normalize_cookies( $cookies, $raw_header );

        if ( empty( $normalized['success'] ) ) {
            return $this->result( false, $normalized['code'] );
        }

        $variation = [];
        $read_only = false;

        foreach ( $normalized['cookies'] as $name => $value ) {
            if ( in_array( $name, $this->ignore_exact, true ) || $this->matches_prefix( $name, $this->ignore_prefixes ) ) {
                continue;
            }

            if ( 0 === strpos( $name, self::WORDPRESS_LOGIN_PREFIX ) ) {
                $read_only = true;

                continue;
            }

            if ( in_array( $name, $this->reject_exact, true ) || $this->matches_prefix( $name, $this->reject_prefixes ) ) {
                return $this->result( false, 'sensitive_cookie', $name );
            }

            if ( ! isset( $this->vary[ $name ] ) ) {
                continue;
            }

            $rule = $this->vary[ $name ];

            if ( ! is_string( $value ) || '' === $value || $rule['max_length'] < strlen( $value ) || preg_match( '/[^\x21-\x7e]/', $value ) || 1 !== @preg_match( '~' . str_replace( '~', '\\~', $rule['pattern'] ) . '~D', $value ) ) {
                return $this->result( false, 'invalid_cookie_variation', $name );
            }

            $variation[ $name ] = $value;
        }

        ksort( $variation, SORT_STRING );

        return [
            'eligible'  => true,
            'code'      => 'cookies_accepted',
            'detail'    => '',
            'variation' => $variation,
            'read_only' => $read_only,
        ];
    }

    /**
     * @param array  $cookies Parsed cookie map.
     * @param string $raw_header Raw Cookie header.
     * @return array
     */
    private function normalize_cookies( array $cookies, $raw_header ) {
        if ( ! is_scalar( $raw_header ) || self::MAX_COOKIE_HEADER_BYTES < strlen( (string) $raw_header ) || preg_match( '/[\x00-\x1f\x7f]/', (string) $raw_header ) ) {
            return [ 'success' => false, 'code' => 'invalid_cookie_header', 'cookies' => [] ];
        }

        $normalized = [];

        foreach ( $cookies as $name => $value ) {
            if ( ! $this->valid_name( $name ) || ! is_scalar( $value ) ) {
                return [ 'success' => false, 'code' => 'invalid_cookie_header', 'cookies' => [] ];
            }

            $normalized[ (string) $name ] = (string) $value;

            if ( self::MAX_COOKIES < count( $normalized ) ) {
                return [ 'success' => false, 'code' => 'too_many_cookies', 'cookies' => [] ];
            }
        }

        foreach ( explode( ';', (string) $raw_header ) as $pair ) {
            $pair = trim( $pair );

            if ( '' === $pair ) {
                continue;
            }

            $parts = explode( '=', $pair, 2 );
            $name  = trim( $parts[0] );

            if ( ! $this->valid_name( $name ) ) {
                return [ 'success' => false, 'code' => 'invalid_cookie_header', 'cookies' => [] ];
            }

            if ( ! array_key_exists( $name, $normalized ) ) {
                $normalized[ $name ] = isset( $parts[1] ) ? rawurldecode( $parts[1] ) : '';
            }

            if ( self::MAX_COOKIES < count( $normalized ) ) {
                return [ 'success' => false, 'code' => 'too_many_cookies', 'cookies' => [] ];
            }
        }

        return [ 'success' => true, 'code' => 'cookies_normalized', 'cookies' => $normalized ];
    }

    /**
     * @param mixed $name Cookie name.
     * @return bool
     */
    private function valid_name( $name ) {
        return is_string( $name )
            && '' !== $name
            && self::MAX_NAME_BYTES >= strlen( $name )
            && 1 === preg_match( "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name );
    }

    /**
     * @param string   $name Cookie name.
     * @param string[] $prefixes Prefix list.
     * @return bool
     */
    private function matches_prefix( $name, array $prefixes ) {
        foreach ( $prefixes as $prefix ) {
            if ( 0 === strpos( $name, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $values Candidate names or prefixes.
     * @return string[]
     */
    private function string_list( $values ) {
        $result = [];

        foreach ( is_array( $values ) ? $values : [] as $value ) {
            if ( $this->valid_name( $value ) ) {
                $result[] = (string) $value;
            }
        }

        return array_values( array_unique( $result ) );
    }

    /**
     * @param array $rules Raw variation rules.
     * @return array
     */
    private function normalize_vary_rules( array $rules ) {
        $normalized = [];

        foreach ( $rules as $name => $rule ) {
            if ( ! $this->valid_name( $name ) || ! is_array( $rule ) ) {
                continue;
            }

            $pattern    = isset( $rule['pattern'] ) && is_string( $rule['pattern'] ) ? $rule['pattern'] : '';
            $max_length = isset( $rule['max_length'] ) ? (int) $rule['max_length'] : 0;

            if ( '' === $pattern || 128 < strlen( $pattern ) || '^' !== substr( $pattern, 0, 1 ) || '$' !== substr( $pattern, -1 ) || preg_match( '/[\x00-\x1f\x7f]/', $pattern ) || 1 > $max_length || self::MAX_VARIATION_BYTES < $max_length || false === @preg_match( '~' . str_replace( '~', '\\~', $pattern ) . '~D', '' ) ) {
                continue;
            }

            $normalized[ $name ] = [
                'pattern'    => $pattern,
                'max_length' => $max_length,
            ];
        }

        ksort( $normalized, SORT_STRING );

        return $normalized;
    }

    /**
     * @param bool   $eligible Eligibility state.
     * @param string $code Stable code.
     * @param string $detail Cookie name.
     * @return array
     */
    private function result( $eligible, $code, $detail = '' ) {
        return [
            'eligible'  => (bool) $eligible,
            'code'      => (string) $code,
            'detail'    => (string) $detail,
            'variation' => [],
            'read_only' => false,
        ];
    }
}
