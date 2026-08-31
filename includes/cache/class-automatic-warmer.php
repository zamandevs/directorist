<?php

namespace Directorist\Cache;

/**
 * Converts successful invalidation plans into bounded automatic warming.
 */
final class Automatic_Warmer {
    const MAX_URLS = 25;

    /** @var Cache_Provider */
    private $provider;

    /** @var callable */
    private $discover;

    /** @var bool */
    private $registered = false;

    /**
     * @param Cache_Provider $provider Selected provider.
     * @param callable|null  $discover Bounded URL discovery callback.
     */
    public function __construct( Cache_Provider $provider, $discover = null ) {
        $this->provider = $provider;
        $this->discover = is_callable( $discover ) ? $discover : static function ( array $args ) {
            $registry = new Warm_URL_Registry( home_url( '/' ), self::MAX_URLS );

            return ( new Warm_URL_Discovery( $registry ) )->discover( $args );
        };
    }

    /** @return bool */
    public function register() {
        if ( $this->registered ) {
            return false;
        }

        add_action( 'directorist_page_cache_invalidated', [ $this, 'after_invalidation' ], 10, 2 );
        $this->registered = true;

        return true;
    }

    /**
     * @param array $invalidation Provider invalidation result.
     * @param array $plan Normalized invalidation plan.
     * @return array
     */
    public function after_invalidation( $invalidation, $plan ) {
        if ( ! is_array( $invalidation ) || empty( $invalidation['success'] ) ) {
            return $this->result( false, 'invalidation_failed' );
        }

        if ( $this->is_internal_warm_request() ) {
            return $this->result( true, 'mutation_during_warm' );
        }

        if ( ! $this->provider->supports( Provider_Capabilities::WARM_URLS ) ) {
            return $this->result( false, 'warm_unsupported' );
        }

        $plan     = is_array( $plan ) ? $plan : [];
        $registry = new Warm_URL_Registry( home_url( '/' ), self::MAX_URLS );
        $registry->add( isset( $plan['urls'] ) ? $plan['urls'] : [], 'invalidation' );
        $conservative = ! empty( $plan['conservative'] );
        $generations  = isset( $plan['generations'] ) && is_array( $plan['generations'] ) ? $plan['generations'] : [];

        if ( $conservative || $this->affects_collections( $generations ) ) {
            $limits = $conservative
                ? [ 'listing_limit' => 5, 'term_limit' => 10, 'page_limit' => 2 ]
                : [ 'listing_limit' => 0, 'term_limit' => 0, 'page_limit' => 1 ];
            $registry->add( call_user_func( $this->discover, $limits ), 'affected-routes' );
        }

        $urls = $registry->all();

        if ( empty( $urls ) ) {
            return $this->result( true, 'no_urls' );
        }

        try {
            $result = $this->provider->warm( $urls );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->result( false, 'warm_exception' );
        }

        if ( ! is_array( $result ) || ! isset( $result['success'], $result['code'] ) ) {
            return $this->result( false, 'invalid_warm_result' );
        }

        if ( empty( $result['success'] ) && function_exists( 'directorist_page_cache_record_performance_event' ) ) {
            directorist_page_cache_record_performance_event(
                'warning',
                'automatic-warm-failed',
                [
                    'provider' => $this->provider_id(),
                    'code'     => (string) $result['code'],
                ]
            );
        }

        do_action( 'directorist_page_cache_automatic_warm_scheduled', $result, $urls, $plan );

        return $result;
    }

    /**
     * @param string[] $generations Generation keys.
     * @return bool
     */
    private function affects_collections( array $generations ) {
        foreach ( $generations as $generation ) {
            if ( is_string( $generation ) && preg_match( '/:(?:collection|taxonomy|settings|template|site)(?::|$)/', $generation ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool   $success Result state.
     * @param string $code Stable code.
     * @return array
     */
    private function result( $success, $code ) {
        return [ 'success' => (bool) $success, 'code' => (string) $code ];
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

    private function is_internal_warm_request() {
        $value = isset( $_SERVER['HTTP_X_DIRECTORIST_CACHE_WARM'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DIRECTORIST_CACHE_WARM'] ) ) : '';

        return '1' === $value;
    }
}
