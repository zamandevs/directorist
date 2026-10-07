<?php

namespace Directorist\Cache;

/**
 * Guarded Cache Enabler adapter.
 */
final class Cache_Enabler_Provider extends Abstract_Cache_Provider {
    /**
     * @param array|null $runtime Test/runtime operation overrides.
     */
    public function __construct( array $runtime = null ) {
        if ( null === $runtime ) {
            $enabled = defined( 'WP_CACHE' ) && WP_CACHE && is_file( WP_CONTENT_DIR . '/advanced-cache.php' );
            $runtime = [
                'delete_url' => is_callable( [ 'Cache_Enabler', 'clear_page_cache_by_url' ] ) ? static function ( $url ) {
                    \Cache_Enabler::clear_page_cache_by_url( $url );
                } : null,
                'purge_site' => is_callable( [ 'Cache_Enabler', 'clear_site_cache' ] ) ? static function ( $site_id ) {
                    \Cache_Enabler::clear_site_cache( $site_id );
                } : null,
                'version'    => defined( 'CACHE_ENABLER_VERSION' ) ? CACHE_ENABLER_VERSION : '',
                'enabled'    => $enabled,
                'warm_urls'  => function_exists( 'directorist_page_cache_queue_warm_urls' ) ? 'directorist_page_cache_queue_warm_urls' : null,
            ];
        }

        $operations = [
            'delete_url' => isset( $runtime['delete_url'] ) ? $runtime['delete_url'] : null,
            'purge_site' => isset( $runtime['purge_site'] ) ? $runtime['purge_site'] : null,
            'warm_urls'  => isset( $runtime['warm_urls'] ) ? $runtime['warm_urls'] : null,
        ];

        $available = empty( $runtime['disabled'] ) && ( ! isset( $runtime['enabled'] ) || $runtime['enabled'] );

        $this->configure( 'cache-enabler', isset( $runtime['version'] ) ? $runtime['version'] : '', $operations, $available );
    }
}
