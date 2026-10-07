<?php

namespace Directorist\Cache;

/**
 * Adds reversible private-route and cookie exclusions to WP Fastest Cache.
 */
final class WP_Fastest_Cache_Compatibility {
    const OPTION_NAME            = 'directorist_page_cache_wpfc_compatibility_v1';
    const MANAGED_MARKER         = 'directorist-page-cache-language';
    const PRIVATE_MARKER         = 'directorist-page-cache-private';
    const REQUEST_POLICY_VERSION = 2;

    private $private_paths;
    private $response_guard;
    private $registered = false;

    /** @var callable */
    private $read_rules;

    /** @var callable */
    private $write_rules;

    /** @var callable */
    private $refresh_rules;

    /** @var callable */
    private $cookie_policy;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $warm_after_repair;

    /**
     * @param array $runtime Test/runtime boundaries.
     */
    public function __construct( array $runtime = [] ) {
        $this->private_paths = isset( $runtime['private_paths'] ) && is_callable( $runtime['private_paths'] ) ? $runtime['private_paths'] : [ External_Response_Guard::class, 'private_paths' ];
        if ( ! isset( $runtime['deny_cache'] ) ) {
            $runtime['deny_cache'] = static function () {
                if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                    define( 'DONOTCACHEPAGE', true );
                }
                do_action( 'wpfc_exclude_current_page' );
            };
        }
        $this->response_guard = new External_Response_Guard( $runtime );
        $this->read_rules        = isset( $runtime['read_rules'] ) && is_callable( $runtime['read_rules'] ) ? $runtime['read_rules'] : static function () {
            $rules = json_decode( (string) get_option( 'WpFastestCacheExclude', '' ), true );

            return is_array( $rules ) ? $rules : [];
        };
        $this->write_rules       = isset( $runtime['write_rules'] ) && is_callable( $runtime['write_rules'] ) ? $runtime['write_rules'] : static function ( array $rules ) {
            if ( empty( $rules ) ) {
                delete_option( 'WpFastestCacheExclude' );

                return false === get_option( 'WpFastestCacheExclude', false );
            }

            $encoded = wp_json_encode( array_values( $rules ) );

            if ( ! is_string( $encoded ) ) {
                return false;
            }

            update_option( 'WpFastestCacheExclude', $encoded, true );

            return (string) get_option( 'WpFastestCacheExclude', '' ) === $encoded;
        };
        $this->refresh_rules     = isset( $runtime['refresh_rules'] ) && is_callable( $runtime['refresh_rules'] ) ? $runtime['refresh_rules'] : static function () {
            if ( empty( $GLOBALS['wp_fastest_cache'] ) || ! is_callable( [ $GLOBALS['wp_fastest_cache'], 'modify_htaccess_for_exclude' ] ) ) {
                return false;
            }

            $GLOBALS['wp_fastest_cache']->modify_htaccess_for_exclude();

            return true;
        };
        $this->cookie_policy     = isset( $runtime['cookie_policy'] ) && is_callable( $runtime['cookie_policy'] ) ? $runtime['cookie_policy'] : static function () {
            return function_exists( 'directorist_page_cache_cookie_policy' ) ? directorist_page_cache_cookie_policy() : Cookie_Policy::defaults();
        };
        $this->clock             = isset( $runtime['clock'] ) && is_callable( $runtime['clock'] ) ? $runtime['clock'] : 'time';
        $this->warm_after_repair = isset( $runtime['warm_after_repair'] ) && is_callable( $runtime['warm_after_repair'] ) ? $runtime['warm_after_repair'] : static function ( Cache_Provider $provider, array $purge, array $plan ) {
            return ( new Automatic_Warmer( $provider ) )->after_invalidation( $purge, $plan );
        };
    }

    public function register() {
        if ( $this->registered ) {
            return false;
        }
        $this->response_guard->register();
        add_filter( 'wpfc_buffer_callback_filter', [ $this, 'filter_buffer' ], PHP_INT_MAX, 2 );
        $this->registered = true;
        return true;
    }

    public function filter_buffer( $buffer, $phase ) {
        // An empty filter result vetoes native storage, not the response body.
        return in_array( $phase, [ 'html', 'cache' ], true ) && ! $this->response_guard->allows_storage() ? '' : $buffer;
    }

    /**
     * @param Cache_Provider $provider Selected WP Fastest Cache provider.
     * @return array
     */
    public function activate( Cache_Provider $provider ) {
        if ( 'wp-fastest-cache' !== $provider->get_id() || ! $provider->is_available() ) {
            return [ 'success' => false, 'code' => 'provider_unavailable' ];
        }

        $current  = $this->read();
        $base     = $this->without_managed_rule( $current );
        $desired  = array_merge( $base, [ $this->managed_rule() ] );
        $paths = call_user_func( $this->private_paths );
        $pattern = External_Response_Guard::path_pattern( is_array( $paths ) ? $paths : [] );
        if ( '' !== $pattern ) {
            $desired[] = [ 'type' => 'page', 'prefix' => 'regex', 'content' => '(?:' . $pattern . ')(?:' . self::PRIVATE_MARKER . '){0}' ];
        }
        $previous = self::current();
        $hash     = hash( 'sha256', wp_json_encode( [ self::REQUEST_POLICY_VERSION, $desired ] ) );
        $changed  = $current !== $desired;
        $refresh  = $changed || $hash !== ( isset( $previous['config_hash'] ) ? (string) $previous['config_hash'] : '' );

        if ( $changed && ! $this->write( $desired ) ) {
            return [ 'success' => false, 'code' => 'configuration_write_failed' ];
        }

        if ( $refresh && ! $this->refresh() ) {
            return [ 'success' => false, 'code' => 'configuration_refresh_failed' ];
        }

        $status = [
            'policy_version' => self::REQUEST_POLICY_VERSION,
            'config_hash'    => $hash,
            'applied_hash'   => isset( $previous['applied_hash'] ) ? (string) $previous['applied_hash'] : '',
            'checked_at'     => (int) call_user_func( $this->clock ),
        ];
        update_option( self::OPTION_NAME, $status, false );

        if ( $hash === $status['applied_hash'] ) {
            return [ 'success' => true, 'code' => 'configuration_ready' ];
        }

        return $this->rebuild( $provider, $status );
    }

    /** @return array */
    public function deactivate() {
        $this->response_guard->unregister();
        remove_filter( 'wpfc_buffer_callback_filter', [ $this, 'filter_buffer' ], PHP_INT_MAX );
        $this->registered = false;
        if ( empty( self::current() ) ) {
            return [ 'success' => true, 'code' => 'configuration_absent' ];
        }

        $current = $this->read();
        $desired = $this->without_managed_rule( $current );

        if ( $current !== $desired && ! $this->write( $desired ) ) {
            return [ 'success' => false, 'code' => 'configuration_cleanup_failed' ];
        }

        if ( ! $this->refresh() ) {
            return [ 'success' => false, 'code' => 'configuration_cleanup_failed' ];
        }

        self::reset();

        return [ 'success' => true, 'code' => 'configuration_removed' ];
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
    private function read() {
        try {
            $rules = call_user_func( $this->read_rules );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $rules = [];
        }

        return is_array( $rules ) ? array_values( $rules ) : [];
    }

    /**
     * @param array $rules Complete provider rules.
     * @return bool
     */
    private function write( array $rules ) {
        try {
            return (bool) call_user_func( $this->write_rules, array_values( $rules ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }
    }

    /** @return bool */
    private function refresh() {
        try {
            return (bool) call_user_func( $this->refresh_rules );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }
    }

    /**
     * @param array $rules Provider rules.
     * @return array
     */
    private function without_managed_rule( array $rules ) {
        return array_values(
            array_filter(
                $rules,
                static function ( $rule ) {
                    if ( ! is_array( $rule ) || empty( $rule['content'] ) || ! isset( $rule['type'], $rule['prefix'] ) || 'regex' !== $rule['prefix'] ) {
                        return true;
                    }
                    $marker = 'cookie' === $rule['type'] ? self::MANAGED_MARKER : ( 'page' === $rule['type'] ? self::PRIVATE_MARKER : '' );
                    $suffix = '(?:' . $marker . '){0}';

                    return '' === $marker || $suffix !== substr( (string) $rule['content'], -strlen( $suffix ) );
                }
            )
        );
    }

    /** @return array */
    private function managed_rule() {
        $names   = $this->language_cookie_names();
        $escaped = array_map(
            static function ( $name ) {
                return preg_quote( $name, '/' );
            },
            $names
        );

        return [
            'type'    => 'cookie',
            'prefix'  => 'regex',
            'content' => '(?:^|;\\s*)(?:' . implode( '|', $escaped ) . ')=(?:' . self::MANAGED_MARKER . '){0}',
        ];
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

    /**
     * @param Cache_Provider $provider Selected provider.
     * @param array          $status Pending compatibility state.
     * @return array
     */
    private function rebuild( Cache_Provider $provider, array $status ) {
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

        $status['applied_hash'] = $status['config_hash'];
        $status['applied_at']   = (int) call_user_func( $this->clock );
        update_option( self::OPTION_NAME, $status, false );

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
}
