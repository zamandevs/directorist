<?php

namespace Directorist\Cache;

use Directorist\Cache\Built_In\Cache_Storage;
use Directorist\Cache\Built_In\Request_Key;

/**
 * Resumable resource-catalog reconciliation coordinated by Directorist workers.
 */
final class Performance_Resource_Reconciler {
    const BATCH_SIZE = 100;
    const LOCK_TTL   = 15 * MINUTE_IN_SECONDS;

    /** @var Performance_Resource_Store */
    private $store;

    /** @var Performance_Resource_Discovery */
    private $discovery;

    /** @var callable */
    private $dispatcher;

    /** @var callable */
    private $clock;

    public function __construct( Performance_Resource_Store $store = null, Performance_Resource_Discovery $discovery = null, $dispatcher = null, $clock = null ) {
        $this->store      = $store ?: new Performance_Resource_Store();
        $this->discovery  = $discovery ?: new Performance_Resource_Discovery();
        $this->dispatcher = is_callable( $dispatcher ) ? $dispatcher : static function ( $generation ) {
            return function_exists( 'directorist_page_cache_performance_resource_process' )
                && directorist_page_cache_performance_resource_process()->enqueue( $generation );
        };
        $this->clock      = is_callable( $clock ) ? $clock : 'time';
    }

    /** @return array */
    public function start( $force = false ) {
        if ( ! $this->store->exists() && ! $this->store->create() ) {
            return [ 'success' => false, 'code' => 'schema-unavailable' ];
        }

        $status = $this->store->status();
        $now    = (int) call_user_func( $this->clock );

        if ( ! $force && 'building' === ( isset( $status['state'] ) ? $status['state'] : '' ) && $now - (int) $status['updated_at'] < self::LOCK_TTL ) {
            return [ 'success' => true, 'code' => 'already-running', 'status' => $status ];
        }

        $generation = max( $now, isset( $status['generation'] ) ? (int) $status['generation'] + 1 : 1 );
        $status     = $this->store->begin_generation( $generation );

        if ( ! is_array( $status ) ) {
            return [ 'success' => false, 'code' => 'catalog-busy', 'status' => $this->store->status() ];
        }

        $generation = (int) $status['generation'];

        try {
            $dispatched = call_user_func( $this->dispatcher, $generation );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $dispatched = false;
        }

        if ( ! $dispatched ) {
            $status = $this->store->update_generation_status( $generation, [ 'state' => 'failed', 'errors' => 1, 'code' => 'dispatch-failed' ] );

            if ( false === $status ) {
                return [ 'success' => false, 'code' => 'stale-generation', 'status' => $this->store->status() ];
            }

            return [ 'success' => false, 'code' => 'dispatch-failed', 'status' => $status ];
        }

        return [ 'success' => true, 'code' => 'queued', 'status' => $status ];
    }

    /** @return array */
    public function process( $generation ) {
        $status     = $this->store->status();
        $generation = max( 1, (int) $generation );

        if ( 'building' !== ( isset( $status['state'] ) ? $status['state'] : '' ) || $generation !== (int) $status['generation'] ) {
            return [ 'success' => false, 'code' => 'stale-generation', 'status' => $status ];
        }

        $phase  = isset( $status['phase'] ) ? sanitize_key( (string) $status['phase'] ) : 'listing';
        $cursor = isset( $status['cursor'] ) ? max( 0, (int) $status['cursor'] ) : 0;
        try {
            $batch = $this->discovery->batch( $phase, $cursor, self::BATCH_SIZE );
            $items = isset( $batch['items'] ) && is_array( $batch['items'] ) ? $batch['items'] : [];

            if ( ! $this->store->is_building_generation( $generation ) ) {
                return [ 'success' => false, 'code' => 'stale-generation', 'status' => $this->store->status() ];
            }

            $stored = $this->store->upsert( $items, $generation );

            if ( $stored < count( $items ) ) {
                throw new \RuntimeException( 'resource-store-incomplete' );
            }

            $this->sync_cache_states( $items );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            if ( ! $this->store->is_building_generation( $generation ) ) {
                return [ 'success' => false, 'code' => 'stale-generation', 'status' => $this->store->status() ];
            }

            $errors = ( isset( $status['errors'] ) ? max( 0, (int) $status['errors'] ) : 0 ) + 1;
            $status = $this->store->update_generation_status( $generation, [ 'errors' => $errors, 'state' => 3 <= $errors ? 'failed' : 'building', 'code' => 'batch-failed' ] );

            if ( false === $status ) {
                return [ 'success' => false, 'code' => 'stale-generation', 'status' => $this->store->status() ];
            }

            return [ 'success' => 3 > $errors, 'code' => 3 <= $errors ? 'failed' : 'processing', 'status' => $status ];
        }
        $status = $this->store->update_generation_status(
            $generation,
            [
                'processed' => ( isset( $status['processed'] ) ? max( 0, (int) $status['processed'] ) : 0 ) + $stored,
                'cursor'    => isset( $batch['next_cursor'] ) ? max( 0, (int) $batch['next_cursor'] ) : $cursor + 1,
                'errors'    => 0,
                'code'      => 'processing',
            ]
        );

        if ( false === $status ) {
            return [ 'success' => false, 'code' => 'stale-generation', 'status' => $this->store->status() ];
        }

        if ( empty( $batch['done'] ) ) {
            return [ 'success' => true, 'code' => 'processing', 'status' => $status ];
        }

        $phase_index = array_search( $phase, Performance_Resource_Discovery::PHASES, true );

        if ( false !== $phase_index && isset( Performance_Resource_Discovery::PHASES[ $phase_index + 1 ] ) ) {
            $status = $this->store->update_generation_status( $generation, [ 'phase' => Performance_Resource_Discovery::PHASES[ $phase_index + 1 ], 'cursor' => 0 ] );

            if ( false === $status ) {
                return [ 'success' => false, 'code' => 'stale-generation', 'status' => $this->store->status() ];
            }

            return [ 'success' => true, 'code' => 'processing', 'status' => $status ];
        }

        if ( ! $this->store->complete_generation( $generation ) ) {
            return [ 'success' => false, 'code' => 'stale-generation', 'status' => $this->store->status() ];
        }

        return [ 'success' => true, 'code' => 'completed', 'status' => $this->store->status() ];
    }

    /** @return array */
    public function status() {
        return $this->store->status();
    }

    private function sync_cache_states( array $resources ) {
        $storage = new Cache_Storage( WP_CONTENT_DIR . '/cache/directorist-page-cache' );

        foreach ( $resources as $resource ) {
            if ( empty( $resource['url'] ) ) {
                continue;
            }

            $key = ( new Request_Key() )->from_url( $resource['url'] );

            if ( empty( $key['success'] ) ) {
                continue;
            }

            $this->store->update_cache_state( $resource['url'], $storage->inspect( $key ) );
        }
    }
}
