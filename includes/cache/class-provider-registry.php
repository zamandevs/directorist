<?php

namespace Directorist\Cache;

/**
 * Discovers and selects one non-conflicting page-cache provider.
 */
final class Provider_Registry {
    /** @var Dropin_Owner_Detector */
    private $dropin_detector;

    /** @var array<int,array{provider:Cache_Provider,priority:int}> */
    private $registrations = [];

    /** @var bool */
    private $builtins_registered = false;

    /** @var Provider_Selection|null */
    private $selection;

    /**
     * @param Dropin_Owner_Detector|null $dropin_detector Drop-in detector.
     */
    public function __construct( Dropin_Owner_Detector $dropin_detector = null ) {
        $this->dropin_detector = $dropin_detector ?: new Dropin_Owner_Detector();
    }

    /**
     * @param Cache_Provider $provider Provider implementation.
     * @param int            $priority Duplicate-ID priority.
     * @return bool
     */
    public function register( Cache_Provider $provider, $priority = 10 ) {
        try {
            $id = strtolower( trim( (string) $provider->get_id() ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }

        if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id ) ) {
            return false;
        }

        $this->registrations[] = [
            'provider' => $provider,
            'priority' => (int) $priority,
        ];

        return true;
    }

    /**
     * @param bool $discover_builtins Whether to inspect built-in integrations.
     * @return Provider_Selection
     */
    public function select( $discover_builtins = true ) {
        if ( $discover_builtins ) {
            $this->register_builtins();
        }

        $registrations = apply_filters( 'directorist_page_cache_providers', $this->registrations, $this );
        $candidates    = $this->available_candidates( is_array( $registrations ) ? $registrations : [] );
        $candidate_ids = array_keys( $candidates );
        $dropin_owner  = $this->dropin_detector->detect();

        if ( empty( $candidates ) ) {
            return $this->remember( new Provider_Selection( null, 'no_available_provider', [], $dropin_owner ) );
        }

        if ( 'unknown' === $dropin_owner ) {
            return $this->remember( new Provider_Selection( null, 'unknown_dropin', $candidate_ids, $dropin_owner ) );
        }

        if ( count( $candidates ) > 1 ) {
            return $this->remember( new Provider_Selection( null, 'multiple_providers', $candidate_ids, $dropin_owner ) );
        }

        $id       = $candidate_ids[0];
        $provider = $candidates[ $id ]['provider'];

        if ( 'none' !== $dropin_owner && $id !== $dropin_owner ) {
            return $this->remember( new Provider_Selection( null, 'dropin_owner_mismatch', $candidate_ids, $dropin_owner ) );
        }

        return $this->remember( new Provider_Selection( $provider, 'selected', $candidate_ids, $dropin_owner ) );
    }

    /** @return Provider_Selection|null */
    public function get_selection() {
        return $this->selection;
    }

    /** @return void */
    private function register_builtins() {
        if ( $this->builtins_registered ) {
            return;
        }

        $this->builtins_registered = true;

        if ( function_exists( 'wpsc_delete_url_cache' ) || function_exists( 'wp_cache_clear_cache' ) ) {
            $this->register( new WP_Super_Cache_Provider(), 80 );
        }

        if ( class_exists( 'Cache_Enabler', false ) ) {
            $this->register( new Cache_Enabler_Provider(), 70 );
        }

        if ( function_exists( 'wpfc_clear_all_cache' ) ) {
            $this->register( new WP_Fastest_Cache_Provider(), 60 );
        }

        if ( function_exists( 'rocket_clean_files' ) || function_exists( 'rocket_clean_domain' ) ) {
            $this->register( new WP_Rocket_Provider(), 90 );
        }

        if ( defined( 'LSCWP_V' ) ) {
            $this->register( new LiteSpeed_Cache_Provider(), 100 );
        }
    }

    /**
     * @param array $registrations Provider registrations.
     * @return array<string,array{provider:Cache_Provider,priority:int}>
     */
    private function available_candidates( array $registrations ) {
        $candidates = [];

        foreach ( $registrations as $registration ) {
            if ( $registration instanceof Cache_Provider ) {
                $registration = [ 'provider' => $registration, 'priority' => 10 ];
            }

            if ( ! is_array( $registration ) || empty( $registration['provider'] ) || ! $registration['provider'] instanceof Cache_Provider ) {
                continue;
            }

            $provider = $registration['provider'];

            try {
                $id        = strtolower( trim( (string) $provider->get_id() ) );
                $available = $provider->is_available();
                $safe      = $provider->supports( Provider_Capabilities::PURGE_SITE )
                    || ( $provider->supports( Provider_Capabilities::PURGE_DEPENDENCIES ) && $provider->supports( Provider_Capabilities::PURGE_GENERATIONS ) );
            } catch ( \Throwable $exception ) {
                unset( $exception );

                continue;
            }

            if ( ! $available || ! $safe || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id ) ) {
                continue;
            }

            $priority = isset( $registration['priority'] ) ? (int) $registration['priority'] : 10;

            if ( ! isset( $candidates[ $id ] ) || $priority > $candidates[ $id ]['priority'] ) {
                $candidates[ $id ] = [
                    'provider' => $provider,
                    'priority' => $priority,
                ];
            }
        }

        $candidate_ids = array_keys( $candidates );
        usort(
            $candidate_ids,
            static function ( $left_id, $right_id ) use ( $candidates ) {
                $priority_compare = $candidates[ $right_id ]['priority'] <=> $candidates[ $left_id ]['priority'];

                return 0 !== $priority_compare ? $priority_compare : strcmp( $left_id, $right_id );
            }
        );

        $ordered = [];

        foreach ( $candidate_ids as $candidate_id ) {
            $ordered[ $candidate_id ] = $candidates[ $candidate_id ];
        }

        return $ordered;
    }

    /**
     * @param Provider_Selection $selection Selection result.
     * @return Provider_Selection
     */
    private function remember( Provider_Selection $selection ) {
        $this->selection = $selection;

        return $selection;
    }
}
