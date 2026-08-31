<?php

namespace Directorist\Cache;

/**
 * Applies Directorist request policy to the selected LiteSpeed cache runtime.
 */
final class LiteSpeed_Compatibility {
    const OPTION_NAME            = 'directorist_page_cache_litespeed_compatibility_v1';
    const REQUEST_POLICY_VERSION = 2;

    /** @var callable */
    private $route_probe;

    /** @var callable */
    private $deny_cache;

    /** @var callable */
    private $cookie_policy;

    /** @var callable */
    private $refresh_vary;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $warm_after_repair;

    /** @var bool */
    private $registered = false;

    /**
     * @param array $runtime Test/runtime boundaries.
     */
    public function __construct( array $runtime = [] ) {
        $this->route_probe       = isset( $runtime['route_probe'] ) && is_callable( $runtime['route_probe'] ) ? $runtime['route_probe'] : static function () {
            return ( new Route_Resolver() )->classify_request();
        };
        $this->deny_cache        = isset( $runtime['deny_cache'] ) && is_callable( $runtime['deny_cache'] ) ? $runtime['deny_cache'] : static function ( $reason ) {
            do_action( 'litespeed_control_set_nocache', '[Directorist] ' . sanitize_key( (string) $reason ) );

            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }

            return true;
        };
        $this->cookie_policy     = isset( $runtime['cookie_policy'] ) && is_callable( $runtime['cookie_policy'] ) ? $runtime['cookie_policy'] : static function () {
            return function_exists( 'directorist_page_cache_cookie_policy' ) ? directorist_page_cache_cookie_policy() : Cookie_Policy::defaults();
        };
        $this->refresh_vary      = isset( $runtime['refresh_vary'] ) && is_callable( $runtime['refresh_vary'] ) ? $runtime['refresh_vary'] : static function () {
            if ( ! is_callable( [ 'LiteSpeed\\Conf', 'cls' ] ) || ! is_callable( [ 'LiteSpeed\\Htaccess', 'cls' ] ) ) {
                return true;
            }

            $config = \LiteSpeed\Conf::cls()->load_options( null, true );

            return is_array( $config ) && (bool) \LiteSpeed\Htaccess::cls()->update( $config );
        };
        $this->clock             = isset( $runtime['clock'] ) && is_callable( $runtime['clock'] ) ? $runtime['clock'] : 'time';
        $this->warm_after_repair = isset( $runtime['warm_after_repair'] ) && is_callable( $runtime['warm_after_repair'] ) ? $runtime['warm_after_repair'] : static function ( Cache_Provider $provider, array $purge, array $plan ) {
            return ( new Automatic_Warmer( $provider ) )->after_invalidation( $purge, $plan );
        };
    }

    /** @return bool */
    public function register() {
        if ( $this->registered ) {
            return false;
        }

        add_action( 'template_redirect', [ $this, 'guard_request' ], -1000 );
        add_filter( 'rest_pre_dispatch', [ $this, 'guard_rest_request' ], -1000, 3 );
        add_filter( 'litespeed_vary_cookies', [ $this, 'vary_cookies' ], PHP_INT_MAX );
        add_filter( 'litespeed_vary_curr_cookies', [ $this, 'vary_cookies' ], PHP_INT_MAX );
        $this->registered = true;

        return true;
    }

    /**
     * Preserve provider cookies while adding bounded Directorist language variations.
     *
     * @param mixed $cookies Provider vary-cookie list.
     * @return string[]
     */
    public function vary_cookies( $cookies ) {
        $cookies = is_array( $cookies ) ? $cookies : [];

        return array_values( array_unique( array_merge( $cookies, $this->language_cookie_names() ) ) );
    }

    /** @return array */
    public function guard_request() {
        try {
            $route = (string) call_user_func( $this->route_probe );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $route = 'rejected';
        }

        if ( in_array( $route, [ Route_Resolver::REQUEST_PRIVATE, Route_Resolver::REQUEST_REJECTED ], true ) ) {
            $reason = $route . '_route';
            call_user_func( $this->deny_cache, $reason );

            return [ 'success' => true, 'code' => $reason ];
        }

        return [
            'success' => true,
            'code'    => Route_Resolver::REQUEST_PUBLIC === $route ? 'public_route' : 'unrelated_route',
        ];
    }

    /**
     * @param mixed            $result Existing REST pre-dispatch result.
     * @param \WP_REST_Server  $server REST server, unused.
     * @param \WP_REST_Request $request REST request.
     * @return mixed
     */
    public function guard_rest_request( $result, $server, $request ) {
        unset( $server );

        if ( ! $request instanceof \WP_REST_Request ) {
            return $result;
        }

        $route = (string) $request->get_route();
        $owned = 1 === preg_match( '#^/(?:directorist(?:/|$)|wp/v2/at_biz_dir(?:/|$)|wp/v2/at_biz_dir-(?:category|location|tags)(?:/|$))#', $route );

        /**
         * Filters whether a REST route contains Directorist-owned public data.
         *
         * @param bool             $owned Whether core recognizes the route.
         * @param string           $route Normalized REST route.
         * @param \WP_REST_Request $request REST request.
         */
        if ( ! apply_filters( 'directorist_page_cache_rest_route_owned', $owned, $route, $request ) ) {
            return $result;
        }

        call_user_func( $this->deny_cache, 'directorist_rest_route' );

        return $result;
    }

    /**
     * Purge entries created before this request policy was installed.
     *
     * @param Cache_Provider $provider Selected LiteSpeed provider.
     * @return array
     */
    public function activate( Cache_Provider $provider ) {
        $this->register();
        $current = self::current();
        $hash    = hash( 'sha256', wp_json_encode( [ self::REQUEST_POLICY_VERSION, $this->language_cookie_names() ] ) );

        if ( self::REQUEST_POLICY_VERSION === ( isset( $current['policy_version'] ) ? (int) $current['policy_version'] : 0 ) && $hash === ( isset( $current['config_hash'] ) ? (string) $current['config_hash'] : '' ) ) {
            return [ 'success' => true, 'code' => 'policy_ready' ];
        }

        if ( 'litespeed-cache' !== $provider->get_id() || ! $provider->is_available() ) {
            return [ 'success' => false, 'code' => 'provider_unavailable' ];
        }

        call_user_func( $this->deny_cache, 'policy_rebuild' );

        try {
            $refreshed = (bool) call_user_func( $this->refresh_vary );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $refreshed = false;
        }

        if ( ! $refreshed ) {
            return [ 'success' => false, 'code' => 'vary_refresh_failed' ];
        }

        $plan = [
            'site_id'      => get_current_blog_id(),
            'urls'         => [],
            'dependencies' => [],
            'generations'  => [],
            'conservative' => true,
        ];

        try {
            $purge = $provider->invalidate( $plan );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $purge = [ 'success' => false, 'code' => 'provider_exception' ];
        }

        if ( ! is_array( $purge ) || empty( $purge['success'] ) ) {
            return [ 'success' => false, 'code' => 'policy_purge_failed' ];
        }

        update_option(
            self::OPTION_NAME,
            [
                'policy_version' => self::REQUEST_POLICY_VERSION,
                'config_hash'    => $hash,
                'applied_at'     => (int) call_user_func( $this->clock ),
            ],
            false
        );

        try {
            $warm = call_user_func( $this->warm_after_repair, $provider, $purge, $plan );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $warm = [ 'success' => false, 'code' => 'warm_exception' ];
        }

        return [
            'success' => is_array( $warm ) && ! empty( $warm['success'] ),
            'code'    => is_array( $warm ) && ! empty( $warm['success'] ) ? 'policy_rebuilt' : 'policy_warm_failed',
            'purge'   => $purge,
            'warm'    => is_array( $warm ) ? $warm : [ 'success' => false, 'code' => 'invalid_warm_result' ],
        ];
    }

    /** @return array */
    public static function current() {
        $status = get_option( self::OPTION_NAME, [] );

        return is_array( $status ) ? $status : [];
    }

    /** @return void */
    public static function reset() {
        delete_option( self::OPTION_NAME );
    }

    /** @return array */
    public function deactivate() {
        remove_filter( 'litespeed_vary_cookies', [ $this, 'vary_cookies' ], PHP_INT_MAX );
        remove_filter( 'litespeed_vary_curr_cookies', [ $this, 'vary_cookies' ], PHP_INT_MAX );
        $this->registered = false;

        try {
            $refreshed = (bool) call_user_func( $this->refresh_vary );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $refreshed = false;
        }

        if ( ! $refreshed ) {
            return [ 'success' => false, 'code' => 'configuration_cleanup_failed' ];
        }

        self::reset();

        return [ 'success' => true, 'code' => 'configuration_removed' ];
    }

    /** @return string[] */
    private function language_cookie_names() {
        try {
            $policy = call_user_func( $this->cookie_policy );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $policy = [];
        }

        $names = [];

        foreach ( array_keys( isset( $policy['vary'] ) && is_array( $policy['vary'] ) ? $policy['vary'] : [] ) as $name ) {
            if ( is_string( $name ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $name ) ) {
                $names[] = $name;
            }
        }

        sort( $names, SORT_STRING );

        return array_values( array_unique( $names ) );
    }
}
