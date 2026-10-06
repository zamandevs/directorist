<?php

namespace Directorist\Cache;

/** Deferred, site-scoped invalidation after relevant plugin changes. */
final class Plugin_Lifecycle {
    const PENDING_OPTION = 'directorist_page_cache_plugin_changes';
    const LOCK_OPTION = 'directorist_page_cache_plugin_changes_lock';
    const MAX_EVENTS = 100;
    const MAX_ATTEMPTS = 3;

    private $warmer;
    private $after_invalidation;

    public function __construct( $warmer = null, $after_invalidation = null ) {
        $this->warmer = $warmer;
        $this->after_invalidation = $after_invalidation;
    }

    /** Preserve only plugin events; unknown payloads never imply a full purge. */
    public function merge( array $pending, $subject, $context, $hook = '' ) {
        $plugins = is_string( $subject ) ? [ $subject ] : [];
        $network = is_bool( $context ) && $context;
        $actions = [ 'activated_plugin' => 'activate', 'deactivated_plugin' => 'deactivate', 'deleted_plugin' => 'delete' ];
        $action = isset( $actions[ $hook ] ) ? $actions[ $hook ] : 'plugin_change';

        if ( 'deleted_plugin' === $hook ) {
            if ( ! $context ) { return $pending; }
            $network = is_multisite();
        }

        if ( is_array( $context ) ) {
            if ( ! isset( $context['type'], $context['action'] ) || 'plugin' !== $context['type'] || ! in_array( $context['action'], [ 'update', 'install' ], true ) ) {
                return $pending;
            }
            $plugins = isset( $context['plugins'] ) && is_array( $context['plugins'] ) ? $context['plugins'] : [];
            if ( isset( $context['plugin'] ) && is_string( $context['plugin'] ) ) { $plugins[] = $context['plugin']; }
            $action = $context['action'];
            $network = isset( $context['network_wide'] ) ? (bool) $context['network_wide'] : is_multisite(); // Plugin files are shared across blogs on update.
        }

        $events = isset( $pending['events'] ) && is_array( $pending['events'] ) ? $pending['events'] : [];
        $changed = false;
        foreach ( $plugins as $plugin ) {
            if ( ! is_string( $plugin ) || strlen( $plugin ) > 255 || ! preg_match( '#^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_.-]+)*\.php$#D', $plugin ) || in_array( '..', explode( '/', $plugin ), true ) ) {
                continue;
            }
            $plugin = str_replace( '\\', '/', $plugin );
            $slug = strtolower( explode( '/', $plugin, 2 )[0] );
            $relevant = ( defined( 'ATBDP_DIR' ) && plugin_basename( ATBDP_DIR . 'directorist-base.php' ) === $plugin ) || 'directorist' === $slug || 0 === strpos( $slug, 'directorist-' ) || in_array( $slug, [ 'wp-super-cache', 'cache-enabler', 'wp-fastest-cache', 'wp-rocket', 'litespeed-cache' ], true );
            if ( ! apply_filters( 'directorist_page_cache_plugin_lifecycle_relevant', $relevant, $plugin, $action ) ) { continue; }
            $key = $plugin . ':' . $action;
            if ( ! isset( $events[ $key ] ) ) {
                $events[ $key ] = [ 'plugin' => $plugin, 'action' => $action ];
                $changed = true;
            }
        }
        if ( ! $changed && ( ! $network || ! empty( $pending['network_wide'] ) || empty( $events ) ) ) { return $pending; }
        if ( empty( $events ) ) { return $pending; }

        return [
            'events' => array_slice( $events, 0, self::MAX_EVENTS, true ),
            'site_id' => get_current_blog_id(),
            'network_wide' => $network || ! empty( $pending['network_wide'] ),
            'revision' => wp_generate_uuid4(),
            'attempts' => 0,
            'retry_at' => 0,
            'created_at' => isset( $pending['created_at'] ) ? $pending['created_at'] : time(),
        ];
    }

