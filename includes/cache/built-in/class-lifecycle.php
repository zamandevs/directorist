<?php

namespace Directorist\Cache\Built_In;

/**
 * Reconciles external cache providers with Directorist's owned runtime.
 */
final class Lifecycle {
    const STATE_OPTION = 'directorist_page_cache_lifecycle_state';

    /** @var Runtime_Manager */
    private $runtime;

    /** @var array */
    private $options;

    /**
     * @param Runtime_Manager $runtime Owned runtime manager.
     * @param array           $options Testable lifecycle boundaries.
     */
    public function __construct( Runtime_Manager $runtime, array $options = [] ) {
        $this->runtime = $runtime;
        $this->options = array_merge(
            [
                'external_probe'   => static function () {
                    return [ 'code' => 'none', 'provider' => '', 'candidates' => [] ];
                },
                'owner_resolver'   => function () {
                    $status = $this->runtime->status();

                    if ( 'owned' === $status['dropin'] ) {
                        return 'directorist-cache';
                    }

                    return 'missing' === $status['dropin'] ? 'none' : 'unknown';
                },
                'enabled_resolver' => static function () {
                    return function_exists( 'directorist_page_cache_is_enabled' ) && directorist_page_cache_is_enabled();
                },
                'generation_bump'  => static function () {
                    return [ 'success' => true, 'code' => 'generation_not_configured' ];
                },
                'cookie_policy'    => static function () {
                    return function_exists( 'directorist_page_cache_cookie_policy' ) ? directorist_page_cache_cookie_policy() : Cookie_Policy::defaults();
                },
                'runtime_policy'   => static function () {
                    if ( ! function_exists( 'directorist_page_cache_performance_settings' ) ) {
                        return [ 'ttl' => HOUR_IN_SECONDS, 'cache_filtered_results' => true ];
                    }

                    $settings = directorist_page_cache_performance_settings();
                    $values   = $settings->get();

                    return [
                        'ttl'                    => $settings->get_cache_ttl(),
                        'cache_filtered_results' => ! empty( $values['cache_filtered_results'] ),
                        'refresh_policy'         => $settings->get_cache_policy(),
                        'refresh_endpoint'       => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
                        'refresh_token'          => function_exists( 'directorist_page_cache_refresh_token' ) ? directorist_page_cache_refresh_token() : '',
                    ];
                },
                'state_writer'     => static function ( array $state ) {
                    return function_exists( 'update_site_option' ) && update_site_option( self::STATE_OPTION, $state );
                },
                'is_multisite'     => false,
                'network_wide'     => true,
            ],
            $options
        );
    }

    /**
     * Reconcile one bounded lifecycle transition.
     *
     * @param string $reason Transition reason.
     * @param array  $context Transition context overrides.
     * @return array
     */
    public function reconcile( $reason = 'health', array $context = [] ) {
        $reason   = $this->key( $reason, 'health' );
        $enabled  = $this->resolve_bool( 'enabled_resolver', false );
        $owner    = $this->resolve_owner();
        $external = $this->probe_external();

        if ( ! $enabled ) {
            $retired = $this->retire_if_owned( $owner );

            if ( empty( $retired['success'] ) ) {
                return $this->remember( $this->state( false, 'unavailable', $retired['code'], '', $reason ) );
            }

            return $this->remember( $this->state( true, 'disabled', 'disabled', '', $reason ) );
        }

        if ( 'multiple_providers' === $external['code'] ) {
            $retired = $this->retire_if_owned( $owner );
            $code    = empty( $retired['success'] ) ? $retired['code'] : 'multiple_providers';

            return $this->remember( $this->state( false, 'blocked', $code, '', $reason, $external['candidates'] ) );
        }

        if ( 'selected' === $external['code'] && '' !== $external['provider'] ) {
            if ( ! in_array( $owner, [ 'none', 'directorist-cache', $external['provider'] ], true ) ) {
                return $this->remember( $this->state( false, 'blocked', 'foreign_dropin', '', $reason, $external['candidates'] ) );
            }

            $retired = $this->retire_if_owned( $owner );

            if ( empty( $retired['success'] ) ) {
                return $this->remember( $this->state( false, 'unavailable', $retired['code'], '', $reason ) );
            }

            return $this->remember( $this->state( true, 'external', 'external_selected', $external['provider'], $reason ) );
        }

        if ( ! in_array( $owner, [ 'none', 'directorist-cache' ], true ) ) {
            $code = 'unknown' === $owner ? 'unknown_dropin' : 'foreign_dropin';

            return $this->remember( $this->state( false, 'blocked', $code, '', $reason ) );
        }

        $status = $this->runtime->status();

        if ( 'owned' === $status['dropin'] && 'owned' === $status['config'] ) {
            $synchronized = $this->sync_early_policy();

            if ( empty( $synchronized['success'] ) ) {
                $this->runtime->deactivate();

                return $this->remember( $this->state( false, 'unavailable', $this->result_code( $synchronized, 'policy_sync_failed' ), '', $reason ) );
            }

            if ( ! empty( $synchronized['changed'] ) ) {
                $generation = $this->bump_generation();

                if ( empty( $generation['success'] ) ) {
                    $this->runtime->deactivate();

                    return $this->remember( $this->state( false, 'unavailable', 'lifecycle_generation_failed', '', $reason ) );
                }
            }

            $code = ! empty( $synchronized['changed'] ) ? 'runtime_policy_updated' : 'runtime_current';

            return $this->remember( $this->state( true, 'built_in', $code, 'directorist-cache', $reason ) );
        }

        $activated = $this->runtime->activate(
            array_key_exists( 'is_multisite', $context ) ? (bool) $context['is_multisite'] : $this->resolve_bool( 'is_multisite', false ),
            array_key_exists( 'network_wide', $context ) ? (bool) $context['network_wide'] : $this->resolve_bool( 'network_wide', true )
        );

        if ( empty( $activated['success'] ) ) {
            return $this->remember( $this->state( false, 'unavailable', $this->result_code( $activated, 'activation_failed' ), '', $reason ) );
        }

        $synchronized = $this->sync_early_policy();

        if ( empty( $synchronized['success'] ) ) {
            $this->runtime->deactivate();

            return $this->remember( $this->state( false, 'unavailable', $this->result_code( $synchronized, 'policy_sync_failed' ), '', $reason ) );
        }

        $generation = $this->bump_generation();

        if ( empty( $generation['success'] ) ) {
            $this->runtime->deactivate();

            return $this->remember( $this->state( false, 'unavailable', 'lifecycle_generation_failed', '', $reason ) );
        }

        return $this->remember( $this->state( true, 'built_in', 'runtime_activated', 'directorist-cache', $reason ) );
    }

