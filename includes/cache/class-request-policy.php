<?php

namespace Directorist\Cache;

/**
 * Deny-first base policy for anonymous Directorist page-cache requests.
 */
final class Request_Policy {
    /** @var bool */
    private $enabled;

    /** @var Cookie_Policy */
    private $cookie_policy;

    /** @var bool */
    private $cache_filtered_results;

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
     * @param bool     $cache_filtered_results Whether normalized query variants may be cached.
     */
    public function __construct( $enabled = false, array $additional_rejected_cookie_prefixes = [], $cache_filtered_results = true ) {
        $this->enabled = (bool) $enabled;
        $this->cache_filtered_results = (bool) $cache_filtered_results;
        $this->cookie_policy           = new Cookie_Policy( $additional_rejected_cookie_prefixes, $this );
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

        $cookie_result = $this->cookie_policy->evaluate( $context->get_cookies() );

        if ( empty( $cookie_result['eligible'] ) ) {
            $reason = 'invalid_cookie_variation' === $cookie_result['code']
                ? Eligibility_Result::INVALID_COOKIE_VARIATION
                : Eligibility_Result::REJECTED_COOKIE;

            return new Eligibility_Result( false, $reason, $cookie_result['detail'] );
        }

        if ( ! empty( $context->get_query_args() ) && ! $context->is_query_supported() ) {
            return new Eligibility_Result( false, Eligibility_Result::UNSUPPORTED_QUERY );
        }

        if ( ! empty( $context->get_query_args() ) && ! $this->cache_filtered_results ) {
            return new Eligibility_Result( false, Eligibility_Result::FILTERED_RESULTS_DISABLED );
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