    public function refresh( array $pending, Cache_Provider $provider ) {
        if ( empty( $pending['events'] ) ) { return [ 'success' => true, 'code' => 'no_plugin_changes' ]; }
        if ( empty( $pending['site_id'] ) || (int) $pending['site_id'] !== get_current_blog_id() ) {
            return [ 'success' => false, 'code' => 'plugin_site_mismatch' ];
        }
        $plan = [
            'site_id' => get_current_blog_id(), 'urls' => [], 'dependencies' => [],
            'generations' => [ 'directorist:' . get_current_blog_id() . ':site' ],
            'conservative' => false, 'reason' => 'plugin_lifecycle',
        ];
        try {
            if ( ! $provider->is_available() ) { return [ 'success' => false, 'code' => 'provider_unavailable' ]; }
            if ( 'directorist-cache' === $provider->get_id() && ! empty( $pending['network_wide'] ) ) {
                $plan['generations'][] = 'directorist:0:lifecycle';
            }
            $invalidation = $provider->invalidate( $plan );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            return [ 'success' => false, 'code' => 'plugin_invalidation_exception' ];
        }
        if ( ! is_array( $invalidation ) || empty( $invalidation['success'] ) ) {
            return [ 'success' => false, 'code' => 'plugin_invalidation_failed', 'invalidation' => is_array( $invalidation ) ? $invalidation : [] ];
        }
        $notified = true;
        if ( is_callable( $this->after_invalidation ) ) {
            try {
                $notified = false !== call_user_func( $this->after_invalidation, $invalidation, $plan );
            } catch ( \Throwable $exception ) {
                unset( $exception );
                $notified = false;
            }
        }
        try {
            $preload = is_callable( $this->warmer )
                ? call_user_func( $this->warmer, $invalidation, $plan )
                : ( new Automatic_Warmer( $provider ) )->after_invalidation( $invalidation, array_merge( $plan, [ 'conservative' => true ] ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $preload = [ 'success' => false, 'code' => 'plugin_preload_exception' ];
        }
        return [ 'success' => true, 'code' => 'plugin_output_invalidated', 'invalidation' => $invalidation, 'plan' => $plan, 'notification_success' => $notified, 'preload' => is_array( $preload ) ? $preload : [ 'success' => false, 'code' => 'invalid_preload_result' ] ];
    }

    /** Compare-and-swap prevents a worker from deleting newly queued changes. */
    public function replace( array $expected, array $next ) {
        global $wpdb;
        if ( $expected === $next ) { return true; }
        $stored = get_option( self::PENDING_OPTION, false );
        if ( false === $stored ) { return empty( $expected ) && add_option( self::PENDING_OPTION, $next, '', false ); }
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s",
            maybe_serialize( $next ), self::PENDING_OPTION, maybe_serialize( $expected )
        ) );
        wp_cache_delete( self::PENDING_OPTION, 'options' );
        return 1 === $updated;
    }

    public function enqueue( $subject, $context, $hook = '' ) {
        for ( $attempt = 0; $attempt < 3; ++$attempt ) {
            $pending = get_option( self::PENDING_OPTION, [] );
            $pending = is_array( $pending ) ? $pending : [];
            $next = $this->merge( $pending, $subject, $context, $hook );
            if ( $this->replace( $pending, $next ) ) { return $next; }
        }
        return false;
    }

    public function enqueue_events( array $events, $network = false ) {
        for ( $attempt = 0; $attempt < 3; ++$attempt ) {
            $pending = get_option( self::PENDING_OPTION, [] );
            $pending = is_array( $pending ) ? $pending : [];
            $next = $pending;
            foreach ( array_slice( $events, 0, self::MAX_EVENTS ) as $event ) {
                if ( ! is_array( $event ) || empty( $event['plugin'] ) ) { continue; }
                $action = isset( $event['action'] ) ? $event['action'] : '';
                $hooks = [ 'activate' => 'activated_plugin', 'deactivate' => 'deactivated_plugin', 'delete' => 'deleted_plugin' ];
                $next = isset( $hooks[ $action ] )
                    ? $this->merge( $next, $event['plugin'], (bool) $network, $hooks[ $action ] )
                    : $this->merge( $next, null, [ 'type' => 'plugin', 'action' => 'install' === $action ? 'install' : 'update', 'plugin' => $event['plugin'], 'network_wide' => (bool) $network ], 'upgrader_process_complete' );
            }
            if ( $this->replace( $pending, $next ) ) { return $next; }
        }
        return false;
    }

    public function acquire() {
        global $wpdb;
        $previous = get_option( self::LOCK_OPTION, [] );
        if ( is_array( $previous ) && isset( $previous['expires'] ) && $previous['expires'] < time() ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s", self::LOCK_OPTION, maybe_serialize( $previous ) ) );
            wp_cache_delete( self::LOCK_OPTION, 'options' );
        }
        $lock = [ 'token' => wp_generate_uuid4(), 'expires' => time() + 300 ];
        return add_option( self::LOCK_OPTION, $lock, '', false ) ? $lock : false;
    }

    public function release( array $lock ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s", self::LOCK_OPTION, maybe_serialize( $lock ) ) );
        wp_cache_delete( self::LOCK_OPTION, 'options' );
    }
}
