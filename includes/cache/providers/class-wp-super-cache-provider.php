<?php

namespace Directorist\Cache;

/**
 * Guarded WP Super Cache adapter.
 */
final class WP_Super_Cache_Provider extends Abstract_Cache_Provider {
    /** @var callable */
    private $inventory_resolver;

    /**
     * @param array|null $runtime Test/runtime operation overrides.
     */
    public function __construct( array $runtime = null ) {
        if ( null === $runtime ) {
            $cache_enabled = ! empty( $GLOBALS['cache_enabled'] );
            $version_files = [ WP_PLUGIN_DIR . '/wp-super-cache/wp-cache.php' ];

            if ( defined( 'WPCACHEHOME' ) ) {
                array_unshift( $version_files, trailingslashit( WPCACHEHOME ) . 'wp-cache.php' );
            }

            $runtime = [
                'delete_url'     => function_exists( 'wpsc_delete_url_cache' ) ? static function ( $url ) {
                    return wpsc_delete_url_cache( $url );
                } : null,
                'purge_site'     => function_exists( 'wp_cache_clear_cache' ) ? static function ( $site_id ) {
                    wp_cache_clear_cache( is_multisite() ? $site_id : 0 );
                } : null,
                'warm_urls'      => function_exists( 'directorist_page_cache_queue_warm_urls' ) ? 'directorist_page_cache_queue_warm_urls' : null,
                'version'        => Plugin_Version::resolve( $version_files, defined( 'WPSC_VERSION_ID' ) ? WPSC_VERSION_ID : '' ),
                'enabled'        => $cache_enabled,
                'stats_resolver' => static function () {
                    return get_option( 'supercache_stats', [] );
                },
            ];
        }

        $operations = [
            'delete_url' => isset( $runtime['delete_url'] ) ? $runtime['delete_url'] : null,
            'purge_site' => isset( $runtime['purge_site'] ) ? $runtime['purge_site'] : null,
            'warm_urls'  => isset( $runtime['warm_urls'] ) ? $runtime['warm_urls'] : null,
        ];

        $available = empty( $runtime['disabled'] ) && ( ! isset( $runtime['enabled'] ) || $runtime['enabled'] );
        $stats     = isset( $runtime['stats'] ) ? $runtime['stats'] : [];

        $this->inventory_resolver = isset( $runtime['stats_resolver'] ) && is_callable( $runtime['stats_resolver'] )
            ? $runtime['stats_resolver']
            : static function () use ( $stats ) {
                return $stats;
            };

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

    /** @return array */
    public function get_status() {
        $status        = parent::get_status();
        $compatibility = WP_Super_Cache_Compatibility::current();

        if ( ! empty( $compatibility ) ) {
            $status['configuration_safe'] = ! empty( $compatibility['safe'] );
            $status['configuration_code'] = isset( $compatibility['code'] ) ? sanitize_key( $compatibility['code'] ) : 'configuration_unknown';
        }

        try {
            $inventory = $this->normalize_inventory( call_user_func( $this->inventory_resolver ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $inventory = [];
        }

        if ( ! empty( $inventory ) ) {
            $status['inventory'] = $inventory;
        }

        return $status;
    }

    /**
     * Read WP Super Cache's stored provider-wide stats without regenerating them.
     *
     * @param mixed $stats Stored stats option.
     * @return array
     */
    private function normalize_inventory( $stats ) {
        if ( ! is_array( $stats ) || empty( $stats['generated'] ) ) {
            return [];
        }

        $entries = 0;
        $expired = 0;
        $bytes   = 0;

        foreach ( [ 'supercache', 'wpcache' ] as $type ) {
            $values   = isset( $stats[ $type ] ) && is_array( $stats[ $type ] ) ? $stats[ $type ] : [];
            $entries += isset( $values['cached'] ) ? max( 0, (int) $values['cached'] ) : 0;
            $expired += isset( $values['expired'] ) ? max( 0, (int) $values['expired'] ) : 0;
            $bytes   += isset( $values['fsize'] ) ? max( 0, (int) $values['fsize'] ) : 0;
        }

        return [
            'success'      => true,
            'code'         => 'provider_stats',
            'scope'        => 'provider_site',
            'entries'      => $entries,
            'bytes'        => $bytes,
            'orphans'      => $expired,
            'generations'  => 0,
            'truncated'    => false,
            'generated_at' => max( 0, (int) $stats['generated'] ),
        ];
    }
}
