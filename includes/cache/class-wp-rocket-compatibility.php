<?php

namespace Directorist\Cache;

/**
 * Applies Directorist request policy to WP Rocket's generated cache runtime.
 */
final class WP_Rocket_Compatibility {
    const OPTION_NAME            = 'directorist_page_cache_wp_rocket_compatibility_v1';
    const EARLY_GUARD_MARKER     = 'DIRECTORIST WP ROCKET EARLY GUARD';
    const HTACCESS_GUARD_MARKER  = 'DIRECTORIST WP ROCKET REQUEST GUARD';
    const REQUEST_POLICY_VERSION = 2;

    /** @var callable */
    private $query_arguments;

    /** @var callable */
    private $cookie_policy;

    /** @var callable */
    private $refresh_config;

    /** @var callable */
    private $configuration_current;

    /** @var callable */
    private $provider_resolver;

    /** @var callable */
    private $guard_path;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $warm_after_repair;

    /** @var callable */
    private $route_probe;

    /** @var callable */
    private $begin_capture;

    /** @var callable */
    private $finish_capture;

    /** @var callable */
    private $deny_cache;

    /** @var bool */
    private $registered = false;

    /** @var bool */
    private $capture_started = false;

    /** @var bool */
    private $capture_finished = false;

    /** @var array|null */
    private $resolved_policy;

