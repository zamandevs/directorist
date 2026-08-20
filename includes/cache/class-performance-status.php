<?php

namespace Directorist\Cache;

/**
 * Normalized dashboard status independent of provider implementation details.
 */
final class Performance_Status {
    /** @var Cache_Provider */
    private $provider;

    /** @var Performance_Settings */
    private $settings;

    /** @var Performance_Event_Log */
    private $events;

    /**
     * @param Cache_Provider            $provider Selected provider.
     * @param Performance_Settings|null $settings Settings source.
     * @param Performance_Event_Log|null $events Event log.
     */
    public function __construct( Cache_Provider $provider, Performance_Settings $settings = null, Performance_Event_Log $events = null ) {
        $this->provider = $provider;
        $this->settings = $settings ?: new Performance_Settings();
        $this->events   = $events ?: new Performance_Event_Log( $this->settings );
    }

    /** @return array */
    public function snapshot() {
        try {
            $provider_status = $this->provider->get_status();
            $provider_id     = sanitize_key( (string) $this->provider->get_id() );
            $available       = (bool) $this->provider->is_available();
            $capabilities    = array_values( array_map( 'sanitize_key', $this->provider->get_capabilities() ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $provider_status = [ 'available' => false, 'code' => 'provider_exception' ];
            $provider_id     = 'unknown';
            $available       = false;
            $capabilities    = [];
        }

        $status   = [
            'settings'   => $this->settings->get(),
            'provider'   => [
                'id'           => $provider_id,
                'available'    => $available,
                'capabilities' => $capabilities,
                'status'       => is_array( $provider_status ) ? $provider_status : [],
            ],
            'events'     => $this->events->recent(),
            'route_flow' => $this->route_flow(),
            'extensions' => [],
        ];
        $extended = apply_filters( 'directorist_page_cache_performance_status', $status, $this->provider );

        return is_array( $extended ) ? $extended : $status;
    }

    /** @return array */
    private function route_flow() {
        $flow = [ 'eligible' => 0, 'bypassed' => 0, 'queued' => 0, 'cached' => 0, 'invalidated' => 0 ];

        foreach ( $this->events->recent() as $event ) {
            $context = isset( $event['context'] ) && is_array( $event['context'] ) ? $event['context'] : [];

            if ( isset( $context['eligible'] ) ) {
                ++$flow[ 'yes' === $context['eligible'] ? 'eligible' : 'bypassed' ];
            }

            if ( false !== strpos( isset( $event['code'] ) ? $event['code'] : '', 'invalidat' ) || 'purged' === ( isset( $event['code'] ) ? $event['code'] : '' ) ) {
                ++$flow['invalidated'];
            }
        }

        return $flow;
    }
}
