<?php

namespace Directorist\Cache;

/**
 * Guarded WP Rocket public-API adapter.
 */
final class WP_Rocket_Provider extends Abstract_Cache_Provider {
    /**
     * @param array|null $runtime Test/runtime operation overrides.
     */
    public function __construct( array $runtime = null ) {
        if ( null === $runtime ) {
            $runtime = [
                'delete_urls' => function_exists( 'rocket_clean_files' ) ? static function ( array $urls ) {
                    rocket_clean_files( $urls );
                } : null,
                'purge_site'  => function_exists( 'rocket_clean_domain' ) ? static function () {
                    rocket_clean_domain();
                } : null,
                'version'     => defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : '',
            ];
        }

        $operations = [
            'delete_urls' => isset( $runtime['delete_urls'] ) ? $runtime['delete_urls'] : null,
            'purge_site'  => isset( $runtime['purge_site'] ) ? $runtime['purge_site'] : null,
        ];

        $this->configure( 'wp-rocket', isset( $runtime['version'] ) ? $runtime['version'] : '', $operations, empty( $runtime['disabled'] ) );
    }
}
