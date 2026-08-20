<?php

namespace Directorist\Cache;

/**
 * Coordinates one explicit pre-render and post-render cache decision.
 */
final class Response_Capture {
    /** @var Cache_Manager */
    private $manager;

    /** @var Route_Resolver */
    private $route_resolver;

    /** @var Request_Policy */
    private $request_policy;

    /** @var array|null */
    private $begin_result;

    /** @var array|null */
    private $final_result;

    /** @var bool */
    private $active = false;

    /**
     * @param Cache_Manager|null  $manager Request-scoped manager.
     * @param Route_Resolver|null $route_resolver Directorist route resolver.
     * @param Request_Policy|null $request_policy Deny-first request policy.
     */
    public function __construct( Cache_Manager $manager = null, Route_Resolver $route_resolver = null, Request_Policy $request_policy = null ) {
        $this->manager        = $manager ?: Cache_Manager::instance();
        $this->route_resolver = $route_resolver ?: new Route_Resolver();
        $this->request_policy = $request_policy ?: new Request_Policy( true );
    }

    /**
     * Resolve policy and begin dependency collection before rendering.
     *
     * Optional inputs exist for behavior tests and integrations with already
     * normalized request state. Normal runtime callers omit both arguments.
     *
     * @param array|null $state Resolved route state.
     * @param array|null $request Normalized request data.
     * @return array
     */
    public function begin( array $state = null, array $request = null ) {
        if ( null !== $this->begin_result ) {
            return $this->begin_result;
        }

        $identity = $this->route_resolver->resolve( $state );

        if ( ! $identity instanceof Route_Identity ) {
            return $this->remember_begin( $this->result( false, Eligibility_Result::UNKNOWN_ROUTE ) );
        }

        $request                    = null === $request ? $this->current_request() : $request;
        $request['route_owned']     = true;
        $request['route_type']      = $identity->get_route_type();
        $request['query_supported'] = true;
        $decision                   = $this->request_policy->evaluate( new Request_Context( $request ) );

        $this->remember_begin(
            $this->result(
                $decision->is_eligible(),
                $decision->get_reason(),
                $decision->get_detail(),
                $identity
            )
        );

        if ( ! $decision->is_eligible() ) {
            return $this->begin_result;
        }

        $this->manager->begin_request( $identity );
        $this->active = true;

        return $this->begin_result;
    }

    /**
     * Finalize dependencies and any private veto after rendering.
     *
     * @return array
     */
    public function finish() {
        if ( null !== $this->final_result ) {
            return $this->final_result;
        }

        if ( null === $this->begin_result ) {
            return $this->remember_final( $this->result( false, 'capture_not_started' ) );
        }

        if ( ! $this->active ) {
            return $this->remember_final( $this->begin_result );
        }

        if ( $this->manager->is_private() ) {
            $this->final_result = array_merge(
                $this->begin_result,
                [
                    'eligible'     => false,
                    'reason'       => 'private_render',
                    'detail'       => $this->manager->get_private_reason(),
                    'dependencies' => [],
                ]
            );
        } else {
            $this->final_result                 = $this->begin_result;
            $this->final_result['dependencies'] = $this->manager->get_dependencies();
        }

        $this->manager->end_request();
        $this->active = false;
        do_action( 'directorist_page_cache_eligibility_decided', $this->final_result, 'finish' );

        return $this->final_result;
    }

    /**
     * @param array $result Begin descriptor.
     * @return array
     */
    private function remember_begin( array $result ) {
        $this->begin_result = $result;
        do_action( 'directorist_page_cache_eligibility_decided', $result, 'begin' );

        return $result;
    }

    /**
     * @param array $result Final descriptor.
     * @return array
     */
    private function remember_final( array $result ) {
        $this->final_result = $result;
        do_action( 'directorist_page_cache_eligibility_decided', $result, 'finish' );

        return $result;
    }

    /** @return array */
    private function current_request() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Policy input is normalized by Request_Context and never rendered.
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public cache policy input.
        $query_args = wp_unslash( $_GET );

        return [
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized by Request_Context.
            'method'         => isset( $_SERVER['REQUEST_METHOD'] ) ? wp_unslash( $_SERVER['REQUEST_METHOD'] ) : '',
            'request_uri'    => $request_uri,
            'query_args'     => is_array( $query_args ) ? $query_args : [],
            'cookies'        => wp_unslash( $_COOKIE ),
            'headers'        => $this->current_headers(),
            'user_logged_in' => is_user_logged_in(),
            'flags'          => $this->current_flags(),
        ];
    }

    /** @return array */
    private function current_headers() {
        $map     = [
            'authorization'    => [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ],
            'cache-control'    => [ 'HTTP_CACHE_CONTROL' ],
            'pragma'           => [ 'HTTP_PRAGMA' ],
            'x-wp-nonce'       => [ 'HTTP_X_WP_NONCE' ],
            'x-requested-with' => [ 'HTTP_X_REQUESTED_WITH' ],
        ];
        $headers = [];

        foreach ( $map as $name => $server_names ) {
            foreach ( $server_names as $server_name ) {
                if ( ! isset( $_SERVER[ $server_name ] ) || ! is_scalar( $_SERVER[ $server_name ] ) ) {
                    continue;
                }

                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Policy-only value normalized by Request_Context.
                $headers[ $name ] = wp_unslash( (string) $_SERVER[ $server_name ] );
                break;
            }
        }

        return $headers;
    }

    /** @return array */
    private function current_flags() {
        return [
            'admin'              => is_admin(),
            'ajax'               => function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( defined( 'DOING_AJAX' ) && DOING_AJAX ),
            'rest'               => defined( 'REST_REQUEST' ) && REST_REQUEST,
            'cron'               => function_exists( 'wp_doing_cron' ) ? wp_doing_cron() : ( defined( 'DOING_CRON' ) && DOING_CRON ),
            'cli'                => defined( 'WP_CLI' ) && WP_CLI,
            'xmlrpc'             => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
            'preview'            => is_preview(),
            'password_protected' => post_password_required(),
        ];
    }

    /**
     * @param bool                $eligible Eligibility state.
     * @param string              $reason Stable reason.
     * @param string              $detail Optional non-sensitive detail.
     * @param Route_Identity|null $identity Route identity.
     * @return array
     */
    private function result( $eligible, $reason, $detail = '', Route_Identity $identity = null ) {
        return [
            'eligible'     => (bool) $eligible,
            'reason'       => (string) $reason,
            'detail'       => (string) $detail,
            'site_id'      => $identity ? $identity->get_site_id() : 0,
            'route_type'   => $identity ? $identity->get_route_type() : '',
            'cache_key'    => $identity ? $identity->get_cache_key() : '',
            'dependencies' => [],
        ];
    }
}
