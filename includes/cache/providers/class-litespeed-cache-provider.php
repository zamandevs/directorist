<?php

namespace Directorist\Cache;

/**
 * Guarded LiteSpeed Cache hook adapter.
 */
final class LiteSpeed_Cache_Provider extends Abstract_Cache_Provider {
    /**
     * @param array|null $runtime Test/runtime operation overrides.
     */
    public function __construct( array $runtime = null ) {
        if ( null === $runtime ) {
            $runtime = [
                'server_type' => defined( 'LITESPEED_SERVER_TYPE' ) ? LITESPEED_SERVER_TYPE : '',
                'cache_on'    => defined( 'LITESPEED_ON' ) && LITESPEED_ON,
                'version'     => defined( 'LSCWP_V' ) ? LSCWP_V : '',
                'warm_urls'   => function_exists( 'directorist_page_cache_queue_warm_urls' ) ? 'directorist_page_cache_queue_warm_urls' : null,
            ];
        }

        $server     = isset( $runtime['server'] )
            ? (bool) $runtime['server']
            : ! empty( $runtime['cache_on'] ) && in_array(
                isset( $runtime['server_type'] ) ? (string) $runtime['server_type'] : '',
                [ 'LITESPEED_SERVER_ADC', 'LITESPEED_SERVER_OLS', 'LITESPEED_SERVER_ENT' ],
                true
            );
        $operations = [
            'delete_url' => isset( $runtime['delete_url'] )
                ? $runtime['delete_url']
                : ( $server && has_action( 'litespeed_purge_url' ) ? static function ( $url ) {
                    do_action( 'litespeed_purge_url', $url );
                } : null ),
            'purge_site' => isset( $runtime['purge_site'] )
                ? $runtime['purge_site']
                : ( $server && has_action( 'litespeed_purge_all' ) ? static function () {
                    do_action( 'litespeed_purge_all' );
                } : null ),
            'warm_urls' => isset( $runtime['warm_urls'] ) ? $runtime['warm_urls'] : null,
        ];

        $this->configure( 'litespeed-cache', isset( $runtime['version'] ) ? $runtime['version'] : '', $operations, $server && empty( $runtime['disabled'] ) );
    }
}
