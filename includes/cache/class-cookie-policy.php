<?php

namespace Directorist\Cache;

/**
 * Shared late-policy and generated early-cache cookie contract.
 */
final class Cookie_Policy {
    const MAX_NAME_BYTES      = 128;
    const MAX_VARIATION_BYTES = 64;

    /** @var array */
    private $policy;

    /**
     * @param string[] $additional_reject_prefixes Integration-owned private prefixes.
     * @param object|null $filter_context Legacy request-policy filter context.
     */
    public function __construct( array $additional_reject_prefixes = [], $filter_context = null ) {
        $policy                    = self::defaults();
        $policy['reject_prefixes'] = array_merge( $policy['reject_prefixes'], $additional_reject_prefixes );

        /**
         * Filters cookie-name prefixes that make a response private.
         *
         * @param string[] $reject_prefixes Rejected prefixes.
         * @param object   $filter_context Request policy when available.
         */
        $policy['reject_prefixes'] = apply_filters(
            'directorist_page_cache_rejected_cookie_prefixes',
            $policy['reject_prefixes'],
            $filter_context
        );

        /**
         * Filters the complete bounded page-cache cookie policy.
         *
         * Integrations should reject private/session cookies and use `vary`
         * only for small, validated values that change shared public HTML.
         *
         * @param array $policy Cookie policy.
         */
        $policy   = apply_filters( 'directorist_page_cache_cookie_policy', $policy );
        $policy   = is_array( $policy ) ? $policy : [];
        $required = self::defaults();

        foreach ( [ 'ignore_exact', 'ignore_prefixes', 'reject_exact', 'reject_prefixes' ] as $key ) {
            $policy[ $key ] = array_merge( $required[ $key ], isset( $policy[ $key ] ) && is_array( $policy[ $key ] ) ? $policy[ $key ] : [] );
        }

        $policy['vary'] = array_merge( $required['vary'], isset( $policy['vary'] ) && is_array( $policy['vary'] ) ? $policy['vary'] : [] );

        $this->policy = $this->normalize( $policy );
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
        return $this->policy;
    }

    /**
     * @param array $cookies Normalized cookie map.
     * @return array
     */
    public function evaluate( array $cookies ) {
        $variation = [];

        foreach ( $cookies as $name => $value ) {
            if ( ! $this->valid_name( $name ) || ! is_scalar( $value ) ) {
                return $this->result( false, 'invalid_cookie_header', (string) $name );
            }

            $value = (string) $value;

            if ( in_array( $name, $this->policy['ignore_exact'], true ) || $this->matches_prefix( $name, $this->policy['ignore_prefixes'] ) ) {
                continue;
            }

            if ( in_array( $name, $this->policy['reject_exact'], true ) || $this->matches_prefix( $name, $this->policy['reject_prefixes'] ) ) {
                return $this->result( false, 'sensitive_cookie', $name );
            }

            if ( ! isset( $this->policy['vary'][ $name ] ) ) {
                continue;
            }

            $rule = $this->policy['vary'][ $name ];

            if ( '' === $value || $rule['max_length'] < strlen( $value ) || preg_match( '/[^\x21-\x7e]/', $value ) || 1 !== @preg_match( '~' . str_replace( '~', '\\~', $rule['pattern'] ) . '~D', $value ) ) {
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
        ];
    }

    /**
     * @param array $policy Raw policy.
     * @return array
     */
    private function normalize( array $policy ) {
        $normalized = [
            'ignore_exact'    => $this->string_list( isset( $policy['ignore_exact'] ) ? $policy['ignore_exact'] : [] ),
            'ignore_prefixes' => $this->string_list( isset( $policy['ignore_prefixes'] ) ? $policy['ignore_prefixes'] : [] ),
            'reject_exact'    => $this->string_list( isset( $policy['reject_exact'] ) ? $policy['reject_exact'] : [] ),
            'reject_prefixes' => $this->string_list( isset( $policy['reject_prefixes'] ) ? $policy['reject_prefixes'] : [] ),
            'vary'            => [],
        ];
        $rules      = isset( $policy['vary'] ) && is_array( $policy['vary'] ) ? $policy['vary'] : [];

        foreach ( $rules as $name => $rule ) {
            if ( ! $this->valid_name( $name ) || ! is_array( $rule ) ) {
                continue;
            }

            $pattern    = isset( $rule['pattern'] ) && is_string( $rule['pattern'] ) ? $rule['pattern'] : '';
            $max_length = isset( $rule['max_length'] ) ? (int) $rule['max_length'] : 0;

            if ( '' === $pattern || 128 < strlen( $pattern ) || '^' !== substr( $pattern, 0, 1 ) || '$' !== substr( $pattern, -1 ) || preg_match( '/[\x00-\x1f\x7f]/', $pattern ) || 1 > $max_length || self::MAX_VARIATION_BYTES < $max_length || false === @preg_match( '~' . str_replace( '~', '\\~', $pattern ) . '~D', '' ) ) {
                continue;
            }

            $normalized['vary'][ $name ] = [
                'pattern'    => $pattern,
                'max_length' => $max_length,
            ];
        }

        ksort( $normalized['vary'], SORT_STRING );

        return $normalized;
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
     * @param string[] $prefixes Prefixes.
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
        ];
    }
}
