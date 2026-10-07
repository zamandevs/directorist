<?php

namespace Directorist\Cache;

/**
 * Normalized outcome status independent of provider implementation details.
 */
final class Performance_Status {
    /** @var Cache_Provider */
    private $provider;

    /** @var Performance_Settings */
    private $settings;

    /** @var Performance_Event_Log */
    private $events;

    /** @var callable */
    private $lifecycle_resolver;

    /** @var callable */
    private $warm_status_resolver;

    /** @var callable */
    private $cleanup_status_resolver;

    /**
     * @param Cache_Provider            $provider Selected provider.
     * @param Performance_Settings|null $settings Settings source.
     * @param Performance_Event_Log|null $events Event log.
     * @param callable|null              $lifecycle_resolver Lifecycle state resolver.
     * @param callable|null              $warm_status_resolver Warm queue status resolver.
     * @param callable|null              $cleanup_status_resolver Cleanup queue status resolver.
     */
    public function __construct( Cache_Provider $provider, Performance_Settings $settings = null, Performance_Event_Log $events = null, $lifecycle_resolver = null, $warm_status_resolver = null, $cleanup_status_resolver = null ) {
        $this->provider = $provider;
        $this->settings = $settings ?: new Performance_Settings();
        $this->events   = $events ?: new Performance_Event_Log( $this->settings );
        $this->lifecycle_resolver      = is_callable( $lifecycle_resolver ) ? $lifecycle_resolver : static function () {
            return get_site_option( \Directorist\Cache\Built_In\Lifecycle::STATE_OPTION, [] );
        };
        $this->warm_status_resolver    = is_callable( $warm_status_resolver ) ? $warm_status_resolver : static function () {
            return function_exists( 'directorist_page_cache_warm_background_process' )
                ? directorist_page_cache_warm_background_process()->status()
                : [];
        };
        $this->cleanup_status_resolver = is_callable( $cleanup_status_resolver ) ? $cleanup_status_resolver : static function () {
            return function_exists( 'directorist_page_cache_builtin_cleanup_process' )
                ? directorist_page_cache_builtin_cleanup_process()->status()
                : [];
        };
    }

    /**
     * @param array $inventory_args Bounded built-in inventory list arguments.
     * @return array
     */
    public function snapshot( array $inventory_args = [] ) {
        $include_inventory = ! array_key_exists( 'include_inventory', $inventory_args ) || ! empty( $inventory_args['include_inventory'] );
        unset( $inventory_args['include_inventory'] );
        $provider           = $this->provider_status();
        $settings           = $this->settings->get();
        $lifecycle          = $this->safe_array( $this->lifecycle_resolver );
        $events             = $this->events->recent();
        $mode               = $this->delivery_mode( $lifecycle, $provider['id'] );
        $state              = isset( $lifecycle['state'] ) ? sanitize_key( (string) $lifecycle['state'] ) : $mode;
        $outcome            = ! $settings['enabled']
            ? 'disabled'
            : ( $provider['available'] && ! in_array( $state, [ 'blocked', 'unavailable' ], true ) ? 'optimized' : 'needs-attention' );
        $inventory          = $include_inventory
            ? $this->provider_inventory( $provider, $inventory_args )
            : [ 'success' => false, 'code' => 'not-requested' ];
        $inventory          = $this->inventory( $inventory );
        $diagnostics_active = $settings['diagnostics_until'] >= time();
        $status             = [
            'settings'      => $settings,
            'outcome'       => $outcome,
            'delivery'      => [
                'mode'       => $mode,
                'state'      => $state,
                'code'       => isset( $lifecycle['code'] ) ? sanitize_key( (string) $lifecycle['code'] ) : '',
                'provider'   => $provider['id'],
                'updated_at' => isset( $lifecycle['updated_at'] ) ? max( 0, (int) $lifecycle['updated_at'] ) : 0,
            ],
            'provider'      => $provider,
            'metrics'       => $inventory,
            'inventory'     => $inventory,
            'warm_queue'    => $this->safe_array( $this->warm_status_resolver ),
            'cleanup'       => $this->safe_array( $this->cleanup_status_resolver ),
            'automation'    => [
                'invalidation' => $provider['available'] && ( in_array( Provider_Capabilities::PURGE_SITE, $provider['capabilities'], true ) || in_array( Provider_Capabilities::PURGE_URL, $provider['capabilities'], true ) ),
                'warming'      => $provider['available'] && in_array( Provider_Capabilities::WARM_URLS, $provider['capabilities'], true ),
                'cleanup'      => 'built_in' === $mode,
            ],
            'last_outcomes' => [
                'invalidation' => $this->latest_event( $events, [ 'invalidat', 'purg' ] ),
                'warm'         => $this->latest_event( $events, [ 'warm', 'queued' ] ),
                'cleanup'      => $this->latest_event( $events, [ 'clean' ] ),
                'health'       => $this->latest_event( $events, [ 'health', 'runtime', 'lifecycle' ] ),
            ],
            'diagnostics'   => [
                'active' => $diagnostics_active,
                'until'  => $settings['diagnostics_until'],
            ],
            'events'        => $diagnostics_active ? $events : [],
            'route_flow'    => $this->route_flow( $events ),
            'extensions' => [],
        ];
        $extended = apply_filters( 'directorist_page_cache_performance_status', $status, $this->provider );

        return is_array( $extended ) ? $extended : $status;
    }

