<?php

namespace Directorist\Cache;

/**
 * Guarded WP Fastest Cache free adapter.
 */
final class WP_Fastest_Cache_Provider extends Abstract_Cache_Provider {
    /**
     * @param array|null $runtime Test/runtime operation overrides.
     */
    public function __construct( array $runtime = null ) {
        if ( null === $runtime ) {
            $disabled = defined( 'WPFC_DISABLE_HOOK_CLEAR_ALL_CACHE' ) && WPFC_DISABLE_HOOK_CLEAR_ALL_CACHE;
            $options  = isset( $GLOBALS['wp_fastest_cache_options'] ) ? $GLOBALS['wp_fastest_cache_options'] : [];
            $enabled  = is_object( $options ) && isset( $options->wpFastestCacheStatus );
            $runtime  = [
                'purge_site' => ! $disabled && function_exists( 'wpfc_clear_all_cache' ) ? static function () {
                    return wpfc_clear_all_cache();
                } : null,
                'disabled'   => $disabled,
                'enabled'    => $enabled,
                'warm_urls'  => function_exists( 'directorist_page_cache_queue_warm_urls' ) ? 'directorist_page_cache_queue_warm_urls' : null,
                'version'    => Plugin_Version::resolve( [ WP_PLUGIN_DIR . '/wp-fastest-cache/wpFastestCache.php' ], 'unknown' ),
            ];
        }

        $operations = [
            'purge_site' => isset( $runtime['purge_site'] ) ? $runtime['purge_site'] : null,
            'warm_urls'  => isset( $runtime['warm_urls'] ) ? $runtime['warm_urls'] : null,
        ];

        $available = empty( $runtime['disabled'] ) && ( ! isset( $runtime['enabled'] ) || $runtime['enabled'] );

        $this->configure( 'wp-fastest-cache', isset( $runtime['version'] ) ? $runtime['version'] : '', $operations, $available );
    }
}