    /**
     * @param array $runtime Test/runtime boundaries.
     */
    public function __construct( array $runtime = [] ) {
        $this->query_arguments = isset( $runtime['query_arguments'] ) && is_callable( $runtime['query_arguments'] ) ? $runtime['query_arguments'] : static function () {
            return ( new Query_Schema_Resolver() )->all_public_arguments();
        };
        $this->cookie_policy   = isset( $runtime['cookie_policy'] ) && is_callable( $runtime['cookie_policy'] ) ? $runtime['cookie_policy'] : static function () {
            return function_exists( 'directorist_page_cache_cookie_policy' ) ? directorist_page_cache_cookie_policy() : Cookie_Policy::defaults();
        };
        $this->refresh_config  = isset( $runtime['refresh_config'] ) && is_callable( $runtime['refresh_config'] ) ? $runtime['refresh_config'] : [ $this, 'refresh_provider_configuration' ];

        $this->configuration_current = isset( $runtime['configuration_current'] ) && is_callable( $runtime['configuration_current'] )
            ? $runtime['configuration_current']
            : ( isset( $runtime['refresh_config'] ) ? '__return_true' : [ $this, 'provider_configuration_is_current' ] );

        $this->provider_resolver = isset( $runtime['provider_resolver'] ) && is_callable( $runtime['provider_resolver'] ) ? $runtime['provider_resolver'] : static function () {
            if ( ! function_exists( 'directorist_page_cache_provider_registry' ) ) {
                return null;
            }

            return directorist_page_cache_provider_registry()->select()->get_provider();
        };
        $this->guard_path        = isset( $runtime['guard_path'] ) && is_callable( $runtime['guard_path'] ) ? $runtime['guard_path'] : static function () {
            return __DIR__ . '/class-wp-rocket-early-guard.php';
        };
        $this->clock             = isset( $runtime['clock'] ) && is_callable( $runtime['clock'] ) ? $runtime['clock'] : 'time';
        $this->warm_after_repair = isset( $runtime['warm_after_repair'] ) && is_callable( $runtime['warm_after_repair'] ) ? $runtime['warm_after_repair'] : static function ( Cache_Provider $provider, array $purge, array $plan ) {
            return ( new Automatic_Warmer( $provider ) )->after_invalidation( $purge, $plan );
        };
        $this->route_probe       = isset( $runtime['route_probe'] ) && is_callable( $runtime['route_probe'] ) ? $runtime['route_probe'] : static function () {
            return ( new Route_Resolver() )->classify_request();
        };
        $this->begin_capture     = isset( $runtime['begin_capture'] ) && is_callable( $runtime['begin_capture'] ) ? $runtime['begin_capture'] : 'directorist_page_cache_begin_response_capture';
        $this->finish_capture    = isset( $runtime['finish_capture'] ) && is_callable( $runtime['finish_capture'] ) ? $runtime['finish_capture'] : 'directorist_page_cache_finish_response_capture';
        $this->deny_cache        = isset( $runtime['deny_cache'] ) && is_callable( $runtime['deny_cache'] ) ? $runtime['deny_cache'] : static function () {
            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }

            return true;
        };
    }

    /** @return bool */
    public function register() {
        if ( $this->registered ) {
            return false;
        }

        add_filter( 'rocket_cache_query_strings', [ $this, 'query_strings' ], PHP_INT_MAX );
        add_filter( 'rocket_cache_dynamic_cookies', [ $this, 'dynamic_cookies' ], PHP_INT_MAX );
        add_filter( 'rocket_cache_reject_cookies', [ $this, 'reject_cookies' ], PHP_INT_MAX );
        add_filter( 'rocket_advanced_cache_file', [ $this, 'inject_early_guard' ], PHP_INT_MAX );
        add_filter( 'rocket_htaccess_mod_rewrite', [ $this, 'htaccess_rules' ], PHP_INT_MAX );
        add_action( 'template_redirect', [ $this, 'guard_request' ], -1000 );
        add_action( 'wp_footer', [ $this, 'finish_request' ], PHP_INT_MAX );
        add_action( 'shutdown', [ $this, 'finish_request' ], PHP_INT_MAX - 10 );
        $this->registered = true;

        return true;
    }

    /**
     * @param mixed $arguments Provider query arguments.
     * @return string[]
     */
    public function query_strings( $arguments ) {
        return $this->sorted_strings( array_merge( $this->string_list( $arguments ), $this->policy()['query_arguments'] ) );
    }

    /**
     * @param mixed $cookies Provider dynamic cookies.
     * @return string[]
     */
    public function dynamic_cookies( $cookies ) {
        return $this->sorted_strings( array_merge( $this->string_list( $cookies ), array_keys( $this->policy()['vary'] ) ) );
    }

    /**
     * @param mixed $cookies Provider rejected cookie expressions.
     * @return string[]
     */
    public function reject_cookies( $cookies ) {
        $policy  = $this->policy();
        $managed = [];

        foreach ( $policy['reject_exact'] as $name ) {
            if ( in_array( $name, $policy['ignore_exact'], true ) || $this->matches_prefix( $name, $policy['ignore_prefixes'] ) ) {
                continue;
            }

            $managed[] = '(?:^|;\\s*)' . preg_quote( $name, '#' ) . '(?:=|;|$)';
        }

        foreach ( $policy['reject_prefixes'] as $prefix ) {
            $ignored_suffixes = [];

            foreach ( $policy['ignore_exact'] as $ignored ) {
                if ( 0 === strpos( $ignored, $prefix ) ) {
                    $ignored_suffixes[] = preg_quote( substr( $ignored, strlen( $prefix ) ), '#' );
                }
            }

            $expression = '(?:^|;\\s*)' . preg_quote( $prefix, '#' );

            if ( ! empty( $ignored_suffixes ) ) {
                $expression .= '(?!(?:' . implode( '|', $ignored_suffixes ) . ')(?:=|;|$))';
            }

            $managed[] = $expression;
        }

        return $this->sorted_strings( array_merge( $this->string_list( $cookies ), $managed ) );
    }

    /**
     * Force sensitive header and language-cookie requests through PHP policy.
     *
     * @param string $rules WP Rocket mod_rewrite rules.
     * @return string
     */
    public function htaccess_rules( $rules ) {
        $rules = (string) $rules;

        if ( false !== strpos( $rules, self::HTACCESS_GUARD_MARKER ) ) {
            return $rules;
        }

        $anchor = 'RewriteCond %{QUERY_STRING} =""';
        $offset = strpos( $rules, $anchor );

        if ( false === $offset ) {
            return $rules;
        }

        $offset += strlen( $anchor );
        $vary    = array_map(
            static function ( $name ) {
                return preg_quote( $name, '#' );
            },
            array_keys( $this->policy()['vary'] )
        );
        $guard   = "\n# " . self::HTACCESS_GUARD_MARKER;
        $guard  .= "\nRewriteCond %{HTTP:Authorization} =\"\"";
        $guard  .= "\nRewriteCond %{HTTP:X-WP-Nonce} =\"\"";
        $guard  .= "\nRewriteCond %{HTTP:X-Requested-With} !^XMLHttpRequest$ [NC]";
        $guard  .= "\nRewriteCond %{HTTP:Cache-Control} !(no-cache|no-store|max-age=0|private) [NC]";
        $guard  .= "\nRewriteCond %{HTTP:Pragma} !no-cache [NC]";

        if ( ! empty( $vary ) ) {
            $guard .= "\nRewriteCond %{HTTP:Cookie} !(^|;[[:space:]]*)(?:" . implode( '|', $vary ) . ')= [NC]';
        }

        return substr( $rules, 0, $offset ) . $guard . substr( $rules, $offset );
    }

    /**
     * @param string $content WP Rocket advanced-cache source.
     * @return string
     */
    public function inject_early_guard( $content ) {
        $content = (string) $content;

        if ( false !== strpos( $content, self::EARLY_GUARD_MARKER ) ) {
            return $content;
        }

        $guard_path = (string) call_user_func( $this->guard_path );

        if ( '' === $guard_path ) {
            return $content;
        }

        $policy     = $this->policy();
        $generated  = "\n// " . self::EARLY_GUARD_MARKER . "\n";
        $generated .= '$directorist_page_cache_guard = ' . var_export( $guard_path, true ) . ";\n";
        $generated .= 'if ( is_file( $directorist_page_cache_guard ) ) {' . "\n";
        $generated .= '    require_once $directorist_page_cache_guard;' . "\n";
        $generated .= '    if ( \\Directorist\\Cache\\WP_Rocket_Early_Guard::should_bypass( $_SERVER, $_GET, $_COOKIE, ' . var_export( $policy, true ) . ' ) ) {' . "\n";
        $generated .= "        return;\n    }\n}\n";
        $generated .= "unset( \$directorist_page_cache_guard );\n";

        $anchor = "defined( 'ABSPATH' ) || exit;";
        $offset = strpos( $content, $anchor );

        if ( false !== $offset ) {
            $offset += strlen( $anchor );

            return substr( $content, 0, $offset ) . $generated . substr( $content, $offset );
        }

        $offset = strpos( $content, "\n" );

        return false === $offset ? $content . $generated : substr( $content, 0, $offset + 1 ) . $generated . substr( $content, $offset + 1 );
    }

    /**
     * Synchronize generated rules and purge variants once per policy hash.
     *
     * @param Cache_Provider $provider Selected WP Rocket provider.
     * @return array
     */
    public function activate( Cache_Provider $provider ) {
        $this->register();

        if ( 'wp-rocket' !== $provider->get_id() || ! $provider->is_available() ) {
            return [ 'success' => false, 'code' => 'provider_unavailable' ];
        }

        $policy   = $this->policy();
        $hash     = hash( 'sha256', wp_json_encode( [ self::REQUEST_POLICY_VERSION, $policy ] ) );
        $previous = self::current();

        if ( self::REQUEST_POLICY_VERSION === ( isset( $previous['policy_version'] ) ? (int) $previous['policy_version'] : 0 ) && hash_equals( (string) ( $previous['applied_hash'] ?? '' ), $hash ) && $this->configuration_is_current() ) {
            return [ 'success' => true, 'code' => 'configuration_ready' ];
        }

        if ( ! $this->refresh( true ) ) {
            return [ 'success' => false, 'code' => 'configuration_refresh_failed' ];
        }

        call_user_func( $this->deny_cache, 'configuration_rebuild' );
        $plan  = $this->conservative_plan();
        $purge = $this->purge( $provider, $plan );

        if ( empty( $purge['success'] ) ) {
            return [ 'success' => false, 'code' => 'configuration_purge_failed', 'purge' => $purge ];
        }

        update_option(
            self::OPTION_NAME,
            [
                'policy_version'  => self::REQUEST_POLICY_VERSION,
                'applied_hash'    => $hash,
                'query_strings'   => $policy['query_arguments'],
                'dynamic_cookies' => array_keys( $policy['vary'] ),
                'reject_cookies'  => array_merge( $policy['reject_exact'], $policy['reject_prefixes'] ),
                'applied_at'      => (int) call_user_func( $this->clock ),
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
            'code'    => is_array( $warm ) && ! empty( $warm['success'] ) ? 'configuration_rebuilt' : 'configuration_warm_failed',
            'purge'   => $purge,
            'warm'    => is_array( $warm ) ? $warm : [ 'success' => false, 'code' => 'invalid_warm_result' ],
        ];
    }

    /** @return array */
    public function guard_request() {
        try {
            $route = (string) call_user_func( $this->route_probe );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $route = Route_Resolver::REQUEST_REJECTED;
        }

        if ( Route_Resolver::REQUEST_PRIVATE === $route || Route_Resolver::REQUEST_REJECTED === $route ) {
            $reason = $route . '_route';
            call_user_func( $this->deny_cache, $reason );

            return [ 'success' => true, 'code' => $reason ];
        }

        if ( Route_Resolver::REQUEST_PUBLIC !== $route ) {
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

    /** @return array */
    public function deactivate() {
        $previous = self::current();

        if ( empty( $previous ) ) {
            $this->unregister();

            return [ 'success' => true, 'code' => 'configuration_absent' ];
        }

        $this->unregister();

        if ( ! $this->refresh( false ) ) {
            return [ 'success' => false, 'code' => 'configuration_cleanup_failed' ];
        }

        try {
            $provider = call_user_func( $this->provider_resolver );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $provider = null;
        }

        if ( ! $provider instanceof Cache_Provider || 'wp-rocket' !== $provider->get_id() ) {
            return [ 'success' => false, 'code' => 'provider_unavailable' ];
        }

        $purge = $this->purge( $provider, $this->conservative_plan() );

        if ( empty( $purge['success'] ) ) {
            return [ 'success' => false, 'code' => 'configuration_cleanup_purge_failed', 'purge' => $purge ];
        }

        self::reset();

        return [ 'success' => true, 'code' => 'configuration_removed', 'purge' => $purge ];
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

    /** @return bool */
    public function refresh_provider_configuration( $expect_guard = true ) {
        if ( ! function_exists( 'rocket_generate_config_file' ) || ! function_exists( 'rocket_generate_advanced_cache_file' ) ) {
            return false;
        }

        global $is_apache;

        if ( $is_apache && $expect_guard && ( ! function_exists( 'flush_rocket_htaccess' ) || ! flush_rocket_htaccess() ) ) {
            return false;
        }

        rocket_generate_config_file();
        rocket_generate_advanced_cache_file();

        if ( $is_apache && ! $expect_guard && ( ! function_exists( 'flush_rocket_htaccess' ) || ! flush_rocket_htaccess() ) ) {
            return false;
        }

        $dropin = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/advanced-cache.php' : '';

        if ( '' === $dropin || ! is_file( $dropin ) ) {
            return false;
        }

        $has_guard = false !== strpos( (string) file_get_contents( $dropin ), self::EARLY_GUARD_MARKER );

        if ( (bool) $expect_guard !== $has_guard ) {
            return false;
        }

        if ( ! $is_apache ) {
            return true;
        }

        if ( ! function_exists( 'get_home_path' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $htaccess = get_home_path() . '.htaccess';
        $has_rule = is_file( $htaccess ) && false !== strpos( (string) file_get_contents( $htaccess ), self::HTACCESS_GUARD_MARKER );

        return (bool) $expect_guard === $has_rule;
    }

    /** @return bool */
    public function provider_configuration_is_current() {
        if ( ! function_exists( 'get_rocket_config_file' ) ) {
            return false;
        }

        $generated = get_rocket_config_file();

        if ( ! is_array( $generated ) || empty( $generated[0] ) || ! is_array( $generated[0] ) || ! isset( $generated[1] ) || ! is_string( $generated[1] ) ) {
            return false;
        }

        foreach ( $generated[0] as $path ) {
            if ( ! is_string( $path ) || ! is_file( $path ) || (string) file_get_contents( $path ) !== $generated[1] ) {
                return false;
            }
        }

        $dropin = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/advanced-cache.php' : '';

        if ( '' === $dropin || ! is_file( $dropin ) || false === strpos( (string) file_get_contents( $dropin ), self::EARLY_GUARD_MARKER ) ) {
            return false;
        }

        global $is_apache;

        if ( ! $is_apache ) {
            return true;
        }

        if ( ! function_exists( 'get_home_path' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $htaccess = get_home_path() . '.htaccess';

        return is_file( $htaccess ) && false !== strpos( (string) file_get_contents( $htaccess ), self::HTACCESS_GUARD_MARKER );
    }

    /** @return void */
    private function unregister() {
        if ( ! $this->registered ) {
            return;
        }

        remove_filter( 'rocket_cache_query_strings', [ $this, 'query_strings' ], PHP_INT_MAX );
        remove_filter( 'rocket_cache_dynamic_cookies', [ $this, 'dynamic_cookies' ], PHP_INT_MAX );
        remove_filter( 'rocket_cache_reject_cookies', [ $this, 'reject_cookies' ], PHP_INT_MAX );
        remove_filter( 'rocket_advanced_cache_file', [ $this, 'inject_early_guard' ], PHP_INT_MAX );
        remove_filter( 'rocket_htaccess_mod_rewrite', [ $this, 'htaccess_rules' ], PHP_INT_MAX );
        remove_action( 'template_redirect', [ $this, 'guard_request' ], -1000 );
        remove_action( 'wp_footer', [ $this, 'finish_request' ], PHP_INT_MAX );
        remove_action( 'shutdown', [ $this, 'finish_request' ], PHP_INT_MAX - 10 );
        $this->registered = false;
    }

    /** @return bool */
    private function refresh( $expect_guard ) {
        try {
            return (bool) call_user_func( $this->refresh_config, (bool) $expect_guard );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }
    }

    /** @return bool */
    private function configuration_is_current() {
        try {
            return (bool) call_user_func( $this->configuration_current );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }
    }

    /** @return array */
    private function policy() {
        if ( is_array( $this->resolved_policy ) ) {
            return $this->resolved_policy;
        }

        try {
            $arguments = call_user_func( $this->query_arguments );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $arguments = [];
        }

        try {
            $cookies = call_user_func( $this->cookie_policy );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $cookies = [];
        }

        $cookies = is_array( $cookies ) ? $cookies : [];

        $this->resolved_policy = [
            'query_arguments' => $this->bounded_names( $arguments ),
            'ignore_exact'    => $this->bounded_names( isset( $cookies['ignore_exact'] ) ? $cookies['ignore_exact'] : [] ),
            'ignore_prefixes' => $this->bounded_names( isset( $cookies['ignore_prefixes'] ) ? $cookies['ignore_prefixes'] : [] ),
            'reject_exact'    => $this->bounded_names( isset( $cookies['reject_exact'] ) ? $cookies['reject_exact'] : [] ),
            'reject_prefixes' => $this->bounded_names( isset( $cookies['reject_prefixes'] ) ? $cookies['reject_prefixes'] : [] ),
            'vary'            => $this->vary_rules( isset( $cookies['vary'] ) && is_array( $cookies['vary'] ) ? $cookies['vary'] : [] ),
        ];

        return $this->resolved_policy;
    }

    /** @return array */
    private function conservative_plan() {
        return [
            'site_id'      => get_current_blog_id(),
            'urls'         => [],
            'dependencies' => [],
            'generations'  => [],
            'conservative' => true,
        ];
    }

    /** @return array */
    private function purge( Cache_Provider $provider, array $plan ) {
        try {
            $result = $provider->invalidate( $plan );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $result = [ 'success' => false, 'code' => 'provider_exception' ];
        }

        return is_array( $result ) ? $result : [ 'success' => false, 'code' => 'invalid_provider_result' ];
    }

    /** @return string[] */
    private function bounded_names( $values ) {
        $result = [];

        foreach ( is_array( $values ) ? $values : [] as $value ) {
            $value = is_scalar( $value ) ? (string) $value : '';

            if ( '' !== $value && 128 >= strlen( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
                $result[] = $value;
            }
        }

        return $this->sorted_strings( $result );
    }

    /** @return array */
    private function vary_rules( array $rules ) {
        $result = [];

        foreach ( $rules as $name => $rule ) {
            if ( ! is_string( $name ) || ! is_array( $rule ) || empty( $this->bounded_names( [ $name ] ) ) ) {
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

        ksort( $result, SORT_STRING );

        return $result;
    }

    /** @return string[] */
    private function string_list( $values ) {
        $result = [];

        foreach ( is_array( $values ) ? $values : [] as $value ) {
            if ( is_string( $value ) && '' !== $value ) {
                $result[] = $value;
            }
        }

        return array_values( array_unique( $result ) );
    }

    /** @return bool */
    private function matches_prefix( $value, array $prefixes ) {
        foreach ( $prefixes as $prefix ) {
            if ( 0 === strpos( $value, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function sorted_strings( array $values ) {
        $values = array_values( array_unique( array_filter( $values, 'strlen' ) ) );
        natcasesort( $values );

        return array_values( $values );
    }
}
