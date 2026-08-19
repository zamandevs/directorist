<?php

namespace Directorist\Cache;

/**
 * Dispatches one coalesced invalidation plan at a safe request boundary.
 */
final class Invalidation_Dispatcher {
    /** @var Change_Set */
    private $changes;

    /** @var Invalidation_Planner */
    private $planner;

    /** @var Cache_Provider */
    private $provider;

    /**
     * @param Change_Set           $changes Request-local changes.
     * @param Invalidation_Planner $planner Invalidation planner.
     * @param Cache_Provider       $provider Active provider.
     */
    public function __construct( Change_Set $changes, Invalidation_Planner $planner, Cache_Provider $provider ) {
        $this->changes  = $changes;
        $this->planner  = $planner;
        $this->provider = $provider;
    }

    /** @return array */
    public function dispatch() {
        if ( $this->changes->is_empty() ) {
            return [ 'success' => true, 'code' => 'no_changes' ];
        }

        $plan = $this->planner->build( $this->changes )->to_array();
        $this->changes->reset();

        try {
            $result = $this->provider->invalidate( $plan );

            if ( ! is_array( $result ) ) {
                $result = [
                    'success'  => false,
                    'code'     => 'invalid_provider_result',
                    'provider' => $this->provider_id(),
                ];
            }
        } catch ( \Throwable $exception ) {
            $result = [
                'success'  => false,
                'code'     => 'provider_exception',
                'provider' => $this->provider_id(),
            ];

            do_action( 'directorist_page_cache_invalidation_failed', $result, $plan, $exception );

            return $result;
        }

        if ( empty( $result['success'] ) ) {
            do_action( 'directorist_page_cache_invalidation_failed', $result, $plan, null );
        } else {
            do_action( 'directorist_page_cache_invalidated', $result, $plan );
        }

        return $result;
    }

    /** @return string */
    private function provider_id() {
        try {
            return sanitize_key( (string) $this->provider->get_id() );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return 'unknown';
        }
    }
}
