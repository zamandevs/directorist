<?php

namespace Directorist\Cache;

/**
 * Keeps WP Super Cache's early configuration aligned with Directorist policy.
 */
final class WP_Super_Cache_Compatibility {
    const OPTION_NAME    = 'directorist_page_cache_wpsc_compatibility_v1';
    const MANAGED_MARKER = '(?#directorist-page-cache)';

    /** @var callable */
    private $read_config;

    /** @var callable */
    private $write_config;

    /** @var callable */
    private $cookie_policy;

    /** @var callable */
    private $permalink_structure;

    /** @var callable */
    private $home_path;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $route_probe;

    /** @var callable */
    private $begin_capture;

    /** @var callable */
    private $finish_capture;

    /** @var callable */
    private $deny_cache;

    /** @var callable */
    private $warm_after_repair;

    /** @var callable */
    private $plugin_path;

    /** @var callable */
    private $refresh_rewrite;

    /** @var bool */
    private $registered = false;

    /** @var bool */
    private $capture_started = false;

    /** @var bool */
    private $capture_finished = false;

    /**
     * @param array $runtime Test/runtime boundaries.
     */
    public function __construct( array $runtime = [] ) {
        $this->read_config         = isset( $runtime['read_config'] ) && is_callable( $runtime['read_config'] ) ? $runtime['read_config'] : static function () {
            return [
                'wpsc_rejected_cookies'                  => isset( $GLOBALS['wpsc_rejected_cookies'] ) ? $GLOBALS['wpsc_rejected_cookies'] : [],
                'wpsc_cookies'                           => isset( $GLOBALS['wpsc_cookies'] ) ? $GLOBALS['wpsc_cookies'] : [],
                'wpsc_plugins'                           => isset( $GLOBALS['wpsc_plugins'] ) ? $GLOBALS['wpsc_plugins'] : [],
                'directorist_page_cache_wpsc_vary_rules' => isset( $GLOBALS['directorist_page_cache_wpsc_vary_rules'] ) ? $GLOBALS['directorist_page_cache_wpsc_vary_rules'] : [],
                'wp_cache_slash_check'                   => isset( $GLOBALS['wp_cache_slash_check'] ) ? $GLOBALS['wp_cache_slash_check'] : null,
                'wp_cache_home_path'                     => isset( $GLOBALS['wp_cache_home_path'] ) ? $GLOBALS['wp_cache_home_path'] : null,
                'wp_cache_mod_rewrite'                   => isset( $GLOBALS['wp_cache_mod_rewrite'] ) ? $GLOBALS['wp_cache_mod_rewrite'] : 0,
            ];
        };
        $this->write_config        = isset( $runtime['write_config'] ) && is_callable( $runtime['write_config'] ) ? $runtime['write_config'] : static function ( $name, $value ) {
            return function_exists( 'wp_cache_setting' ) && (bool) wp_cache_setting( $name, $value );
        };
        $this->cookie_policy       = isset( $runtime['cookie_policy'] ) && is_callable( $runtime['cookie_policy'] ) ? $runtime['cookie_policy'] : static function () {
            return function_exists( 'directorist_page_cache_cookie_policy' ) ? directorist_page_cache_cookie_policy() : Cookie_Policy::defaults();
        };
        $this->permalink_structure = isset( $runtime['permalink_structure'] ) && is_callable( $runtime['permalink_structure'] ) ? $runtime['permalink_structure'] : static function () {
            return (string) get_option( 'permalink_structure', '' );
        };
        $this->home_path           = isset( $runtime['home_path'] ) && is_callable( $runtime['home_path'] ) ? $runtime['home_path'] : static function () {
            $path = (string) wp_parse_url( site_url( '/' ), PHP_URL_PATH );

            return trailingslashit( '/' . ltrim( $path, '/' ) );
        };
        $this->clock               = isset( $runtime['clock'] ) && is_callable( $runtime['clock'] ) ? $runtime['clock'] : 'time';
        $this->route_probe         = isset( $runtime['route_probe'] ) && is_callable( $runtime['route_probe'] ) ? $runtime['route_probe'] : static function () {
            $resolver = new Route_Resolver();

            if ( $resolver->is_private_request() ) {
                return 'private';
            }

            return $resolver->resolve() instanceof Route_Identity ? 'public' : 'other';
        };
        $this->begin_capture       = isset( $runtime['begin_capture'] ) && is_callable( $runtime['begin_capture'] ) ? $runtime['begin_capture'] : 'directorist_page_cache_begin_response_capture';
        $this->finish_capture      = isset( $runtime['finish_capture'] ) && is_callable( $runtime['finish_capture'] ) ? $runtime['finish_capture'] : 'directorist_page_cache_finish_response_capture';
        $this->deny_cache          = isset( $runtime['deny_cache'] ) && is_callable( $runtime['deny_cache'] ) ? $runtime['deny_cache'] : static function () {
            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }

            return true;
        };
        $this->warm_after_repair   = isset( $runtime['warm_after_repair'] ) && is_callable( $runtime['warm_after_repair'] ) ? $runtime['warm_after_repair'] : static function ( Cache_Provider $provider, array $purge, array $plan ) {
            return ( new Automatic_Warmer( $provider ) )->after_invalidation( $purge, $plan );
        };
        $this->plugin_path         = isset( $runtime['plugin_path'] ) && is_callable( $runtime['plugin_path'] ) ? $runtime['plugin_path'] : static function () {
            $path = wp_normalize_path( __DIR__ . '/wp-super-cache-early-guard.php' );
            $root = defined( 'ABSPATH' ) ? trailingslashit( wp_normalize_path( ABSPATH ) ) : '';

            return '' !== $root && 0 === strpos( $path, $root ) ? ltrim( substr( $path, strlen( $root ) ), '/' ) : '';
        };
        $this->refresh_rewrite     = isset( $runtime['refresh_rewrite'] ) && is_callable( $runtime['refresh_rewrite'] ) ? $runtime['refresh_rewrite'] : static function () {
            return function_exists( 'update_mod_rewrite_rules' ) && (bool) update_mod_rewrite_rules();
        };
    }

    /** @return bool */
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

    /**
     * Synchronize only Directorist-owned additions to WP Super Cache config.
     *
     * @return array
     */
    public function synchronize() {
        $current        = $this->read();
        $desired        = $this->desired_config( $current );
        $writes         = [];
        $failed         = false;
        $rewrite_failed = false;

        foreach ( [ 'wpsc_rejected_cookies', 'wpsc_cookies', 'wpsc_plugins', 'directorist_page_cache_wpsc_vary_rules', 'wp_cache_slash_check', 'wp_cache_home_path' ] as $name ) {
            if ( array_key_exists( $name, $current ) && $current[ $name ] === $desired[ $name ] ) {
                continue;
            }

            $writes[ $name ] = $desired[ $name ];

            try {
                if ( ! call_user_func( $this->write_config, $name, $desired[ $name ] ) ) {
                    $failed = true;
                }
            } catch ( \Throwable $exception ) {
                unset( $exception );
                $failed = true;
            }
        }

        $actual   = $this->read();
        $safe     = ! $failed && $this->configuration_matches( $actual, $desired );
        $hash     = hash( 'sha256', wp_json_encode( $desired ) );
        $previous = self::current();
        $applied  = isset( $previous['applied_hash'] ) ? (string) $previous['applied_hash'] : '';

        if ( $safe && ! empty( $actual['wp_cache_mod_rewrite'] ) && ( ! empty( $writes ) || $hash !== $applied ) ) {
            try {
                $safe = (bool) call_user_func( $this->refresh_rewrite );
            } catch ( \Throwable $exception ) {
                unset( $exception );
                $safe = false;
            }

            if ( ! $safe ) {
                $rewrite_failed = true;
            }
        }

        $rebuild = $safe && $hash !== $applied;
        $code    = $safe ? ( empty( $writes ) ? 'configuration_ready' : 'configuration_repaired' ) : ( $rewrite_failed ? 'rewrite_refresh_failed' : 'configuration_write_failed' );
        $state   = $safe ? 'optimized' : 'needs-attention';
        $now     = (int) call_user_func( $this->clock );
        $policy  = $this->policy();
        $status  = [
            'state'           => $state,
            'code'            => $code,
            'safe'            => $safe,
            'config_hash'     => $hash,
            'applied_hash'    => $applied,
            'managed_vary'    => array_keys( $policy['vary'] ),
            'managed_cookies' => $this->managed_cookies( $policy ),
            'managed_plugin'  => (string) call_user_func( $this->plugin_path ),
            'checked_at'      => $now,
            'changed_at'      => $this->changed_at( $previous, $state, $code, $now ),
        ];
        $this->store( $status, $previous );

        return [
            'success'          => $safe,
            'code'             => $code,
            'safe'             => $safe,
            'changed'          => ! empty( $writes ),
            'rebuild_required' => $rebuild,
            'config_hash'      => $hash,
            'writes'           => array_keys( $writes ),
        ];
    }

    /**
     * Register request guards and repair cached output once per config hash.
     *
     * @param Cache_Provider $provider Selected WP Super Cache provider.
     * @return array
     */
    public function activate( Cache_Provider $provider ) {
        $this->register();
        $sync = $this->synchronize();

        if ( empty( $sync['safe'] ) ) {
            return $sync;
        }

        if ( empty( $sync['rebuild_required'] ) ) {
            return [ 'success' => true, 'code' => 'configuration_ready' ];
        }

        call_user_func( $this->deny_cache, 'configuration_rebuild' );
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
            return [ 'success' => false, 'code' => 'configuration_purge_failed' ];
        }

        $this->mark_applied( $sync['config_hash'] );

        try {
            $warm = call_user_func( $this->warm_after_repair, $provider, $purge, $plan );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $warm = [ 'success' => false, 'code' => 'warm_exception' ];
        }

        return [
            'success' => is_array( $warm ) && ! empty( $warm['success'] ),
            'code'    => is_array( $warm ) && ! empty( $warm['success'] ) ? 'configuration_rebuilt' : 'configuration_warm_failed',
            'purge'   => $purge,
            'warm'    => is_array( $warm ) ? $warm : [ 'success' => false, 'code' => 'invalid_warm_result' ],
        ];
    }

    /** @return array */
    public function guard_request() {
        $route = (string) call_user_func( $this->route_probe );

        if ( 'private' === $route ) {
            call_user_func( $this->deny_cache, 'private_route' );

            return [ 'success' => true, 'code' => 'private_route' ];
        }

        if ( 'public' !== $route ) {
            return [ 'success' => true, 'code' => 'unrelated_route' ];
        }

        try {
            $result = call_user_func( $this->begin_capture );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $result = [ 'eligible' => false, 'reason' => 'capture_exception' ];
        }

        if ( ! is_array( $result ) || empty( $result['eligible'] ) ) {
            $reason = is_array( $result ) && ! empty( $result['reason'] ) ? sanitize_key( $result['reason'] ) : 'invalid_capture_result';
            call_user_func( $this->deny_cache, $reason );

            return [ 'success' => true, 'code' => 'request_bypassed', 'reason' => $reason ];
        }

        $this->capture_started  = true;
        $this->capture_finished = false;

        return [ 'success' => true, 'code' => 'request_eligible' ];
    }

    /** @return array */
    public function finish_request() {
        if ( ! $this->capture_started || $this->capture_finished ) {
            return [ 'success' => true, 'code' => 'capture_not_started' ];
        }

        $this->capture_finished = true;

        try {
            $result = call_user_func( $this->finish_capture );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $result = [ 'eligible' => false, 'reason' => 'capture_exception' ];
        }

        if ( ! is_array( $result ) || empty( $result['eligible'] ) ) {
            $reason = is_array( $result ) && ! empty( $result['reason'] ) ? sanitize_key( $result['reason'] ) : 'invalid_capture_result';
            call_user_func( $this->deny_cache, $reason );

            return [ 'success' => true, 'code' => 'render_bypassed', 'reason' => $reason ];
        }

        return [ 'success' => true, 'code' => 'render_eligible' ];
    }

    /**
     * Remove only Directorist-managed additions from WP Super Cache.
     *
     * @return array
     */
    public function deactivate() {
        $current         = $this->read();
        $previous        = self::current();
        $managed_cookies = isset( $previous['managed_cookies'] ) && is_array( $previous['managed_cookies'] ) ? $previous['managed_cookies'] : [];
        $managed_plugin  = isset( $previous['managed_plugin'] ) ? (string) $previous['managed_plugin'] : (string) call_user_func( $this->plugin_path );
        $desired         = [
            'wpsc_rejected_cookies'                  => array_values(
                array_filter(
                    $this->string_list( isset( $current['wpsc_rejected_cookies'] ) ? $current['wpsc_rejected_cookies'] : [] ),
                    static function ( $expression ) {
                        return false === strpos( $expression, self::MANAGED_MARKER );
                    }
                )
            ),
            'wpsc_cookies'                           => array_values( array_diff( $this->string_list( isset( $current['wpsc_cookies'] ) ? $current['wpsc_cookies'] : [] ), $managed_cookies ) ),
            'wpsc_plugins'                           => array_values( array_diff( $this->string_list( isset( $current['wpsc_plugins'] ) ? $current['wpsc_plugins'] : [] ), [ $managed_plugin ] ) ),
            'directorist_page_cache_wpsc_vary_rules' => [],
        ];
        $writes          = [];

        foreach ( $desired as $name => $value ) {
            if ( 'directorist_page_cache_wpsc_vary_rules' !== $name && isset( $current[ $name ] ) && $current[ $name ] === $value ) {
                continue;
            }

            try {
                if ( ! call_user_func( $this->write_config, $name, $value ) ) {
                    return [ 'success' => false, 'code' => 'configuration_cleanup_failed', 'writes' => array_keys( $writes ) ];
                }
            } catch ( \Throwable $exception ) {
                unset( $exception );

                return [ 'success' => false, 'code' => 'configuration_cleanup_failed', 'writes' => array_keys( $writes ) ];
            }

            $writes[ $name ] = true;
        }

        delete_option( self::OPTION_NAME );

        return [ 'success' => true, 'code' => empty( $writes ) ? 'configuration_absent' : 'configuration_removed', 'writes' => array_keys( $writes ) ];
    }

    /** @return array */
    public static function current() {
        $status = get_option( self::OPTION_NAME, [] );

        return is_array( $status ) ? $status : [];
    }

    /** @return array */
    private function read() {
        try {
            $config = call_user_func( $this->read_config );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $config = [];
        }

        return is_array( $config ) ? $config : [];
    }

    /**
     * @param array $current Current external config.
     * @return array
     */
    private function desired_config( array $current ) {
        $policy   = $this->policy();
        $rejected = [];

        foreach ( isset( $current['wpsc_rejected_cookies'] ) && is_array( $current['wpsc_rejected_cookies'] ) ? $current['wpsc_rejected_cookies'] : [] as $expression ) {
            if ( is_string( $expression ) && '' !== $expression && false === strpos( $expression, self::MANAGED_MARKER ) ) {
                $rejected[] = $expression;
            }
        }

        $rejected[]     = $this->reject_expression( $policy );
        $previous       = self::current();
        $managed        = isset( $previous['managed_cookies'] ) && is_array( $previous['managed_cookies'] ) ? $previous['managed_cookies'] : ( isset( $previous['managed_vary'] ) && is_array( $previous['managed_vary'] ) ? $previous['managed_vary'] : [] );
        $cookies        = $this->string_list( isset( $current['wpsc_cookies'] ) ? $current['wpsc_cookies'] : [] );
        $cookies        = array_values( array_diff( $cookies, $managed ) );
        $cookies        = array_values( array_unique( array_merge( $cookies, $this->managed_cookies( $policy ) ) ) );
        $plugins        = $this->string_list( isset( $current['wpsc_plugins'] ) ? $current['wpsc_plugins'] : [] );
        $managed_plugin = isset( $previous['managed_plugin'] ) ? (string) $previous['managed_plugin'] : '';

        if ( '' !== $managed_plugin ) {
            $plugins = array_values( array_diff( $plugins, [ $managed_plugin ] ) );
        }

        $plugin = (string) call_user_func( $this->plugin_path );

        if ( '' !== $plugin ) {
            $plugins[] = $plugin;
        }

        return [
            'wpsc_rejected_cookies'                  => array_values( array_unique( $rejected ) ),
            'wpsc_cookies'                           => $cookies,
            'wpsc_plugins'                           => array_values( array_unique( $plugins ) ),
            'directorist_page_cache_wpsc_vary_rules' => $this->vary_rules( $policy['vary'] ),
            'wp_cache_slash_check'                   => '/' === substr( (string) call_user_func( $this->permalink_structure ), -1 ) ? 1 : 0,
            'wp_cache_home_path'                     => trailingslashit( '/' . ltrim( (string) call_user_func( $this->home_path ), '/' ) ),
        ];
    }

    /** @return array */
    private function policy() {
        try {
            $policy = call_user_func( $this->cookie_policy );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $policy = [];
        }

        $policy = is_array( $policy ) ? $policy : [];

        return [
            'reject_exact'    => $this->string_list( isset( $policy['reject_exact'] ) ? $policy['reject_exact'] : [] ),
            'reject_prefixes' => $this->string_list( isset( $policy['reject_prefixes'] ) ? $policy['reject_prefixes'] : [] ),
            'vary'            => isset( $policy['vary'] ) && is_array( $policy['vary'] ) ? $policy['vary'] : [],
        ];
    }

    /**
     * @param array $policy Normalized policy subset.
     * @return string
     */
    private function reject_expression( array $policy ) {
        $parts = [];

        foreach ( $policy['reject_exact'] as $name ) {
            $parts[] = preg_quote( $name, '~' );
        }

        foreach ( $policy['reject_prefixes'] as $prefix ) {
            $parts[] = preg_quote( $prefix, '~' ) . '.*';
        }

        return '^(?:' . implode( '|', array_values( array_unique( $parts ) ) ) . ')' . self::MANAGED_MARKER . '$';
    }

    /**
     * @param mixed $values Candidate strings.
     * @return string[]
     */
    private function string_list( $values ) {
        $result = [];

        foreach ( is_array( $values ) ? $values : [] as $value ) {
            if ( is_string( $value ) && '' !== $value ) {
                $result[] = $value;
            }
        }

        return array_values( array_unique( $result ) );
    }

    /**
     * Return cookie names/fragments that must make expert mode enter PHP.
     *
     * @param array $policy Normalized policy subset.
     * @return string[]
     */
    private function managed_cookies( array $policy ) {
        $vary = array_keys( $policy['vary'] );
        sort( $vary, SORT_STRING );

        return array_values( array_unique( array_merge( $policy['reject_exact'], $policy['reject_prefixes'], $vary ) ) );
    }

    /**
     * Keep the generated pre-WordPress policy bounded and executable.
     *
     * @param array $rules Candidate variation rules.
     * @return array
     */
    private function vary_rules( array $rules ) {
        $result = [];

        foreach ( $rules as $name => $rule ) {
            if ( ! is_string( $name ) || ! is_array( $rule ) ) {
                continue;
            }

            $pattern    = isset( $rule['pattern'] ) && is_string( $rule['pattern'] ) ? $rule['pattern'] : '';
            $max_length = isset( $rule['max_length'] ) ? (int) $rule['max_length'] : 0;

            if ( '' === $pattern || '^' !== substr( $pattern, 0, 1 ) || '$' !== substr( $pattern, -1 ) || 128 < strlen( $pattern ) || 1 > $max_length || Cookie_Policy::MAX_VARIATION_BYTES < $max_length || false === @preg_match( '~' . str_replace( '~', '\\~', $pattern ) . '~D', '' ) ) {
                continue;
            }

            $result[ $name ] = [
                'pattern'    => $pattern,
                'max_length' => $max_length,
            ];
        }

        return $result;
    }

    /**
     * @param array $actual Actual config after writes.
     * @param array $desired Expected config.
     * @return bool
     */
    private function configuration_matches( array $actual, array $desired ) {
        foreach ( $desired as $name => $value ) {
            if ( ! array_key_exists( $name, $actual ) || $actual[ $name ] !== $value ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array  $previous Previous status.
     * @param string $state New state.
     * @param string $code New code.
     * @param int    $now Current timestamp.
     * @return int
     */
    private function changed_at( array $previous, $state, $code, $now ) {
        if ( isset( $previous['state'], $previous['code'], $previous['changed_at'] ) && $state === $previous['state'] && $code === $previous['code'] ) {
            return (int) $previous['changed_at'];
        }

        return (int) $now;
    }

    /**
     * @param array $status New compact status.
     * @param array $previous Previous compact status.
     * @return void
     */
    private function store( array $status, array $previous ) {
        $comparison          = $status;
        $previous_comparison = $previous;
        unset( $comparison['checked_at'], $previous_comparison['checked_at'] );

        if ( $comparison !== $previous_comparison ) {
            update_option( self::OPTION_NAME, $status, false );
        }
    }

    /**
     * @param string $hash Applied config hash.
     * @return void
     */
    private function mark_applied( $hash ) {
        $status                 = self::current();
        $status['applied_hash'] = (string) $hash;
        $status['code']         = 'configuration_ready';
        $status['safe']         = true;
        $status['state']        = 'optimized';
        $status['checked_at']   = (int) call_user_func( $this->clock );
        update_option( self::OPTION_NAME, $status, false );
    }
}