    /**
     * Release Directorist ownership before a supported external activation hook.
     *
     * @param string $plugin Plugin basename.
     * @return array
     */
    public function prepare_external_activation( $plugin ) {
        $plugin = strtolower( trim( str_replace( '\\', '/', (string) $plugin ) ) );
        $slug   = false === strpos( $plugin, '/' ) ? $plugin : explode( '/', $plugin, 2 )[0];

        if ( ! in_array( $slug, [ 'wp-super-cache', 'cache-enabler', 'wp-fastest-cache', 'wp-rocket', 'litespeed-cache' ], true ) ) {
            return [ 'success' => true, 'code' => 'not_external_cache' ];
        }

        $status = $this->runtime->status();

        if ( 'owned' !== $status['dropin'] ) {
            return [ 'success' => true, 'code' => 'runtime_not_owned' ];
        }

        $retired = $this->retire_if_owned( 'directorist-cache' );

        return empty( $retired['success'] )
            ? $retired
            : [ 'success' => true, 'code' => 'external_activation_prepared' ];
    }

    /**
     * Remove only Directorist-owned early runtime during core deactivation.
     *
     * @return array
     */
    public function deactivate_core() {
        $retired = $this->retire_if_owned( $this->resolve_owner() );
        $state   = empty( $retired['success'] )
            ? $this->state( false, 'unavailable', $retired['code'], '', 'core_deactivation' )
            : $this->state( true, 'disabled', 'core_deactivated', '', 'core_deactivation' );

        return $this->remember( $state );
    }

    /** @return bool */
    public function is_runtime_healthy() {
        $status = $this->runtime->status();

        return 'owned' === $status['dropin'] && 'owned' === $status['config'];
    }

    /** @return array */
    private function probe_external() {
        try {
            $result = call_user_func( $this->options['external_probe'] );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $result = [];
        }

        $result = is_array( $result ) ? $result : [];

        return [
            'code'       => $this->key( isset( $result['code'] ) ? $result['code'] : '', 'probe_failed' ),
            'provider'   => $this->provider_key( isset( $result['provider'] ) ? $result['provider'] : '' ),
            'candidates' => isset( $result['candidates'] ) && is_array( $result['candidates'] )
                ? array_values( array_unique( array_filter( array_map( [ $this, 'provider_key' ], $result['candidates'] ) ) ) )
                : [],
        ];
    }

    /** @return string */
    private function resolve_owner() {
        try {
            $owner = call_user_func( $this->options['owner_resolver'] );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $owner = 'unknown';
        }

        $owner = $this->provider_key( $owner );

        return '' === $owner ? 'unknown' : $owner;
    }

