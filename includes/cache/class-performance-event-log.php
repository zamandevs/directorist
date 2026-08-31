<?php

namespace Directorist\Cache;

/**
 * Bounded operational history with optional cold-request eligibility samples.
 */
final class Performance_Event_Log {
    const OPTION_NAME = 'directorist_page_cache_events';
    const MAX_AGE     = 2592000;

    /** @var Performance_Settings */
    private $settings;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $random;

    /**
     * @param Performance_Settings|null $settings Settings source.
     * @param callable|null             $clock Timestamp provider.
     * @param callable|null             $random Integer provider from 0 to 99.
     */
    public function __construct( Performance_Settings $settings = null, $clock = null, $random = null ) {
        $this->settings = $settings ?: new Performance_Settings();
        $this->clock    = is_callable( $clock ) ? $clock : 'time';
        $this->random   = is_callable( $random ) ? $random : static function () {
            return wp_rand( 0, 99 );
        };
    }

    /**
     * @param string $level Event level.
     * @param string $code Stable event code.
     * @param array  $context Bounded scalar context.
     * @return bool
     */
    public function record( $level, $code, array $context = [] ) {
        $settings = $this->settings->get();
        $now      = (int) call_user_func( $this->clock );
        $events   = $this->recent( $now );
        $level    = in_array( $level, [ 'info', 'success', 'warning', 'error' ], true ) ? $level : 'info';
        $code     = sanitize_title( (string) $code );

        if ( '' === $code ) {
            return false;
        }

        array_unshift(
            $events,
            [
                'time'    => $now,
                'site_id' => get_current_blog_id(),
                'level'   => $level,
                'code'    => $code,
                'context' => $this->normalize_context( $context ),
            ]
        );

        $events = array_slice( $events, 0, $settings['history_limit'] );

        return update_option( self::OPTION_NAME, $events, false ) || $events === get_option( self::OPTION_NAME, [] );
    }

    /** @return array */
    public function recent( $now = null ) {
        $events = get_option( self::OPTION_NAME, [] );
        $now    = null === $now ? (int) call_user_func( $this->clock ) : max( 0, (int) $now );

        if ( ! is_array( $events ) ) {
            return [];
        }

        return array_values(
            array_filter(
                $events,
                static function ( $event ) use ( $now ) {
                    return is_array( $event )
                        && isset( $event['time'] )
                        && is_numeric( $event['time'] )
                        && (int) $event['time'] >= $now - self::MAX_AGE;
                }
            )
        );
    }

    /** @return bool */
    public function clear() {
        delete_option( self::OPTION_NAME );

        return true;
    }

    /**
     * @param array  $decision Eligibility descriptor.
     * @param string $phase Capture phase.
     * @return bool
     */
    public function maybe_sample( array $decision, $phase ) {
        $settings = $this->settings->get();
        $rate     = $settings['sample_rate'];
        $now      = (int) call_user_func( $this->clock );

        if ( $settings['diagnostics_until'] < $now || 1 > $rate || (int) call_user_func( $this->random ) >= $rate ) {
            return false;
        }

        $eligible = ! empty( $decision['eligible'] );
        $reason   = isset( $decision['reason'] ) ? $decision['reason'] : ( $eligible ? 'eligible' : 'ineligible' );

        return $this->record(
            $eligible ? 'success' : 'warning',
            $reason,
            [
                'phase'      => sanitize_key( (string) $phase ),
                'eligible'   => $eligible ? 'yes' : 'no',
                'route_type' => isset( $decision['route_type'] ) ? sanitize_key( (string) $decision['route_type'] ) : '',
            ]
        );
    }

    /**
     * @param array $context Raw context.
     * @return array
     */
    private function normalize_context( array $context ) {
        $normalized = [];

        foreach ( $context as $key => $value ) {
            if ( 8 <= count( $normalized ) || ! is_scalar( $value ) ) {
                continue;
            }

            $key = sanitize_key( (string) $key );

            if ( '' === $key ) {
                continue;
            }

            $normalized[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 160 );
        }

        return $normalized;
    }
}
