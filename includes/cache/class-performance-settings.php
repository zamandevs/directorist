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
            'history_limit' => 50,
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
            ? max( 10, min( 100, (int) $values['history_limit'] ) )
            : $defaults['history_limit'];

        return [
            'enabled'       => $enabled,
            'sample_rate'   => $selected,
            'history_limit' => $history,
        ];
    }
}
