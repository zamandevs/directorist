<?php

namespace Directorist\Cache\Built_In;

/**
 * Conservative pre-WordPress request guard.
 */
final class Request_Guard {
    /** @var Request_Key */
    private $request_key;

    /** @var Cookie_Policy */
    private $cookie_policy;

    /** @var bool */
    private $cache_filtered_results;

    /** @var string */
    private $refresh_token;

    /** @var callable */
    private $clock;

    /**
     * @param Request_Key|null $request_key URL canonicalizer.
     * @param array            $cookie_policy Generated cookie policy.
     * @param bool             $cache_filtered_results Whether query variants may be cached.
     * @param string           $refresh_token Signed internal refresh secret.
     * @param callable|null    $clock Unix timestamp provider.
     */
    public function __construct( Request_Key $request_key = null, array $cookie_policy = [], $cache_filtered_results = true, $refresh_token = '', $clock = null ) {
        $this->request_key            = $request_key ?: new Request_Key();
        $this->cookie_policy          = new Cookie_Policy( $cookie_policy );
        $this->cache_filtered_results = (bool) $cache_filtered_results;
        $this->refresh_token          = is_string( $refresh_token ) ? $refresh_token : '';
        $this->clock                  = is_callable( $clock ) ? $clock : 'time';
    }

    /**
     * @param array $server HTTP server values.
     * @param array $cookies Parsed cookies.
     * @return array
     */
    public function evaluate( array $server, array $cookies = [] ) {
        $method = isset( $server['REQUEST_METHOD'] ) ? strtoupper( trim( (string) $server['REQUEST_METHOD'] ) ) : '';

        if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
            return $this->result( false, 'unsafe_method' );
        }

        if ( $this->has_header( $server, [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] ) ) {
            return $this->result( false, 'authorization_header' );
        }

        $cache_control = $this->header( $server, 'HTTP_CACHE_CONTROL' );

        foreach ( [ 'no-cache', 'no-store', 'max-age=0' ] as $directive ) {
            if ( false !== strpos( strtolower( $cache_control ), $directive ) ) {
                return $this->result( false, 'bypass_header' );
            }
        }

        if ( false !== strpos( strtolower( $this->header( $server, 'HTTP_PRAGMA' ) ), 'no-cache' ) || $this->has_header( $server, [ 'HTTP_X_WP_NONCE' ] ) || 'xmlhttprequest' === strtolower( $this->header( $server, 'HTTP_X_REQUESTED_WITH' ) ) ) {
            return $this->result( false, 'bypass_header' );
        }

        $accept = strtolower( $this->header( $server, 'HTTP_ACCEPT' ) );

        if ( '' !== $accept && false === strpos( $accept, 'text/html' ) && false === strpos( $accept, 'application/xhtml+xml' ) && false === strpos( $accept, '*/*' ) ) {
            return $this->result( false, 'non_html_accept' );
        }

        $cookie_result = $this->cookie_policy->evaluate( $cookies, $this->header( $server, 'HTTP_COOKIE' ) );

        if ( empty( $cookie_result['eligible'] ) ) {
            return $this->result( false, $cookie_result['code'] );
        }

        $request = $this->request_key->from_server( $server, $cookie_result['variation'] );

        if ( empty( $request['success'] ) ) {
            return $this->result( false, $request['code'] );
        }

        if ( $this->is_private_path( $request['path'] ) ) {
            return $this->result( false, 'private_path' );
        }

        if ( $this->has_private_query( $request['query'] ) ) {
            return $this->result( false, 'private_query' );
        }

        if ( '' !== $request['query'] && ! $this->cache_filtered_results ) {
            return $this->result( false, 'filtered_results_disabled' );
        }

        $result              = $this->result( true, 'candidate' );
        $result['request']   = $request;
        $result['method']    = $method;
        $result['read_only'] = ! empty( $cookie_result['read_only'] );
        $result['force_refresh'] = ! $result['read_only'] && $this->valid_refresh_signature(
            $this->header( $server, 'HTTP_X_DIRECTORIST_CACHE_REFRESH' ),
            $this->header( $server, 'HTTP_X_DIRECTORIST_CACHE_REFRESH_TIME' ),
            $this->header( $server, 'HTTP_X_DIRECTORIST_CACHE_REFRESH_KEY' ),
            $request
        );

        return $result;
    }

    /**
     * @param string $path Canonical path.
     * @return bool
     */
    private function is_private_path( $path ) {
        $path = strtolower( $path );

        foreach ( [ '/wp-admin', '/wp-login.php', '/wp-cron.php', '/wp-json', '/xmlrpc.php', '/wp-comments-post.php' ] as $private_path ) {
            if ( $private_path === $path || 0 === strpos( $path, $private_path . '/' ) || 0 === strpos( $path, $private_path . '?' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $query Canonical raw query.
     * @return bool
     */
    private function has_private_query( $query ) {
        if ( '' === $query ) {
            return false;
        }

        foreach ( explode( '&', $query ) as $pair ) {
            $name = strtolower( rawurldecode( str_replace( '+', ' ', explode( '=', $pair, 2 )[0] ) ) );
            $name = preg_replace( '/\[.*$/', '', $name );

            if ( false !== strpos( $name, 'nonce' ) || in_array( $name, [ 'security', 'preview', 'customize_changeset_uuid', 'rest_route' ], true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array    $server HTTP server values.
     * @param string[] $names Header server keys.
     * @return bool
     */
    private function has_header( array $server, array $names ) {
        foreach ( $names as $name ) {
            if ( '' !== trim( $this->header( $server, $name ) ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array  $server HTTP server values.
     * @param string $name Header server key.
     * @return string
     */
    private function header( array $server, $name ) {
        return isset( $server[ $name ] ) && is_scalar( $server[ $name ] ) ? (string) $server[ $name ] : '';
    }

    private function valid_refresh_signature( $candidate, $timestamp, $request_hash, array $request ) {
        if ( '' === $candidate || ! ctype_digit( (string) $timestamp ) || ! preg_match( '/^[a-f0-9]{64}$/', $request_hash ) || 8 > strlen( $this->refresh_token ) || ! function_exists( 'hash_equals' ) ) {
            return false;
        }

        $timestamp = (int) $timestamp;
        $now       = (int) call_user_func( $this->clock );

        if ( 60 < abs( $now - $timestamp ) ) {
            return false;
        }

        if ( empty( $request['hash'] ) || ! hash_equals( $request['hash'], (string) $request_hash ) ) {
            return false;
        }

        $expected = hash_hmac( 'sha256', $timestamp . "\n" . (string) $request['canonical_url'] . "\n" . $request['hash'], $this->refresh_token );

        return hash_equals( $expected, (string) $candidate );
    }

    /**
     * @param bool   $eligible Candidate state.
     * @param string $code Stable code.
     * @return array
     */
    private function result( $eligible, $code ) {
        return [
            'eligible'  => (bool) $eligible,
            'code'      => $code,
            'request'   => [],
            'method'    => '',
            'read_only' => false,
            'force_refresh' => false,
        ];
    }
}