    /** @return array */
    private function provider_status() {
        try {
            $status       = $this->provider->get_status();
            $id           = sanitize_key( (string) $this->provider->get_id() );
            $available       = (bool) $this->provider->is_available();
            $capabilities    = array_values( array_map( 'sanitize_key', $this->provider->get_capabilities() ) );
            $status       = is_array( $status ) ? $status : [];
            $available    = $available && ( ! array_key_exists( 'configuration_safe', $status ) || ! empty( $status['configuration_safe'] ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $status       = [ 'available' => false, 'code' => 'provider_exception' ];
            $id           = 'unknown';
            $available       = false;
            $capabilities    = [];
        }

        return [
            'id'           => $id,
            'available'    => $available,
            'capabilities' => $capabilities,
            'status'       => $status,
        ];
    }

    /** @return array */
    private function inventory( array $inventory ) {
        $normalized = [
            'supported'        => ! empty( $inventory['success'] ),
            'code'             => isset( $inventory['code'] ) ? sanitize_key( (string) $inventory['code'] ) : 'unavailable',
            'scope'            => isset( $inventory['scope'] ) ? sanitize_key( (string) $inventory['scope'] ) : 'directorist',
            'entries'          => isset( $inventory['entries'] ) ? max( 0, (int) $inventory['entries'] ) : 0,
            'cached_entries'   => isset( $inventory['cached_entries'] ) ? max( 0, (int) $inventory['cached_entries'] ) : 0,
            'inactive_entries' => isset( $inventory['inactive_entries'] ) ? max( 0, (int) $inventory['inactive_entries'] ) : 0,
            'bytes'            => isset( $inventory['bytes'] ) ? max( 0, (int) $inventory['bytes'] ) : 0,
            'cached_bytes'     => isset( $inventory['cached_bytes'] ) ? max( 0, (int) $inventory['cached_bytes'] ) : 0,
            'inactive_bytes'   => isset( $inventory['inactive_bytes'] ) ? max( 0, (int) $inventory['inactive_bytes'] ) : 0,
            'orphans'          => isset( $inventory['orphans'] ) ? max( 0, (int) $inventory['orphans'] ) : 0,
            'generations'      => isset( $inventory['generations'] ) ? max( 0, (int) $inventory['generations'] ) : 0,
            'truncated'        => ! empty( $inventory['truncated'] ),
            'generated_at'     => isset( $inventory['generated_at'] ) ? max( 0, (int) $inventory['generated_at'] ) : 0,
            'matched_entries'  => isset( $inventory['matched_entries'] ) ? max( 0, (int) $inventory['matched_entries'] ) : 0,
            'groups'           => [],
            'items'            => [],
            'page'             => isset( $inventory['page'] ) ? max( 1, (int) $inventory['page'] ) : 1,
            'per_page'         => isset( $inventory['per_page'] ) ? min( 50, max( 1, (int) $inventory['per_page'] ) ) : 20,
            'pages'            => isset( $inventory['pages'] ) ? max( 1, (int) $inventory['pages'] ) : 1,
            'route_type'       => isset( $inventory['route_type'] ) ? sanitize_key( (string) $inventory['route_type'] ) : '',
        ];

        foreach ( isset( $inventory['groups'] ) && is_array( $inventory['groups'] ) ? $inventory['groups'] : [] as $group ) {
            $route_type = isset( $group['route_type'] ) ? sanitize_key( (string) $group['route_type'] ) : '';

            if ( '' === $route_type || ! preg_match( '/^[a-z0-9-]{1,64}$/', $route_type ) ) {
                continue;
            }

            $normalized['groups'][ $route_type ] = [
                'route_type' => $route_type,
                'entries'    => isset( $group['entries'] ) ? max( 0, (int) $group['entries'] ) : 0,
                'bytes'      => isset( $group['bytes'] ) ? max( 0, (int) $group['bytes'] ) : 0,
            ];
        }

        foreach ( array_slice( isset( $inventory['items'] ) && is_array( $inventory['items'] ) ? $inventory['items'] : [], 0, 50 ) as $item ) {
            $hash       = isset( $item['hash'] ) ? strtolower( (string) $item['hash'] ) : '';
            $url        = isset( $item['canonical_url'] ) ? esc_url_raw( (string) $item['canonical_url'] ) : '';
            $route_type = isset( $item['route_type'] ) ? sanitize_key( (string) $item['route_type'] ) : '';
            $state      = isset( $item['state'] ) ? sanitize_key( (string) $item['state'] ) : '';
            $scheme     = wp_parse_url( $url, PHP_URL_SCHEME );

            if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || '' === $url || ! in_array( $scheme, [ 'http', 'https' ], true ) || ! preg_match( '/^[a-z0-9-]{1,64}$/', $route_type ) || ! in_array( $state, [ 'current', 'stale', 'expired', 'invalidated' ], true ) ) {
                continue;
            }

            $normalized['items'][] = [
                'hash'          => $hash,
                'canonical_url' => $url,
                'route_type'    => $route_type,
                'created_at'    => isset( $item['created_at'] ) ? max( 0, (int) $item['created_at'] ) : 0,
                'expires_at'    => isset( $item['expires_at'] ) ? max( 0, (int) $item['expires_at'] ) : 0,
                'stale_until'   => isset( $item['stale_until'] ) ? max( 0, (int) $item['stale_until'] ) : 0,
                'body_size'     => isset( $item['body_size'] ) ? max( 0, (int) $item['body_size'] ) : 0,
                'state'         => $state,
                'language'      => isset( $item['language'] ) ? sanitize_key( (string) $item['language'] ) : '',
                'has_query'     => ! empty( $item['has_query'] ),
                'site_id'       => isset( $item['site_id'] ) ? max( 0, (int) $item['site_id'] ) : 0,
                'object_id'     => isset( $item['object_id'] ) ? max( 0, (int) $item['object_id'] ) : 0,
                'page_id'       => isset( $item['page_id'] ) ? max( 0, (int) $item['page_id'] ) : 0,
            ];
        }

        return $normalized;
    }

    /**
     * @param array $provider Normalized provider status.
     * @param array $args Inventory arguments.
     * @return array
     */
    private function provider_inventory( array $provider, array $args ) {
        if ( 'directorist-cache' === $provider['id'] && is_callable( [ $this->provider, 'get_inventory' ] ) ) {
            try {
                $inventory = $this->provider->get_inventory( $args );

                return is_array( $inventory ) ? $inventory : [];
            } catch ( \Throwable $exception ) {
                unset( $exception );

                return [];
            }
        }

        return isset( $provider['status']['inventory'] ) && is_array( $provider['status']['inventory'] )
            ? $provider['status']['inventory']
            : [];
    }

    /** @return array */
    private function safe_array( $resolver ) {
        try {
            $value = call_user_func( $resolver );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return [];
        }

        return is_array( $value ) ? $value : [];
    }

    /** @return string */
    private function delivery_mode( array $lifecycle, $provider_id ) {
        $state = isset( $lifecycle['state'] ) ? sanitize_key( (string) $lifecycle['state'] ) : '';

        if ( in_array( $state, [ 'built_in', 'external', 'blocked', 'disabled', 'unavailable' ], true ) ) {
            return $state;
        }

        if ( 'directorist-cache' === $provider_id ) {
            return 'built_in';
        }

        return in_array( $provider_id, [ '', 'none', 'unknown' ], true ) ? 'unavailable' : 'external';
    }

    /** @return array */
    private function latest_event( array $events, array $needles ) {
        foreach ( $events as $event ) {
            $code = isset( $event['code'] ) ? (string) $event['code'] : '';

            foreach ( $needles as $needle ) {
                if ( false !== strpos( $code, $needle ) ) {
                    return $event;
                }
            }
        }

        return [];
    }

    /** @return array */
    private function route_flow( array $events ) {
        $flow = [ 'eligible' => 0, 'bypassed' => 0, 'queued' => 0, 'cached' => 0, 'invalidated' => 0 ];

        foreach ( $events as $event ) {
            $context = isset( $event['context'] ) && is_array( $event['context'] ) ? $event['context'] : [];

            if ( isset( $context['eligible'] ) ) {
                ++$flow[ 'yes' === $context['eligible'] ? 'eligible' : 'bypassed' ];
            }

            $code = isset( $event['code'] ) ? $event['code'] : '';

            if ( false !== strpos( $code, 'invalidat' ) || false !== strpos( $code, 'purg' ) ) {
                ++$flow['invalidated'];
            }

            if ( false !== strpos( $code, 'warm' ) || false !== strpos( $code, 'queued' ) ) {
                ++$flow['queued'];
            }
        }

        return $flow;
    }
}
