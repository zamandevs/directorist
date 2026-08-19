<?php

namespace Directorist\Cache;

/**
 * Deny-first base policy for anonymous Directorist page-cache requests.
 */
final class Request_Policy {
    /** @var bool */
    private $enabled;

    /** @var string[] */
    private $rejected_cookie_prefixes;

    /** @var string[] */
    private $private_flags = [
        'admin',
        'ajax',
        'rest',
        'cron',
        'cli',
        'xmlrpc',
        'preview',
        'password_protected',
        'submission',
        'checkout',
        'payment',
        'dashboard',
        'account',
    ];

    /**
     * @param bool     $enabled Whether page caching is enabled.
     * @param string[] $additional_rejected_cookie_prefixes Integration-owned prefixes.
     */
    public function __construct( $enabled = false, array $additional_rejected_cookie_prefixes = [] ) {
        $this->enabled = (bool) $enabled;

        $rejected_cookie_prefixes = array_merge(
            [
                'wordpress_logged_in_',
                'wordpress_sec_',
                'wp-postpass_',
                'comment_author_',
                'woocommerce_items_in_cart',
                'woocommerce_cart_hash',
                'wp_woocommerce_session_',
                'edd_items_in_cart',
                'atbdlc__selected_listings_id',
            ],
            $additional_rejected_cookie_prefixes
        );

        /**
         * Filters cookie-name prefixes that make a response private.
         *
         * @param string[]      $rejected_cookie_prefixes Rejected prefixes.
         * @param Request_Policy $policy                   Current policy.
         */
        $rejected_cookie_prefixes = apply_filters(
            'directorist_page_cache_rejected_cookie_prefixes',
            $rejected_cookie_prefixes,
            $this
        );
        $rejected_cookie_prefixes = is_array( $rejected_cookie_prefixes ) ? $rejected_cookie_prefixes : [];

        $this->rejected_cookie_prefixes = array_values(
            array_unique(
                array_filter( array_map( 'strval', $rejected_cookie_prefixes ), 'strlen' )
            )
        );
    }

    /**
     * @param Request_Context $context Request input.
     * @return Eligibility_Result
     */
    public function evaluate( Request_Context $context ) {
        if ( ! $this->enabled ) {
            return new Eligibility_Result( false, Eligibility_Result::CACHE_DISABLED );
        }

        if ( ! in_array( $context->get_method(), [ 'GET', 'HEAD' ], true ) ) {
            return new Eligibility_Result( false, Eligibility_Result::UNSAFE_METHOD );
        }

        if ( ! $context->is_route_owned() ) {
            return new Eligibility_Result( false, Eligibility_Result::UNKNOWN_ROUTE );
        }

        if ( $context->is_user_logged_in() ) {
            return new Eligibility_Result( false, Eligibility_Result::AUTHENTICATED_USER );
        }

        foreach ( $this->private_flags as $flag ) {
            if ( $context->has_flag( $flag ) ) {
                return new Eligibility_Result( false, Eligibility_Result::PRIVATE_CONTEXT, $flag );
            }
        }

        if ( '' !== $context->get_header( 'authorization' ) ) {
            return new Eligibility_Result( false, Eligibility_Result::AUTHORIZATION_HEADER );
        }

        $bypass_header = $this->get_bypass_header( $context );

        if ( '' !== $bypass_header ) {
            return new Eligibility_Result( false, Eligibility_Result::BYPASS_HEADER, $bypass_header );
        }

        foreach ( $context->get_cookie_names() as $cookie_name ) {
            if ( $this->is_rejected_cookie( $cookie_name ) ) {
                return new Eligibility_Result( false, Eligibility_Result::REJECTED_COOKIE, $cookie_name );
            }
        }

        if ( ! empty( $context->get_query_args() ) ) {
            return new Eligibility_Result( false, Eligibility_Result::UNSUPPORTED_QUERY );
        }

        /**
         * Filters an otherwise safe request. This filter can veto eligibility,
         * but it is intentionally not called for a request that failed a guard.
         *
         * @param bool            $eligible Whether the request remains eligible.
         * @param Request_Context $context  Normalized request data.
         */
        if ( ! apply_filters( 'directorist_page_cache_request_eligible', true, $context ) ) {
            return new Eligibility_Result( false, Eligibility_Result::INTEGRATION_VETO );
        }

        return new Eligibility_Result( true, Eligibility_Result::ELIGIBLE );
    }

    /**
     * @param string $cookie_name Cookie name.
     * @return bool
     */
    private function is_rejected_cookie( $cookie_name ) {
        foreach ( $this->rejected_cookie_prefixes as $prefix ) {
            if ( 0 === strpos( $cookie_name, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Request_Context $context Request input.
     * @return string Bypass header name, or an empty string.
     */
    private function get_bypass_header( Request_Context $context ) {
        $cache_control = strtolower( $context->get_header( 'cache-control' ) );

        foreach ( [ 'no-cache', 'no-store', 'max-age=0' ] as $directive ) {
            if ( false !== strpos( $cache_control, $directive ) ) {
                return 'cache-control';
            }
        }

        if ( false !== strpos( strtolower( $context->get_header( 'pragma' ) ), 'no-cache' ) ) {
            return 'pragma';
        }

        if ( '' !== $context->get_header( 'x-wp-nonce' ) ) {
            return 'x-wp-nonce';
        }

        if ( 'xmlhttprequest' === strtolower( $context->get_header( 'x-requested-with' ) ) ) {
            return 'x-requested-with';
        }

        return '';
    }
}