    /**
     * @param string $owner Current drop-in owner.
     * @return array
     */
    private function retire_if_owned( $owner ) {
        $status = $this->runtime->status();

        if ( 'directorist-cache' !== $owner && 'owned' !== $status['dropin'] ) {
            return [ 'success' => true, 'code' => 'runtime_not_owned' ];
        }

        $generation = $this->bump_generation();
        $retired    = $this->runtime->deactivate();

        if ( empty( $retired['success'] ) ) {
            return $retired;
        }

        if ( empty( $generation['success'] ) ) {
            return [ 'success' => false, 'code' => 'lifecycle_generation_failed' ];
        }

        return [ 'success' => true, 'code' => 'runtime_retired' ];
    }

    /** @return array */
    private function bump_generation() {
        try {
            $result = call_user_func( $this->options['generation_bump'] );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return [ 'success' => false, 'code' => 'generation_exception' ];
        }

        return is_array( $result ) ? $result : [ 'success' => false, 'code' => 'invalid_generation_result' ];
    }

    /** @return array */
    private function sync_cookie_policy() {
        try {
            $policy = call_user_func( $this->options['cookie_policy'] );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return [ 'success' => false, 'code' => 'cookie_policy_exception' ];
        }

        if ( ! is_array( $policy ) ) {
            return [ 'success' => false, 'code' => 'invalid_cookie_policy' ];
        }

        return $this->runtime->sync_cookie_policy( $policy );
    }

    /** @return array */
    private function sync_runtime_policy() {
        try {
            $policy = call_user_func( $this->options['runtime_policy'] );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return [ 'success' => false, 'code' => 'runtime_policy_exception' ];
        }

        if ( ! is_array( $policy ) || ! isset( $policy['ttl'] ) ) {
            return [ 'success' => false, 'code' => 'invalid_runtime_policy' ];
        }

        return $this->runtime->sync_runtime_policy(
            (int) $policy['ttl'],
            ! empty( $policy['cache_filtered_results'] ),
            isset( $policy['refresh_policy'] ) && is_array( $policy['refresh_policy'] ) ? $policy['refresh_policy'] : [],
            isset( $policy['refresh_endpoint'] ) ? (string) $policy['refresh_endpoint'] : '',
            isset( $policy['refresh_token'] ) ? (string) $policy['refresh_token'] : ''
        );
    }

    /** @return array */
    private function sync_early_policy() {
        $cookie = $this->sync_cookie_policy();

        if ( empty( $cookie['success'] ) ) {
            return $cookie;
        }

        $runtime = $this->sync_runtime_policy();

        if ( empty( $runtime['success'] ) ) {
            return $runtime;
        }

        return [
            'success' => true,
            'code'    => ! empty( $cookie['changed'] ) || ! empty( $runtime['changed'] ) ? 'early_policy_updated' : 'early_policy_current',
            'changed' => ! empty( $cookie['changed'] ) || ! empty( $runtime['changed'] ),
        ];
    }

    /**
     * @param string $option Option key.
     * @param bool   $fallback Fallback value.
     * @return bool
     */
    private function resolve_bool( $option, $fallback ) {
        $value = isset( $this->options[ $option ] ) ? $this->options[ $option ] : $fallback;

        try {
            return (bool) ( is_callable( $value ) ? call_user_func( $value ) : $value );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return (bool) $fallback;
        }
    }

    /**
     * @param bool     $success Success state.
     * @param string   $state Lifecycle state.
     * @param string   $code Stable result code.
     * @param string   $provider Selected provider.
     * @param string   $reason Transition reason.
     * @param string[] $candidates Provider candidates.
     * @return array
     */
    private function state( $success, $state, $code, $provider, $reason, array $candidates = [] ) {
        return [
            'success'    => (bool) $success,
            'state'      => $this->key( $state, 'unavailable' ),
            'code'       => $this->key( $code, 'unknown' ),
            'provider'   => $this->provider_key( $provider ),
            'reason'     => $this->key( $reason, 'health' ),
            'candidates' => array_slice( $candidates, 0, 10 ),
            'updated_at' => time(),
        ];
    }

    /**
     * @param array $state State payload.
     * @return array
     */
    private function remember( array $state ) {
        try {
            call_user_func( $this->options['state_writer'], $state );
        } catch ( \Throwable $exception ) {
            unset( $exception );
        }

        return $state;
    }

    /**
     * @param array  $result Operation result.
     * @param string $fallback Fallback code.
     * @return string
     */
    private function result_code( array $result, $fallback ) {
        return $this->key( isset( $result['code'] ) ? $result['code'] : '', $fallback );
    }

    /** @return string */
    public function provider_key( $value ) {
        $value = strtolower( trim( (string) $value ) );

        return preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value ) ? $value : '';
    }

    /**
     * @param mixed  $value Raw key.
     * @param string $fallback Fallback key.
     * @return string
     */
    private function key( $value, $fallback ) {
        $value = strtolower( trim( (string) $value ) );

        return preg_match( '/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $value ) ? $value : $fallback;
    }
}
