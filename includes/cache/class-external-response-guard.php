<?php

namespace Directorist\Cache;

/**
 * Response-storage boundary for native providers without a Directorist early guard.
 */
final class External_Response_Guard {
    private $runtime;
    private $registered = false;
    private $prepared = false;
    private $capture_started = false;
    private $capture_finished = false;
    private $storage_allowed = true;

    public function __construct( array $runtime = [] ) {
        $this->runtime = array_merge( [
            'route_probe' => static function () { return ( new Route_Resolver() )->classify_request(); },
            'begin_capture' => 'directorist_page_cache_begin_response_capture',
            'finish_capture' => 'directorist_page_cache_finish_response_capture',
            'private_probe' => static function () { return function_exists( 'directorist_page_cache' ) && directorist_page_cache()->is_private(); },
            'deny_cache' => static function () {
                if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                    define( 'DONOTCACHEPAGE', true );
                }
            },
        ], array_intersect_key( $runtime, array_flip( [ 'route_probe', 'begin_capture', 'finish_capture', 'private_probe', 'deny_cache' ] ) ) );
    }

    public function register() {
        if ( $this->registered ) {
            return false;
        }
        add_action( 'template_redirect', [ $this, 'guard_request' ], -1000 );
        add_action( 'wp_footer', [ $this, 'finish_request' ], PHP_INT_MAX );
        add_action( 'shutdown', [ $this, 'finish_request' ], PHP_INT_MAX - 10 );
        $this->registered = true;
        return true;
    }

    public function unregister() {
        remove_action( 'template_redirect', [ $this, 'guard_request' ], -1000 );
        remove_action( 'wp_footer', [ $this, 'finish_request' ], PHP_INT_MAX );
        remove_action( 'shutdown', [ $this, 'finish_request' ], PHP_INT_MAX - 10 );
        $this->registered = false;
    }

    public function guard_request() {
        if ( $this->prepared ) {
            return;
        }
        $this->prepared = true;
        try {
            $route = call_user_func( $this->runtime['route_probe'] );
        } catch ( \Throwable $exception ) {
            $this->deny( 'route_exception' );
            return;
        }
        if ( 'private' === $route || 'rejected' === $route ) {
            $this->deny( $route . '_route' );
            return;
        }
        if ( 'unrelated' === $route ) {
            return;
        }
        if ( 'public' !== $route ) {
            $this->deny( 'invalid_route_result' );
            return;
        }
        try {
            $result = call_user_func( $this->runtime['begin_capture'] );
        } catch ( \Throwable $exception ) {
            $result = [ 'eligible' => false, 'reason' => 'capture_exception' ];
        }
        if ( ! is_array( $result ) || empty( $result['eligible'] ) ) {
            $this->deny( is_array( $result ) && ! empty( $result['reason'] ) ? $result['reason'] : 'invalid_capture_result' );
            return;
        }
        $this->capture_started = true;
    }

    public function finish_request() {
        if ( ! $this->capture_started || $this->capture_finished ) {
            return;
        }
        $this->capture_finished = true;
        try {
            $result = call_user_func( $this->runtime['finish_capture'] );
        } catch ( \Throwable $exception ) {
            $result = [ 'eligible' => false, 'reason' => 'capture_exception' ];
        }
        if ( ! is_array( $result ) || empty( $result['eligible'] ) ) {
            $this->deny( is_array( $result ) && ! empty( $result['reason'] ) ? $result['reason'] : 'invalid_capture_result' );
        }
    }

    public function allows_storage() {
        $this->guard_request();
        $this->finish_request();
        if ( $this->storage_allowed ) {
            try {
                if ( call_user_func( $this->runtime['private_probe'] ) ) {
                    $this->deny( 'private_output' );
                }
            } catch ( \Throwable $exception ) {
                $this->deny( 'private_probe_exception' );
            }
        }
        return $this->storage_allowed;
    }

    private function deny( $reason ) {
        $this->storage_allowed = false;
        call_user_func( $this->runtime['deny_cache'], sanitize_key( $reason ) );
    }

    /** Only configured private pages are resolved; no whole-content scan. */
    public static function private_paths() {
        $paths = [];
        if ( ! function_exists( 'directorist_get_page_id' ) ) {
            return $paths;
        }
        foreach ( [ 'form', 'dashboard', 'checkout', 'receipt', 'failed', 'registration', 'login' ] as $name ) {
            $id = absint( directorist_get_page_id( $name ) );
            if ( ! $id ) {
                continue;
            }
            $url = get_permalink( $id );
            if ( ! $url || wp_parse_url( $url, PHP_URL_QUERY ) ) {
                continue;
            }
            $paths[] = (string) wp_parse_url( $url, PHP_URL_PATH );
        }
        return array_values( array_unique( $paths ) );
    }

    /** Slash-delimited PCRE body, also safe for native Apache rewrite rules. */
    public static function path_pattern( array $paths ) {
        $patterns = [];
        foreach ( $paths as $path ) {
            if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] || preg_match( '/[\x00-\x20\x7f]/', $path ) ) {
                continue;
            }
            foreach ( array_unique( [ $path, rawurldecode( $path ) ] ) as $variant ) {
                if ( preg_match( '/[\x00-\x20\x7f]/', $variant ) ) {
                    continue;
                }
                $base = rtrim( $variant, '/' );
                $patterns[] = '' === $base ? '^\/(?:$|\?)' : '^' . preg_quote( $base, '/' ) . '(?:\/|\?|$)';
            }
        }
        return implode( '|', array_unique( $patterns ) );
    }
}
