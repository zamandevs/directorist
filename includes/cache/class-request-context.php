<?php

namespace Directorist\Cache;

/**
 * Normalized, request-scoped input for page-cache policy decisions.
 */
final class Request_Context {
    /** @var string */
    private $method;

    /** @var string */
    private $request_uri;

    /** @var array */
    private $query_args;

    /** @var array */
    private $cookies;

    /** @var array */
    private $headers;

    /** @var bool */
    private $user_logged_in;

    /** @var bool */
    private $route_owned;

    /** @var string */
    private $route_type;

    /** @var bool */
    private $query_supported;

    /** @var array */
    private $flags;

    /**
     * @param array $data Normalized or raw request data.
     */
    public function __construct( array $data = [] ) {
        $query_supported = ! empty( $data['query_supported'] );

        $data = wp_parse_args(
            $data,
            [
                'method'         => '',
                'request_uri'    => '',
                'query_args'     => [],
                'cookies'        => [],
                'headers'        => [],
                'user_logged_in' => false,
                'route_owned'    => false,
                'route_type'     => '',
                'flags'          => [],
            ]
        );

        $this->method         = strtoupper( trim( (string) $data['method'] ) );
        $this->request_uri    = (string) $data['request_uri'];
        $this->query_args     = is_array( $data['query_args'] ) ? $data['query_args'] : [];
        $this->cookies        = $this->normalize_cookies( $data['cookies'] );
        $this->headers        = $this->normalize_headers( $data['headers'] );
        $this->user_logged_in = (bool) $data['user_logged_in'];
        $this->route_owned    = (bool) $data['route_owned'];
        $this->route_type     = sanitize_key( (string) $data['route_type'] );
        $this->flags          = $this->normalize_flags( $data['flags'] );

        $this->query_supported = $query_supported;
    }

    /** @return string */
    public function get_method() {
        return $this->method;
    }

    /** @return string */
    public function get_request_uri() {
        return $this->request_uri;
    }

    /** @return array */
    public function get_query_args() {
        return $this->query_args;
    }

    /** @return string[] */
    public function get_cookie_names() {
        return array_keys( $this->cookies );
    }

    /** @return array */
    public function get_cookies() {
        return $this->cookies;
    }

    /**
     * @param string $name Header name.
     * @return string
     */
    public function get_header( $name ) {
        $name = strtolower( trim( (string) $name ) );

        return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : '';
    }

    /** @return bool */
    public function is_user_logged_in() {
        return $this->user_logged_in;
    }

    /** @return bool */
    public function is_route_owned() {
        return $this->route_owned;
    }

    /** @return string */
    public function get_route_type() {
        return $this->route_type;
    }

    /** @return bool */
    public function is_query_supported() {
        return $this->query_supported;
    }

    /**
     * @param string $name Flag name.
     * @return bool
     */
    public function has_flag( $name ) {
        $name = sanitize_key( (string) $name );

        return ! empty( $this->flags[ $name ] );
    }

    /**
     * @param mixed $cookies Cookie map or list.
     * @return array
     */
    private function normalize_cookies( $cookies ) {
        if ( ! is_array( $cookies ) ) {
            return [];
        }

        $normalized = [];

        if ( array_keys( $cookies ) === range( 0, count( $cookies ) - 1 ) ) {
            foreach ( $cookies as $name ) {
                $name = (string) $name;

                if ( '' !== $name ) {
                    $normalized[ $name ] = '';
                }
            }

            return $normalized;
        }

        foreach ( $cookies as $name => $value ) {
            $name = (string) $name;

            if ( '' !== $name ) {
                $normalized[ $name ] = is_scalar( $value ) ? (string) $value : '';
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $headers Header map.
     * @return array
     */
    private function normalize_headers( $headers ) {
        if ( ! is_array( $headers ) ) {
            return [];
        }

        $normalized = [];

        foreach ( $headers as $name => $value ) {
            $name = strtolower( trim( (string) $name ) );

            if ( '' === $name ) {
                continue;
            }

            $normalized[ $name ] = is_scalar( $value ) ? (string) $value : '';
        }

        return $normalized;
    }

    /**
     * @param mixed $flags Context flag map.
     * @return array
     */
    private function normalize_flags( $flags ) {
        if ( ! is_array( $flags ) ) {
            return [];
        }

        $normalized = [];

        foreach ( $flags as $name => $enabled ) {
            $name = sanitize_key( (string) $name );

            if ( '' !== $name ) {
                $normalized[ $name ] = (bool) $enabled;
            }
        }

        return $normalized;
    }
}
