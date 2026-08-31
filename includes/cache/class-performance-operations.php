<?php

namespace Directorist\Cache;

/**
 * Capability-aware operations shared by the admin screen and integrations.
 */
final class Performance_Operations {
    /** @var Cache_Provider */
    private $provider;

    /** @var Performance_Settings */
    private $settings;

    /** @var Performance_Event_Log */
    private $events;

    /** @var callable */
    private $discover;

    /** @var callable */
    private $site_id;

    /** @var callable */
    private $record_invalidation;

    /**
     * @param Cache_Provider            $provider Selected provider.
     * @param Performance_Settings|null $settings Settings source.
     * @param Performance_Event_Log|null $events Event log.
     * @param callable|null             $discover Warm URL discovery.
     * @param callable|null             $site_id Site ID provider.
     * @param callable|null             $record_invalidation Successful invalidation recorder.
     */
    public function __construct( Cache_Provider $provider, Performance_Settings $settings = null, Performance_Event_Log $events = null, $discover = null, $site_id = null, $record_invalidation = null ) {
        $this->provider            = $provider;
        $this->settings            = $settings ?: new Performance_Settings();
        $this->events              = $events ?: new Performance_Event_Log( $this->settings );
        $this->discover            = is_callable( $discover ) ? $discover : static function ( array $args ) {
            return directorist_page_cache_discover_warm_urls( $args );
        };
        $this->site_id             = is_callable( $site_id ) ? $site_id : 'get_current_blog_id';
        $this->record_invalidation = is_callable( $record_invalidation ) ? $record_invalidation : static function ( array $result, array $plan ) {
            return function_exists( 'directorist_page_cache_record_resource_purge' )
                ? directorist_page_cache_record_resource_purge( $result, $plan )
                : false;
        };
    }

    /**
     * @param string $action Operation name.
     * @param array  $input Raw operation input.
     * @return array
     */
    public function execute( $action, array $input ) {
        $action  = sanitize_key( (string) $action );
        $allowed = [ 'save_settings', 'purge', 'purge_url', 'purge_urls', 'purge_route', 'purge_entry', 'warm', 'warm_urls', 'verify', 'disable', 'enable', 'enable_diagnostics', 'disable_diagnostics' ];

        if ( ! in_array( $action, $allowed, true ) ) {
            return $this->result( false, 'unknown_operation' );
        }

        try {
            if ( 'save_settings' === $action ) {
                $previous = $this->settings->get();
                $settings = $this->settings->update( $input );
                do_action( 'directorist_page_cache_enabled_changed', $settings['enabled'], $settings, $previous );
                do_action( 'directorist_page_cache_performance_settings_changed', $settings, $previous );

                return array_merge( $this->result( true, 'settings_saved' ), [ 'settings' => $settings ] );
            }

            if ( in_array( $action, [ 'enable_diagnostics', 'disable_diagnostics' ], true ) ) {
                $enabled  = 'enable_diagnostics' === $action;
                $settings = $this->settings->update(
                    [
                        'diagnostics_until' => $enabled ? time() + HOUR_IN_SECONDS : 0,
                        'sample_rate'       => $enabled ? 5 : 0,
                    ]
                );

                return array_merge( $this->result( true, $enabled ? 'diagnostics_enabled' : 'diagnostics_disabled' ), [ 'settings' => $settings ] );
            }

            if ( in_array( $action, [ 'disable', 'enable' ], true ) ) {
                $enabled  = 'enable' === $action;
                $previous = $this->settings->get();
                $settings = $this->settings->update( [ 'enabled' => $enabled ] );
                do_action( 'directorist_page_cache_enabled_changed', $enabled, $settings, $previous );
                do_action( 'directorist_page_cache_performance_settings_changed', $settings, $previous );
                $result = $this->result( true, $enabled ? 'enabled' : 'disabled' );
            } elseif ( 'purge' === $action ) {
                $result = $this->purge();
            } elseif ( 'purge_url' === $action ) {
                $result = $this->purge_url( isset( $input['url'] ) ? $input['url'] : '' );
            } elseif ( 'purge_urls' === $action ) {
                $result = $this->purge_urls( isset( $input['urls'] ) && is_array( $input['urls'] ) ? $input['urls'] : [] );
            } elseif ( 'purge_route' === $action ) {
                $result = $this->purge_route( isset( $input['route_type'] ) ? $input['route_type'] : '' );
            } elseif ( 'purge_entry' === $action ) {
                $result = $this->purge_entry( isset( $input['entry_hash'] ) ? $input['entry_hash'] : '' );
            } elseif ( 'warm' === $action ) {
                $result = $this->warm( $input );
            } elseif ( 'warm_urls' === $action ) {
                $result = $this->warm_urls( isset( $input['urls'] ) && is_array( $input['urls'] ) ? $input['urls'] : [] );
            } elseif ( 'verify' === $action ) {
                $result = $this->extension_operation( $action, $input );

                if ( 'operation_unavailable' === $result['code'] ) {
                    $status = $this->safe_status();
                    $result = array_merge( $this->result( ! empty( $status['available'] ), ! empty( $status['available'] ) ? 'healthy' : 'unhealthy' ), [ 'status' => $status ] );
                }
            }
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $result = $this->result( false, 'operation_exception' );
        }

        try {
            $provider_id = $this->provider->get_id();
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $provider_id = 'unknown';
        }

        $this->events->record( ! empty( $result['success'] ) ? 'success' : 'error', $result['code'], [ 'operation' => $action, 'provider' => $provider_id ] );

        return $result;
    }

