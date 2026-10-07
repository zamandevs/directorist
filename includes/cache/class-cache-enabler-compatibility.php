<?php

namespace Directorist\Cache;

/**
 * Adds reversible private-route and cookie exclusions to Cache Enabler.
 */
final class Cache_Enabler_Compatibility {
    const OPTION_NAME            = 'directorist_page_cache_cache_enabler_compatibility_v1';
    const MANAGED_MARKER         = 'directorist-page-cache-language';
    const PRIVATE_MARKER         = 'directorist-page-cache-private';
    const DEFAULT_COOKIE_REGEX   = '/^(wp-postpass|wordpress_logged_in|comment_author)_/';
    const REQUEST_POLICY_VERSION = 2;

    private $private_paths;
    private $response_guard;
    private $registered = false;

    /** @var callable */
    private $read_settings;

    /** @var callable */
    private $write_settings;

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
        $this->response_guard = new External_Response_Guard( $runtime );
        $this->read_settings     = isset( $runtime['read_settings'] ) && is_callable( $runtime['read_settings'] ) ? $runtime['read_settings'] : static function () {
            if ( ! is_callable( [ 'Cache_Enabler', 'get_settings' ] ) ) {
                return [];
            }

            $settings = \Cache_Enabler::get_settings( false );

            return is_array( $settings ) ? $settings : [];
        };
        $this->write_settings    = isset( $runtime['write_settings'] ) && is_callable( $runtime['write_settings'] ) ? $runtime['write_settings'] : static function ( array $settings ) {
            if ( ! is_callable( [ 'Cache_Enabler', 'validate_settings' ] ) || ! is_callable( [ 'Cache_Enabler_Disk', 'create_settings_file' ] ) ) {
                return false;
            }

            $settings = \Cache_Enabler::validate_settings( $settings );

            if ( ! is_array( $settings ) || false === \Cache_Enabler_Disk::create_settings_file( $settings ) ) {
                return false;
            }

            update_option( 'cache_enabler', $settings, false );

            if ( class_exists( 'Cache_Enabler_Engine' ) ) {
                \Cache_Enabler_Engine::$settings = $settings;
            }

            return get_option( 'cache_enabler', [] ) === $settings;
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
        add_filter( 'cache_enabler_bypass_cache', [ $this, 'bypass_cache' ], PHP_INT_MAX );
        $this->registered = true;
        return true;
    }

    public function bypass_cache( $bypass ) {
        // The native filter also runs before the main query has resolved.
        return $bypass || ( did_action( 'template_redirect' ) && ! $this->response_guard->allows_storage() );
    }

