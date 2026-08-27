<?php

namespace Directorist\Cache\Built_In;

/**
 * Sends one authenticated refresh handoff after an early stale response.
 */
final class Early_Refresh_Dispatcher {
    const CONNECT_TIMEOUT = 0.25;
    const MAX_WRITE_BYTES = 16384;

    /** @var callable|null */
    private $transport;

    /** @var callable */
    private $clock;

    public function __construct( $transport = null, $clock = null ) {
        $this->transport = is_callable( $transport ) ? $transport : null;
        $this->clock     = is_callable( $clock ) ? $clock : 'time';
    }

    /**
     * Register a post-response handoff.
     *
     * @param array $request Refresh endpoint, token, URL, and request hash.
     * @return bool
     */
    public function dispatch( array $request ) {
        $request = $this->normalize( $request );

        if ( empty( $request ) || ! function_exists( 'register_shutdown_function' ) ) {
            return false;
        }

        register_shutdown_function( [ $this, 'send' ], $request );

        return true;
    }

    /**
     * @param array $request Normalized refresh handoff.
     * @return bool
     */
    public function send( array $request ) {
        $request = $this->normalize( $request );

        if ( empty( $request ) ) {
            return false;
        }

        $endpoint = parse_url( $request['endpoint'] );
        $scheme   = strtolower( (string) $endpoint['scheme'] );
        $host     = strtolower( (string) $endpoint['host'] );
        $port     = isset( $endpoint['port'] ) ? (int) $endpoint['port'] : ( 'https' === $scheme ? 443 : 80 );
        $path     = isset( $endpoint['path'] ) && '' !== $endpoint['path'] ? $endpoint['path'] : '/';

        if ( ! empty( $endpoint['query'] ) ) {
            $path .= '?' . $endpoint['query'];
        }

        $timestamp = (int) call_user_func( $this->clock );
        $signature = hash_hmac( 'sha256', $timestamp . "\n" . $request['hash'] . "\n" . $request['url'], $request['token'] );
        $body      = http_build_query(
            [
                'action'    => 'directorist_page_cache_refresh_due',
                'timestamp' => $timestamp,
                'signature' => $signature,
                'url'       => $request['url'],
                'hash'      => $request['hash'],
                'variation' => json_encode( $request['variation'], JSON_UNESCAPED_SLASHES ),
            ],
            '',
            '&'
        );

        if ( self::MAX_WRITE_BYTES < strlen( $body ) ) {
            return false;
        }

        if ( is_callable( $this->transport ) ) {
            return (bool) call_user_func( $this->transport, $request, $body );
        }

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            @fastcgi_finish_request();
        }

        $context_options = [];

        if ( 'https' === $scheme ) {
            $context_options['ssl'] = [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'peer_name'         => $host,
                'SNI_enabled'       => true,
            ];
        }

        $context = stream_context_create( $context_options );
        $socket  = @stream_socket_client(
            ( 'https' === $scheme ? 'tls://' : 'tcp://' ) . $host . ':' . $port,
            $error_number,
            $error_message,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );
        unset( $error_number, $error_message );

        if ( ! is_resource( $socket ) ) {
            return false;
        }

        stream_set_timeout( $socket, 1 );
        $host_header = $host . ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ? '' : ':' . $port );
        $headers     = [
            "POST {$path} HTTP/1.1",
            "Host: {$host_header}",
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: ' . strlen( $body ),
            'Connection: close',
            'User-Agent: Directorist-Cache-Refresh',
        ];
        $payload     = implode( "\r\n", $headers ) . "\r\n\r\n" . $body;
        $written     = fwrite( $socket, $payload );
        fclose( $socket );

        return false !== $written && $written === strlen( $payload );
    }

    private function normalize( array $request ) {
        $endpoint  = isset( $request['endpoint'] ) ? (string) $request['endpoint'] : '';
        $token     = isset( $request['token'] ) ? (string) $request['token'] : '';
        $url       = isset( $request['url'] ) ? (string) $request['url'] : '';
        $hash      = isset( $request['hash'] ) ? (string) $request['hash'] : '';
        $variation = isset( $request['variation'] ) && is_array( $request['variation'] ) ? $request['variation'] : [];
        $target    = parse_url( $endpoint );
        $source    = parse_url( $url );

        if ( ! is_array( $target ) || ! is_array( $source )
            || empty( $target['scheme'] ) || empty( $target['host'] )
            || empty( $source['scheme'] ) || empty( $source['host'] )
            || ! in_array( strtolower( (string) $target['scheme'] ), [ 'http', 'https' ], true )
            || strtolower( (string) $target['host'] ) !== strtolower( (string) $source['host'] )
            || isset( $target['user'] ) || isset( $target['pass'] ) || isset( $target['fragment'] )
            || ! preg_match( '/^[a-zA-Z0-9_-]{8,128}$/', $token )
            || ! preg_match( '/^[a-f0-9]{64}$/', $hash )
            || 8192 < strlen( $url )
            || ! $this->valid_variation( $variation )
        ) {
            return [];
        }

        return compact( 'endpoint', 'token', 'url', 'hash', 'variation' );
    }

    private function valid_variation( array $variation ) {
        if ( 8 < count( $variation ) ) {
            return false;
        }

        foreach ( $variation as $name => $value ) {
            if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $name ) || ! is_scalar( $value ) || 64 < strlen( (string) $value ) || preg_match( '/[\x00-\x1f\x7f]/', (string) $value ) ) {
                return false;
            }
        }

        return true;
    }
}