    /** @return array */
    private function purge() {
        if ( ! $this->provider->supports( 'purge_site' ) && ! ( $this->provider->supports( 'purge_dependencies' ) && $this->provider->supports( 'purge_generations' ) ) ) {
            return $this->result( false, 'capability_unavailable' );
        }

        return $this->invalidate(
            [
                'site_id'      => max( 1, (int) call_user_func( $this->site_id ) ),
                'urls'         => [],
                'dependencies' => [],
                'generations'  => [],
                'conservative' => true,
            ]
        );
    }

    /**
     * @param string $url Candidate public URL.
     * @return array
     */
    private function purge_url( $url ) {
        $registry = new Warm_URL_Registry( home_url( '/' ), 1 );
        $registry->add( is_string( $url ) ? $url : '', 'manual-purge' );
        $urls = $registry->all();

        if ( empty( $urls ) ) {
            return $this->result( false, 'invalid_url' );
        }

        $path = wp_parse_url( $urls[0], PHP_URL_PATH );
        $path = is_string( $path ) ? strtolower( $path ) : '/';

        foreach ( [ '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php' ] as $private_path ) {
            if ( $private_path === $path || 0 === strpos( $path, $private_path . '/' ) ) {
                return $this->result( false, 'invalid_url' );
            }
        }

        return $this->invalidate(
            [
                'site_id'      => max( 1, (int) call_user_func( $this->site_id ) ),
                'urls'         => $urls,
                'dependencies' => [],
                'generations'  => [],
                'conservative' => false,
            ]
        );
    }

    /**
     * @param string[] $urls Public URLs.
     * @return array
     */
    private function purge_urls( array $urls ) {
        if ( ! $this->provider->supports( Provider_Capabilities::PURGE_URL ) && ! $this->provider->supports( Provider_Capabilities::PURGE_URLS ) ) {
            return $this->result( false, 'capability_unavailable' );
        }

        $urls = $this->public_urls( $urls );

        if ( empty( $urls ) ) {
            return $this->result( false, 'invalid_url' );
        }

        return $this->invalidate(
            [
                'site_id'      => max( 1, (int) call_user_func( $this->site_id ) ),
                'urls'         => $urls,
                'dependencies' => [],
                'generations'  => [],
                'conservative' => false,
            ]
        );
    }

    /**
     * @param string $route_type Directorist route identifier.
     * @return array
     */
    private function purge_route( $route_type ) {
        $route_type = is_string( $route_type ) ? strtolower( $route_type ) : '';

        if ( ! preg_match( '/^[a-z0-9-]{1,64}$/', $route_type ) ) {
            return $this->result( false, 'invalid_route' );
        }

        if ( ! $this->provider->supports( 'purge_dependencies' ) ) {
            return $this->result( false, 'capability_unavailable' );
        }

        $site_id = max( 1, (int) call_user_func( $this->site_id ) );

        return $this->invalidate(
            [
                'site_id'      => $site_id,
                'urls'         => [],
                'entry_hashes' => [],
                'dependencies' => [ 'directorist:' . $site_id . ':route:' . $route_type ],
                'generations'  => [],
                'conservative' => false,
            ]
        );
    }