    /**
     * @param Cache_Provider $provider Selected Cache Enabler provider.
     * @return array
     */
    public function activate( Cache_Provider $provider ) {
        if ( 'cache-enabler' !== $provider->get_id() || ! $provider->is_available() ) {
            return [ 'success' => false, 'code' => 'provider_unavailable' ];
        }

        $settings = $this->read();

        if ( empty( $settings ) ) {
            return [ 'success' => false, 'code' => 'configuration_unavailable' ];
        }

        $previous = self::current();
        $current  = isset( $settings['excluded_cookies'] ) ? (string) $settings['excluded_cookies'] : '';
        $original = $this->original_regex( $current, $previous );
        $managed  = $this->managed_regex( '' === $original ? self::DEFAULT_COOKIE_REGEX : $original, $this->language_cookie_names() );
        $path_current = isset( $settings['excluded_page_paths'] ) ? (string) $settings['excluded_page_paths'] : '';
        $path_previous = [
            'managed_regex' => isset( $previous['managed_path_regex'] ) ? $previous['managed_path_regex'] : null,
            'original_regex' => isset( $previous['original_path_regex'] ) ? $previous['original_path_regex'] : null,
        ];
        $path_original = $this->original_regex( $path_current, $path_previous, self::PRIVATE_MARKER );
        $paths = call_user_func( $this->private_paths );
        $pattern = External_Response_Guard::path_pattern( is_array( $paths ) ? $paths : [] );
        $path_managed = '' === $pattern ? $path_original : '/(?:' . $pattern . ')(?#' . self::PRIVATE_MARKER . ')' . ( '' !== $path_original ? '|(?:' . substr( $path_original, 1, -1 ) . ')' : '' ) . '/';
        $hash     = hash( 'sha256', wp_json_encode( [ self::REQUEST_POLICY_VERSION, $managed, $path_managed ] ) );
        $changed  = $current !== $managed || $path_current !== $path_managed;

        if ( $changed ) {
            $settings['excluded_cookies'] = $managed;
            $settings['excluded_page_paths'] = $path_managed;

            if ( ! $this->write( $settings ) ) {
                return [ 'success' => false, 'code' => 'configuration_write_failed' ];
            }
        }

        $status = [
            'policy_version' => self::REQUEST_POLICY_VERSION,
            'config_hash'    => $hash,
            'applied_hash'   => isset( $previous['applied_hash'] ) ? (string) $previous['applied_hash'] : '',
            'original_regex' => $original,
            'managed_regex'  => $managed,
            'original_path_regex' => $path_original,
            'managed_path_regex' => $path_managed,
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
        remove_filter( 'cache_enabler_bypass_cache', [ $this, 'bypass_cache' ], PHP_INT_MAX );
        $this->registered = false;
        $status = self::current();

        if ( empty( $status ) ) {
            return [ 'success' => true, 'code' => 'configuration_absent' ];
        }

        $settings = $this->read();
        $managed  = isset( $status['managed_regex'] ) ? (string) $status['managed_regex'] : '';
        $current  = isset( $settings['excluded_cookies'] ) ? (string) $settings['excluded_cookies'] : '';

        $changed = false;
        if ( '' !== $managed && $managed === $current ) {
            $settings['excluded_cookies'] = isset( $status['original_regex'] ) ? (string) $status['original_regex'] : '';
            $changed = true;
        }
        if ( ! empty( $status['managed_path_regex'] ) && isset( $settings['excluded_page_paths'] ) && $settings['excluded_page_paths'] === $status['managed_path_regex'] ) {
            $settings['excluded_page_paths'] = isset( $status['original_path_regex'] ) ? (string) $status['original_path_regex'] : '';
            $changed = true;
        }
        if ( $changed && ! $this->write( $settings ) ) {
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
            $settings = call_user_func( $this->read_settings );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $settings = [];
        }

        return is_array( $settings ) ? $settings : [];
    }

    /**
     * @param array $settings Complete Cache Enabler settings.
     * @return bool
     */
    private function write( array $settings ) {
        try {
            return (bool) call_user_func( $this->write_settings, $settings );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }
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
     * @param string $current Current provider regex.
     * @param array  $previous Directorist compatibility state.
     * @return string
     */
    private function original_regex( $current, array $previous, $owner = self::MANAGED_MARKER ) {
        if ( isset( $previous['managed_regex'], $previous['original_regex'] ) && $current === (string) $previous['managed_regex'] ) {
            return (string) $previous['original_regex'];
        }

        $marker = '(?#' . $owner . ')';

        if ( false === strpos( $current, $marker ) ) {
            return $current;
        }

        $suffix = $marker . '|(?:';
        $start  = strpos( $current, $suffix );

        if ( false !== $start && 2 <= strlen( $current ) && ')/' === substr( $current, -2 ) ) {
            return '/' . substr( $current, $start + strlen( $suffix ), -2 ) . '/';
        }

        return '';
    }

    /**
     * @param string   $original User-owned regex.
     * @param string[] $names Bounded language-cookie names.
     * @return string
     */
    private function managed_regex( $original, array $names ) {
        $escaped  = array_map(
            static function ( $name ) {
                return preg_quote( $name, '/' );
            },
            $names
        );
        $language = '/^(?:' . implode( '|', $escaped ) . ')$(?#' . self::MANAGED_MARKER . ')';

        if ( '' === $original || 2 > strlen( $original ) || '/' !== substr( $original, 0, 1 ) || '/' !== substr( $original, -1 ) ) {
            return $language . '/';
        }

        return $language . '|(?:' . substr( $original, 1, -1 ) . ')/';
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
