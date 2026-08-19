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
            $server  = defined( 'LITESPEED_ON' ) && LITESPEED_ON;
            $runtime = [
                'delete_url' => $server && has_action( 'litespeed_purge_url' ) ? static function ( $url ) {
                    do_action( 'litespeed_purge_url', $url );
                } : null,
                'purge_site' => $server && has_action( 'litespeed_purge_all' ) ? static function () {
                    do_action( 'litespeed_purge_all' );
                } : null,
                'version'    => defined( 'LSCWP_V' ) ? LSCWP_V : '',
                'server'     => $server,
            ];
        }

        $operations = [
            'delete_url' => isset( $runtime['delete_url'] ) ? $runtime['delete_url'] : null,
            'purge_site' => isset( $runtime['purge_site'] ) ? $runtime['purge_site'] : null,
        ];

        $this->configure( 'litespeed-cache', isset( $runtime['version'] ) ? $runtime['version'] : '', $operations, ! empty( $runtime['server'] ) && empty( $runtime['disabled'] ) );
    }
}
