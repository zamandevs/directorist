<?php

namespace Directorist\Cache {
    spl_autoload_register(
        static function ( $class_name ) {
            static $class_map = [
                'Directorist\\Cache\\Cache_Manager'       => 'class-cache-manager.php',
                'Directorist\\Cache\\Cache_Provider'      => 'contracts/interface-cache-provider.php',
                'Directorist\\Cache\\Eligibility_Result'  => 'class-eligibility-result.php',
                'Directorist\\Cache\\Null_Cache_Provider' => 'class-null-cache-provider.php',
                'Directorist\\Cache\\Request_Context'     => 'class-request-context.php',
                'Directorist\\Cache\\Request_Policy'      => 'class-request-policy.php',
            ];

            if ( isset( $class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $class_map[ $class_name ];
            }
        }
    );
}

namespace {
    use Directorist\Cache\Cache_Manager;

    if ( ! function_exists( 'directorist_page_cache' ) ) {
        /**
         * Return Directorist's request-scoped page-cache manager.
         *
         * @return Cache_Manager
         */
        function directorist_page_cache() {
            return Cache_Manager::instance();
        }
    }
}