    /**
     * @param string $entry_hash Inventory-selected request hash.
     * @return array
     */
    private function purge_entry( $entry_hash ) {
        if ( ! is_string( $entry_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $entry_hash ) ) {
            return $this->result( false, 'invalid_entry' );
        }

        if ( ! $this->provider->supports( 'purge_entries' ) ) {
            return $this->result( false, 'capability_unavailable' );
        }

        return $this->invalidate(
            [
                'site_id'      => max( 1, (int) call_user_func( $this->site_id ) ),
                'urls'         => [],
                'entry_hashes' => [ $entry_hash ],
                'dependencies' => [],
                'generations'  => [],
                'conservative' => false,
            ]
        );
    }

    /** @return array */
    private function invalidate( array $plan ) {
        $result = $this->normalize_result( $this->provider->invalidate( $plan ) );

        if ( empty( $result['success'] ) ) {
            return $result;
        }

        try {
            call_user_func( $this->record_invalidation, $result, $plan );
        } catch ( \Throwable $exception ) {
            unset( $exception );
        }

        return $result;
    }

    /**
     * @param array $input Warm limits.
     * @return array
     */
    private function warm( array $input ) {
        if ( ! $this->provider->supports( 'warm_urls' ) ) {
            return $this->result( false, 'capability_unavailable' );
        }

        $urls = call_user_func(
            $this->discover,
            [
                'listing_limit' => isset( $input['listing_limit'] ) ? min( 50, max( 0, absint( $input['listing_limit'] ) ) ) : 20,
                'term_limit'    => isset( $input['term_limit'] ) ? min( 100, max( 0, absint( $input['term_limit'] ) ) ) : 30,
                'page_limit'    => isset( $input['page_limit'] ) ? min( 10, max( 1, absint( $input['page_limit'] ) ) ) : 3,
            ]
        );

        return $this->normalize_result( $this->provider->warm( is_array( $urls ) ? $urls : [] ) );
    }

    /**
     * @param string[] $urls Public URLs.
     * @return array
     */
    private function warm_urls( array $urls ) {
        if ( ! $this->provider->supports( Provider_Capabilities::WARM_URLS ) ) {
            return $this->result( false, 'capability_unavailable' );
        }

        $urls = $this->public_urls( $urls );

        if ( empty( $urls ) ) {
            return $this->result( false, 'invalid_url' );
        }

        return $this->normalize_result( $this->provider->warm( $urls ) );
    }

    /** @return string[] */
    private function public_urls( array $urls ) {
        $registry = new Warm_URL_Registry( home_url( '/' ), 50 );
        $registry->add( $urls, 'performance-operation' );

        return $registry->all();
    }

    /**
     * @param string $action Extension operation.
     * @param array  $input Operation input.
     * @return array
     */
    private function extension_operation( $action, array $input ) {
        $result = apply_filters( 'directorist_page_cache_performance_operation', null, $action, $input, $this->provider );

        return is_array( $result ) ? $this->normalize_result( $result ) : $this->result( false, 'operation_unavailable' );
    }

    /** @return array */
    private function safe_status() {
        try {
            $status = $this->provider->get_status();
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return [ 'available' => false, 'code' => 'provider_exception' ];
        }

        return is_array( $status ) ? $status : [ 'available' => false, 'code' => 'invalid_provider_status' ];
    }

    /**
     * @param mixed $result Provider result.
     * @return array
     */
    private function normalize_result( $result ) {
        return is_array( $result ) && isset( $result['success'], $result['code'] )
            ? $result
            : $this->result( false, 'invalid_operation_result' );
    }

    /**
     * @param bool   $success Operation state.
     * @param string $code Stable result code.
     * @return array
     */
    private function result( $success, $code ) {
        return [
            'success' => (bool) $success,
            'code'    => (string) $code,
        ];
    }
}
