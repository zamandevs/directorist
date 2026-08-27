<?php

namespace Directorist\Cache\Built_In;

/**
 * Resolves bounded route-aware soft-refresh and hard-expiry lifetimes.
 */
final class Refresh_Policy {
    const MIN_TTL  = 1;
    const MAX_SOFT = 2592000;
    const MAX_HARD = 7776000;

    /**
     * @param array  $config Early runtime policy.
     * @param array  $descriptor Core response descriptor.
     * @param string $request_hash Canonical request hash used for stable jitter.
     * @return array{soft_ttl:int,hard_ttl:int,stale_ttl:int,jitter:int}
     */
    public function resolve( array $config, array $descriptor, $request_hash ) {
        $fallback = [
            'soft_ttl' => isset( $config['ttl'] ) ? (int) $config['ttl'] : HOUR_IN_SECONDS,
            'hard_ttl' => ( isset( $config['ttl'] ) ? (int) $config['ttl'] : HOUR_IN_SECONDS ) + ( isset( $config['stale_ttl'] ) ? (int) $config['stale_ttl'] : 30 ),
            'jitter'   => 0,
        ];
        $policy   = isset( $config['refresh_policy'] ) && is_array( $config['refresh_policy'] ) ? $config['refresh_policy'] : [];
        $selected = isset( $policy['default'] ) && is_array( $policy['default'] ) ? $policy['default'] : $fallback;
        $route    = isset( $descriptor['route_type'] ) ? preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $descriptor['route_type'] ) ) : '';

        if ( '' !== $route && isset( $policy['routes'][ $route ] ) && is_array( $policy['routes'][ $route ] ) ) {
            $selected = $policy['routes'][ $route ];
        }

        $selected = $this->normalize( $selected, $fallback );

        if ( function_exists( 'apply_filters' ) ) {
            /**
             * Filters one built-in cache entry's refresh and hard-expiry policy.
             *
             * Extensions with time-sensitive public output can narrow these
             * values without changing the global policy for stable routes.
             *
             * @param array  $selected Policy with soft_ttl, hard_ttl, and jitter.
             * @param array  $descriptor Core route descriptor.
             * @param string $request_hash Canonical request hash.
             */
            $filtered = apply_filters( 'directorist_page_cache_refresh_policy', $selected, $descriptor, (string) $request_hash );

            if ( is_array( $filtered ) ) {
                $selected = $this->normalize( $filtered, $selected );
            }
        }

        $soft_ttl = $this->jitter( $selected['soft_ttl'], $selected['jitter'], $request_hash );
        $hard_ttl = max( $soft_ttl, $selected['hard_ttl'] );

        return [
            'soft_ttl'  => $soft_ttl,
            'hard_ttl'  => $hard_ttl,
            'stale_ttl' => $hard_ttl - $soft_ttl,
            'jitter'    => $selected['jitter'],
        ];
    }

    private function normalize( array $policy, array $fallback ) {
        $fallback_soft = isset( $fallback['soft_ttl'] ) ? (int) $fallback['soft_ttl'] : HOUR_IN_SECONDS;
        $fallback_hard = isset( $fallback['hard_ttl'] ) ? (int) $fallback['hard_ttl'] : $fallback_soft + 30;
        $soft_ttl      = isset( $policy['soft_ttl'] ) && is_numeric( $policy['soft_ttl'] ) ? (int) $policy['soft_ttl'] : $fallback_soft;
        $hard_ttl      = isset( $policy['hard_ttl'] ) && is_numeric( $policy['hard_ttl'] ) ? (int) $policy['hard_ttl'] : $fallback_hard;
        $jitter        = isset( $policy['jitter'] ) && is_numeric( $policy['jitter'] ) ? (int) $policy['jitter'] : 0;
        $soft_ttl      = min( self::MAX_SOFT, max( self::MIN_TTL, $soft_ttl ) );
        $hard_ttl      = min( self::MAX_HARD, max( $soft_ttl, $hard_ttl ) );
        $jitter        = min( (int) floor( $soft_ttl / 4 ), max( 0, $jitter ) );

        return compact( 'soft_ttl', 'hard_ttl', 'jitter' );
    }

    private function jitter( $soft_ttl, $jitter, $request_hash ) {
        $soft_ttl = (int) $soft_ttl;
        $jitter   = (int) $jitter;

        if ( 1 > $jitter || ! is_string( $request_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $request_hash ) ) {
            return $soft_ttl;
        }

        $range  = ( 2 * $jitter ) + 1;
        $offset = ( hexdec( substr( $request_hash, 0, 8 ) ) % $range ) - $jitter;

        return max( self::MIN_TTL, $soft_ttl + $offset );
    }
}
