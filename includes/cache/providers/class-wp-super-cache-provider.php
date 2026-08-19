<?php

namespace Directorist\Cache;

/**
 * Guarded WP Super Cache adapter.
 */
final class WP_Super_Cache_Provider extends Abstract_Cache_Provider {
    /**
     * @param array|null $runtime Test/runtime operation overrides.
     */
    public function __construct( array $runtime = null ) {
        if ( null === $runtime ) {
            $cache_enabled = ! empty( $GLOBALS['cache_enabled'] ) || ! empty( $GLOBALS['super_cache_enabled'] );
            $runtime       = [
                'delete_url' => function_exists( 'wpsc_delete_url_cache' ) ? static function ( $url ) {
                    return wpsc_delete_url_cache( $url );
                } : null,
                'purge_site' => function_exists( 'wp_cache_clear_cache' ) ? static function ( $site_id ) {
                    wp_cache_clear_cache( is_multisite() ? $site_id : 0 );
                } : null,
                'version'    => defined( 'WPSC_VERSION_ID' ) ? WPSC_VERSION_ID : '',
                'enabled'    => $cache_enabled,
            ];
        }

        $operations = [
            'delete_url' => isset( $runtime['delete_url'] ) ? $runtime['delete_url'] : null,
            'purge_site' => isset( $runtime['purge_site'] ) ? $runtime['purge_site'] : null,
        ];

        $available = empty( $runtime['disabled'] ) && ( ! isset( $runtime['enabled'] ) || $runtime['enabled'] );

        $this->configure( 'wp-super-cache', isset( $runtime['version'] ) ? $runtime['version'] : '', $operations, $available );
    }

    /**
     * WP Super Cache rejects query URLs and its exact API cannot delete root.
     *
     * @param array $plan Normalized plan.
     * @return bool
     */
    protected function must_purge_site( array $plan ) {
        $home = untrailingslashit( home_url( '/' ) );

        foreach ( $plan['urls'] as $url ) {
            if ( false !== strpos( $url, '?' ) || untrailingslashit( $url ) === $home ) {
                return true;
            }
        }

        return false;
    }
}
