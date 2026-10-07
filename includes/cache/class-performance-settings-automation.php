<?php

namespace Directorist\Cache;

/**
 * Applies cache invalidation and warming after user-facing policy changes.
 */
final class Performance_Settings_Automation {
    /** @var Cache_Provider */
    private $provider;

    /** @var callable */
    private $warmer;

    /** @var callable */
    private $site_id;

    public function __construct( Cache_Provider $provider, $warmer = null, $site_id = null ) {
        $this->provider = $provider;
        $this->warmer   = is_callable( $warmer ) ? $warmer : function ( array $invalidation, array $plan ) use ( $provider ) {
            return ( new Automatic_Warmer( $provider ) )->after_invalidation( $invalidation, $plan );
        };
        $this->site_id  = is_callable( $site_id ) ? $site_id : 'get_current_blog_id';
    }

    /**
     * @param array $current Normalized current settings.
     * @param array $previous Normalized previous settings.
     * @return array
     */
    public function apply( array $current, array $previous ) {
        $changed = [];

        foreach ( [ 'enabled', 'cache_duration', 'cache_filtered_results' ] as $key ) {
            if ( ! array_key_exists( $key, $current ) || ! array_key_exists( $key, $previous ) || $current[ $key ] !== $previous[ $key ] ) {
                $changed[] = $key;
            }
        }

        if ( empty( $changed ) ) {
            return $this->result( true, 'settings-unchanged' );
        }

        if ( empty( $current['enabled'] ) ) {
            return $this->result( true, 'cache-disabled' );
        }

        if ( ! $this->provider_available() || ! $this->can_purge() ) {
            return $this->result( false, 'provider-unavailable' );
        }

        $plan = [
            'site_id'      => max( 1, (int) call_user_func( $this->site_id ) ),
            'urls'         => [],
            'dependencies' => [],
            'generations'  => [ 'settings', 'template' ],
            'conservative' => true,
        ];

        try {
            $invalidation = $this->provider->invalidate( $plan );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->result( false, 'invalidation-exception' );
        }

        if ( ! is_array( $invalidation ) || empty( $invalidation['success'] ) ) {
            return array_merge(
                $this->result( false, 'invalidation-failed' ),
                [ 'invalidation' => is_array( $invalidation ) ? $invalidation : [] ]
            );
        }

        try {
            $warming = call_user_func( $this->warmer, $invalidation, $plan );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $warming = [ 'success' => false, 'code' => 'warming-exception' ];
        }

        return array_merge(
            $this->result( true, 'settings-refreshed' ),
            [
                'changed'      => $changed,
                'plan'         => $plan,
                'invalidation' => $invalidation,
                'warming'      => is_array( $warming ) ? $warming : [ 'success' => false, 'code' => 'invalid-warming-result' ],
            ]
        );
    }

    private function provider_available() {
        try {
            return (bool) $this->provider->is_available();
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }
    }

    private function can_purge() {
        try {
            return $this->provider->supports( Provider_Capabilities::PURGE_SITE )
                || ( $this->provider->supports( Provider_Capabilities::PURGE_DEPENDENCIES ) && $this->provider->supports( Provider_Capabilities::PURGE_GENERATIONS ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }
    }

    private function result( $success, $code ) {
        return [ 'success' => (bool) $success, 'code' => (string) $code ];
    }
}
