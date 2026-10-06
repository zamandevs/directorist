<?php
/**
 * Guards derived listing data across Directorist deployment changes.
 *
 * @since 8.9.0
 */

namespace Directorist\database;

defined( 'ABSPATH' ) || exit;

class Listing_Index_Lifecycle {
    const DEPLOYMENT_TOKEN_OPTION = 'directorist_listing_index_deployment_token';

    const TRUSTED_TOKEN_OPTION = 'directorist_listing_index_trusted_deployment';

    private static $hooks_registered = false;

    public static function register_hooks() {
        if ( self::$hooks_registered ) {
            return;
        }

        self::$hooks_registered = true;

        add_action( 'directorist_updated', [ __CLASS__, 'handle_directorist_update' ], 1 );
        add_action( 'deactivate_plugin', [ __CLASS__, 'handle_deactivation' ], 1, 2 );
        add_action( 'activated_plugin', [ __CLASS__, 'handle_activation' ], 1, 2 );
        add_filter( 'upgrader_pre_install', [ __CLASS__, 'handle_upgrader_pre_install' ], 10, 2 );
    }

    public static function deployment_token() {
        return (string) get_site_option( self::DEPLOYMENT_TOKEN_OPTION, '' );
    }

    public static function is_current_deployment_trusted() {
        return self::deployment_token() === (string) get_option( self::TRUSTED_TOKEN_OPTION, '' );
    }

    public static function trust_current_deployment() {
        $token = self::deployment_token();

        if ( '' === $token ) {
            delete_option( self::TRUSTED_TOKEN_OPTION );
            return;
        }

        update_option( self::TRUSTED_TOKEN_OPTION, $token, false );
    }

    public static function handle_directorist_update() {
        if ( self::is_current_deployment_trusted() ) {
            self::rotate_deployment_token();
        }

        self::invalidate_current_site( true );
    }

    public static function handle_deactivation( $plugin, $network_deactivating ) {
        if ( ! self::is_directorist_plugin( $plugin ) ) {
            return;
        }

        if ( $network_deactivating ) {
            self::rotate_deployment_token();
        }

        self::invalidate_current_site();
    }

    public static function handle_activation( $plugin, $network_activating ) {
        if ( ! self::is_directorist_plugin( $plugin ) ) {
            return;
        }

        if ( $network_activating ) {
            self::rotate_deployment_token();
        }

        self::invalidate_current_site( true );
    }

    public static function handle_upgrader_pre_install( $response, $hook_extra ) {
        if ( self::is_directorist_upgrade( $hook_extra ) ) {
            self::rotate_deployment_token();
            self::invalidate_current_site();
        }

        return $response;
    }

    public static function invalidate_current_site( $schedule = false ) {
        Listing_Index_Schema::invalidate_data();

        if ( $schedule ) {
            Listing_Index_Maintenance::schedule_if_needed();
        }
    }

    private static function rotate_deployment_token() {
        update_site_option( self::DEPLOYMENT_TOKEN_OPTION, wp_generate_uuid4() );
    }

    private static function is_directorist_upgrade( $hook_extra ) {
        if ( ! is_array( $hook_extra ) || ( isset( $hook_extra['type'] ) && 'plugin' !== $hook_extra['type'] ) ) {
            return false;
        }

        $plugins = [];

        if ( ! empty( $hook_extra['plugin'] ) ) {
            $plugins[] = $hook_extra['plugin'];
        }

        if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
            $plugins = array_merge( $plugins, $hook_extra['plugins'] );
        }

        foreach ( $plugins as $plugin ) {
            if ( self::is_directorist_plugin( $plugin ) ) {
                return true;
            }
        }

        return false;
    }

    private static function is_directorist_plugin( $plugin ) {
        return plugin_basename( ATBDP_DIR . 'directorist-base.php' ) === plugin_basename( $plugin );
    }
}
