<?php

namespace Directorist\Cache;

/**
 * Small normalized settings schema for page-cache operations.
 */
final class Performance_Settings {
    const OPTION_NAME = 'directorist_page_cache_performance';

    /** @return array */
    public function defaults() {
        return [
            'enabled'       => true,
            'sample_rate'   => 0,
            'history_limit' => 20,
            'diagnostics_until'      => 0,
            'cache_duration'         => 'automatic',
            'cache_filtered_results' => true,
        ];
    }

    /** @return array */
    public function get() {
        $stored = get_option( self::OPTION_NAME, [] );

        return $this->normalize( is_array( $stored ) ? $stored : [] );
    }

    /**
     * @param array $values Partial settings.
     * @return array
     */
    public function update( array $values ) {
        $settings = $this->normalize( array_merge( $this->get(), $values ) );
        update_option( self::OPTION_NAME, $settings );

        return $settings;
    }

    /** @return bool */
    public function is_enabled() {
        return $this->get()['enabled'];
    }

    /** @return int */
    public function get_cache_ttl() {
        $duration = $this->get()['cache_duration'];

        return 'automatic' === $duration ? 6 * HOUR_IN_SECONDS : (int) $duration;
    }

    /**
     * Return normalized built-in soft-refresh and hard-expiry boundaries.
     *
     * External providers continue owning their expiry policy. These values are
     * serialized only for Directorist's built-in early cache runtime.
     *
     * @return array
     */
    public function get_cache_policy() {
        $duration = $this->get()['cache_duration'];

        if ( 'automatic' !== $duration ) {
            $soft_ttl = max( HOUR_IN_SECONDS, (int) $duration );

            return [
                'mode'    => 'fixed',
                'default' => [
                    'soft_ttl' => $soft_ttl,
                    'hard_ttl' => $soft_ttl + HOUR_IN_SECONDS,
                    'jitter'   => 0,
                ],
                'routes'  => [],
            ];
        }

        $collection = [
            'soft_ttl' => 6 * HOUR_IN_SECONDS,
            'hard_ttl' => DAY_IN_SECONDS,
            'jitter'   => 300,
        ];
        $search     = [
            'soft_ttl' => 2 * HOUR_IN_SECONDS,
            'hard_ttl' => 6 * HOUR_IN_SECONDS,
            'jitter'   => 180,
        ];

        return [
            'mode'    => 'automatic',
            'default' => $collection,
            'routes'  => [
                'listing'     => [
                    'soft_ttl' => DAY_IN_SECONDS,
                    'hard_ttl' => 7 * DAY_IN_SECONDS,
                    'jitter'   => 600,
                ],
                'listings'    => $collection,
                'category'    => $collection,
                'location'    => $collection,
                'tag'         => $collection,
                'author'      => $collection,
                'categories'  => $collection,
                'locations'   => $collection,
                'embedded'    => $collection,
                'search'      => $search,
                'search-form' => $search,
            ],
        ];
    }

    /**
     * @param array $values Raw values.
     * @return array
     */
    private function normalize( array $values ) {
        $defaults = $this->defaults();
        $enabled  = isset( $values['enabled'] ) && in_array( $values['enabled'], [ true, false, 0, 1, '0', '1' ], true )
            ? (bool) $values['enabled']
            : $defaults['enabled'];
        $rate     = isset( $values['sample_rate'] ) && is_numeric( $values['sample_rate'] ) ? max( 0, min( 100, (int) $values['sample_rate'] ) ) : $defaults['sample_rate'];
        $allowed  = [ 0, 1, 5, 10, 25, 100 ];
        $selected = 0;

        foreach ( $allowed as $candidate ) {
            if ( $candidate > $rate ) {
                break;
            }

            $selected = $candidate;
        }

        $history = isset( $values['history_limit'] ) && is_numeric( $values['history_limit'] )
            ? max( 5, min( 50, (int) $values['history_limit'] ) )
            : $defaults['history_limit'];
        $diagnostics_until = isset( $values['diagnostics_until'] ) && is_numeric( $values['diagnostics_until'] )
            ? max( 0, (int) $values['diagnostics_until'] )
            : $defaults['diagnostics_until'];
        $durations         = [ 'automatic', '3600', '21600', '43200', '86400' ];
        $duration          = isset( $values['cache_duration'] ) && in_array( (string) $values['cache_duration'], $durations, true )
            ? (string) $values['cache_duration']
            : $defaults['cache_duration'];
        $filtered_results  = isset( $values['cache_filtered_results'] ) && in_array( $values['cache_filtered_results'], [ true, false, 0, 1, '0', '1' ], true )
            ? (bool) $values['cache_filtered_results']
            : $defaults['cache_filtered_results'];

        return [
            'enabled'       => $enabled,
            'sample_rate'   => $selected,
            'history_limit' => $history,
            'diagnostics_until'      => $diagnostics_until,
            'cache_duration'         => $duration,
            'cache_filtered_results' => $filtered_results,
        ];
    }
}
