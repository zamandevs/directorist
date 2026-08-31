<?php

namespace Directorist\Cache;

/**
 * Queues bounded built-in cache refreshes shortly before soft expiry.
 */
final class Automatic_Refresh_Coordinator {
    const MAX_URLS     = 25;
    const CLAIM_LEASE  = 300;
    const DEFAULT_LEAD = 300;

    /** @var Cache_Provider */
    private $provider;

    /** @var object */
    private $resources;

    /** @var callable */
    private $clock;

    public function __construct( Cache_Provider $provider, $resources, $clock = null ) {
        $this->provider  = $provider;
        $this->resources = $resources;
        $this->clock     = is_callable( $clock ) ? $clock : 'time';
    }

    /**
     * @param int $lead Seconds before soft expiry.
     * @param int $limit Maximum URLs.
     * @return array
     */
    public function run( $lead = self::DEFAULT_LEAD, $limit = self::MAX_URLS ) {
        if ( 'directorist-cache' !== $this->provider->get_id() ) {
            return $this->result( true, 'provider_managed' );
        }

        if ( ! $this->provider->supports( Provider_Capabilities::WARM_URLS ) ) {
            return $this->result( false, 'preload_unavailable' );
        }

        if ( ! is_object( $this->resources )
            || ! is_callable( [ $this->resources, 'due_refresh_urls' ] )
            || ! is_callable( [ $this->resources, 'mark_refresh_requested' ] )
        ) {
            return $this->result( false, 'resource_catalog_unavailable' );
        }

        $now   = max( 1, (int) call_user_func( $this->clock ) );
        $lead  = min( HOUR_IN_SECONDS, max( MINUTE_IN_SECONDS, (int) $lead ) );
        $limit = min( self::MAX_URLS, max( 1, (int) $limit ) );

        try {
            $urls = $this->resources->due_refresh_urls( $now + $lead, $limit, $now - self::CLAIM_LEASE );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->result( false, 'resource_lookup_failed' );
        }

        $urls = is_array( $urls ) ? array_values( array_filter( array_unique( $urls ) ) ) : [];

        if ( empty( $urls ) ) {
            return $this->result( true, 'nothing_due' );
        }

        try {
            $queued = $this->provider->warm( $urls );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->result( false, 'preload_exception', [ 'discovered' => count( $urls ) ] );
        }

        $queued   = is_array( $queued ) ? $queued : [];
        $accepted = isset( $queued['accepted_urls'] ) && is_array( $queued['accepted_urls'] ) ? $queued['accepted_urls'] : [];

        if ( ! empty( $accepted ) ) {
            $this->resources->mark_refresh_requested( $accepted, $now );
        }

        $success = ! empty( $queued['success'] );

        return $this->result(
            $success,
            $success ? 'refresh_queued' : 'preload_failed',
            [
                'discovered' => count( $urls ),
                'queued'     => count( $accepted ),
                'more_due'   => $limit <= count( $urls ),
            ]
        );
    }

    private function result( $success, $code, array $extra = [] ) {
        return array_merge(
            [
                'success'  => (bool) $success,
                'code'     => (string) $code,
                'queued'   => 0,
                'more_due' => false,
            ],
            $extra
        );
    }
}
