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

            static $route_class_map = [
                'Directorist\\Cache\\Dependency_Collector'       => 'class-dependency-collector.php',
                'Directorist\\Cache\\Query_Normalization_Result' => 'class-query-normalization-result.php',
                'Directorist\\Cache\\Query_Normalizer'           => 'class-query-normalizer.php',
                'Directorist\\Cache\\Route_Identity'             => 'class-route-identity.php',
                'Directorist\\Cache\\Route_Resolver'             => 'class-route-resolver.php',
            ];

            if ( isset( $class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $class_map[ $class_name ];
            } elseif ( isset( $route_class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $route_class_map[ $class_name ];
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

    if ( ! function_exists( 'directorist_page_cache_add_dependency' ) ) {
        /**
         * Declare a dependency for the Directorist response currently being collected.
         *
         * @param string     $domain Dependency domain.
         * @param int|string $identifier Optional identifier.
         * @return bool Whether a collector accepted the dependency.
         */
        function directorist_page_cache_add_dependency( $domain, $identifier = '' ) {
            return directorist_page_cache()->add_dependency( $domain, $identifier );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_mark_private' ) ) {
        /**
         * Veto page caching for dynamic or personalized output in this request.
         *
         * @param string $reason Stable, non-sensitive reason.
         * @return bool
         */
        function directorist_page_cache_mark_private( $reason = 'integration_veto' ) {
            return directorist_page_cache()->mark_private( $reason );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_add_listing_dependencies' ) ) {
        /**
         * Add exact listing dependencies only while a cache response is collected.
         *
         * @param int   $listing_id Listing ID.
         * @param int   $author_id Listing author ID.
         * @param int[] $directory_ids Directory type term IDs.
         * @param int[] $term_ids Category, location, and tag term IDs.
         * @return bool
         */
        function directorist_page_cache_add_listing_dependencies( $listing_id, $author_id = 0, array $directory_ids = [], array $term_ids = [] ) {
            $manager    = directorist_page_cache();
            $listing_id = absint( $listing_id );

            if ( ! $listing_id || ! $manager->is_collecting_dependencies() ) {
                return false;
            }

            $manager->add_dependency( 'listing', $listing_id );

            if ( $author_id ) {
                $manager->add_dependency( 'author', $author_id );
            }

            foreach ( $directory_ids as $directory_id ) {
                $manager->add_dependency( 'directory', $directory_id );
            }

            foreach ( $term_ids as $term_id ) {
                $manager->add_dependency( 'term', $term_id );
            }

            return true;
        }
    }
}
