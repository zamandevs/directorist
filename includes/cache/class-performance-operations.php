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

    /**
     * @param Cache_Provider            $provider Selected provider.
     * @param Performance_Settings|null $settings Settings source.
     * @param Performance_Event_Log|null $events Event log.
     * @param callable|null             $discover Warm URL discovery.
     * @param callable|null             $site_id Site ID provider.
     */
    public function __construct( Cache_Provider $provider, Performance_Settings $settings = null, Performance_Event_Log $events = null, $discover = null, $site_id = null ) {
        $this->provider = $provider;
        $this->settings = $settings ?: new Performance_Settings();
        $this->events   = $events ?: new Performance_Event_Log( $this->settings );
        $this->discover = is_callable( $discover ) ? $discover : static function ( array $args ) {
            return directorist_page_cache_discover_warm_urls( $args );
        };
        $this->site_id  = is_callable( $site_id ) ? $site_id : 'get_current_blog_id';
    }

    /**
     * @param string $action Operation name.
     * @param array  $input Raw operation input.
     * @return array
     */
    public function execute( $action, array $input ) {
        $action  = sanitize_key( (string) $action );
        $allowed = [ 'save_settings', 'purge', 'warm', 'verify', 'pause', 'resume', 'cancel', 'clear_history', 'disable', 'enable' ];

        if ( ! in_array( $action, $allowed, true ) ) {
            return $this->result( false, 'unknown_operation' );
        }

        try {
            if ( 'save_settings' === $action ) {
                $settings = $this->settings->update( $input );
                do_action( 'directorist_page_cache_enabled_changed', $settings['enabled'], $settings );

                return array_merge( $this->result( true, 'settings_saved' ), [ 'settings' => $settings ] );
            }

            if ( in_array( $action, [ 'disable', 'enable' ], true ) ) {
                $enabled  = 'enable' === $action;
                $settings = $this->settings->update( [ 'enabled' => $enabled ] );
                do_action( 'directorist_page_cache_enabled_changed', $enabled, $settings );
                $result = $this->result( true, $enabled ? 'enabled' : 'disabled' );
            } elseif ( 'clear_history' === $action ) {
                $this->events->clear();

                return $this->result( true, 'history_cleared' );
            } elseif ( 'purge' === $action ) {
                $result = $this->purge();
            } elseif ( 'warm' === $action ) {
                $result = $this->warm( $input );
            } elseif ( 'verify' === $action ) {
                $result = $this->extension_operation( $action, $input );

                if ( 'operation_unavailable' === $result['code'] ) {
                    $status = $this->safe_status();
                    $result = array_merge( $this->result( ! empty( $status['available'] ), ! empty( $status['available'] ) ? 'healthy' : 'unhealthy' ), [ 'status' => $status ] );
                }
            } else {
                $result = $this->extension_operation( $action, $input );
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

        return $this->normalize_result(
            $this->provider->invalidate(
                [
                    'site_id'      => max( 1, (int) call_user_func( $this->site_id ) ),
                    'urls'         => [],
                    'dependencies' => [],
                    'generations'  => [],
                    'conservative' => true,
                ]
            )
        );
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
