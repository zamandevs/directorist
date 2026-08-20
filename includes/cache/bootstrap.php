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
                'Directorist\\Cache\\Dependency_Key'             => 'class-dependency-key.php',
                'Directorist\\Cache\\Query_Normalization_Result' => 'class-query-normalization-result.php',
                'Directorist\\Cache\\Query_Normalizer'           => 'class-query-normalizer.php',
                'Directorist\\Cache\\Route_Identity'             => 'class-route-identity.php',
                'Directorist\\Cache\\Route_Resolver'             => 'class-route-resolver.php',
                'Directorist\\Cache\\Response_Capture'           => 'class-response-capture.php',
                'Directorist\\Cache\\Warm_URL_Discovery'         => 'class-warm-url-discovery.php',
                'Directorist\\Cache\\Warm_URL_Registry'          => 'class-warm-url-registry.php',
            ];

            static $mutation_class_map = [
                'Directorist\\Cache\\Change_Set'               => 'class-change-set.php',
                'Directorist\\Cache\\Change_Type'              => 'class-change-type.php',
                'Directorist\\Cache\\Invalidation_Dispatcher'  => 'class-invalidation-dispatcher.php',
                'Directorist\\Cache\\Invalidation_Plan'        => 'class-invalidation-plan.php',
                'Directorist\\Cache\\Invalidation_Planner'     => 'class-invalidation-planner.php',
                'Directorist\\Cache\\Invalidation_Subscriber'  => 'class-invalidation-subscriber.php',
                'Directorist\\Cache\\Mutation_Entity_Resolver' => 'class-mutation-entity-resolver.php',
            ];

            static $provider_class_map = [
                'Directorist\\Cache\\Abstract_Cache_Provider'   => 'providers/abstract-cache-provider.php',
                'Directorist\\Cache\\Cache_Enabler_Provider'    => 'providers/class-cache-enabler-provider.php',
                'Directorist\\Cache\\Dropin_Owner_Detector'     => 'class-dropin-owner-detector.php',
                'Directorist\\Cache\\LiteSpeed_Cache_Provider'  => 'providers/class-litespeed-cache-provider.php',
                'Directorist\\Cache\\Provider_Capabilities'     => 'class-provider-capabilities.php',
                'Directorist\\Cache\\Provider_Registry'         => 'class-provider-registry.php',
                'Directorist\\Cache\\Provider_Selection'        => 'class-provider-selection.php',
                'Directorist\\Cache\\WP_Fastest_Cache_Provider' => 'providers/class-wp-fastest-cache-provider.php',
                'Directorist\\Cache\\WP_Rocket_Provider'        => 'providers/class-wp-rocket-provider.php',
                'Directorist\\Cache\\WP_Super_Cache_Provider'   => 'providers/class-wp-super-cache-provider.php',
            ];

            if ( isset( $class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $class_map[ $class_name ];
            } elseif ( isset( $route_class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $route_class_map[ $class_name ];
            } elseif ( isset( $mutation_class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $mutation_class_map[ $class_name ];
            } elseif ( isset( $provider_class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $provider_class_map[ $class_name ];
            }
        }
    );
}

namespace {
    use Directorist\Cache\Cache_Manager;
    use Directorist\Cache\Cache_Provider;
    use Directorist\Cache\Dropin_Owner_Detector;
    use Directorist\Cache\Provider_Registry;

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

    if ( ! function_exists( 'directorist_page_cache_record_change' ) ) {
        /**
         * Declare a completed extension-owned mutation for page-cache invalidation.
         *
         * This API is intentionally inert until an available cache provider
         * enables mutation tracking through the manager.
         *
         * @param string $extension Extension slug.
         * @param string $identifier Mutation identifier.
         * @param array  $context Mutation context.
         * @return bool Whether the active change set accepted the mutation.
         */
        function directorist_page_cache_record_change( $extension, $identifier = '', array $context = [] ) {
            return directorist_page_cache()->record_extension_change( $extension, $identifier, $context );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_begin_response_capture' ) ) {
        /**
         * Begin explicit dependency collection before a public renderer runs.
         *
         * This API is inert until a cache-provider integration calls it.
         *
         * @return array Normalized eligibility descriptor.
         */
        function directorist_page_cache_begin_response_capture() {
            return directorist_page_cache()->begin_response_capture();
        }
    }

    if ( ! function_exists( 'directorist_page_cache_finish_response_capture' ) ) {
        /**
         * Finalize one explicitly started response after rendering.
         *
         * @return array Normalized final response descriptor.
         */
        function directorist_page_cache_finish_response_capture() {
            return directorist_page_cache()->finish_response_capture();
        }
    }

    if ( ! function_exists( 'directorist_page_cache_provider_registry' ) ) {
        /**
         * Return the request-scoped provider registry.
         *
         * @return Provider_Registry
         */
        function directorist_page_cache_provider_registry() {
            static $registry;

            if ( ! $registry instanceof Provider_Registry ) {
                $registry = new Provider_Registry();
            }

            return $registry;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_register_provider' ) ) {
        /**
         * Register a custom page-cache provider for this request.
         *
         * The filter `directorist_page_cache_providers` is preferred by plugins
         * that may load before Directorist.
         *
         * @param Cache_Provider $provider Provider implementation.
         * @param int            $priority Selection priority used for duplicate IDs.
         * @return bool
         */
        function directorist_page_cache_register_provider( Cache_Provider $provider, $priority = 10 ) {
            $GLOBALS['directorist_page_cache_custom_provider_registered'] = true;

            return directorist_page_cache_provider_registry()->register( $provider, $priority );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_warm_url_registry' ) ) {
        /**
         * Return the request-scoped bounded warm URL registry.
         *
         * @return Directorist\Cache\Warm_URL_Registry
         */
        function directorist_page_cache_warm_url_registry() {
            static $registry;

            if ( ! $registry instanceof Directorist\Cache\Warm_URL_Registry ) {
                $registry = new Directorist\Cache\Warm_URL_Registry();
            }

            return $registry;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_register_warm_urls' ) ) {
        /**
         * Register extension-owned public URLs for the next bounded discovery.
         *
         * @param string|string[] $urls Public URLs.
         * @param string          $source Stable source identifier.
         * @return int Number of newly accepted URLs.
         */
        function directorist_page_cache_register_warm_urls( $urls, $source = 'extension' ) {
            return directorist_page_cache_warm_url_registry()->add( $urls, $source );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_discover_warm_urls' ) ) {
        /**
         * Discover bounded core and extension public warm URLs on demand.
         *
         * @param array $args Bounded discovery limits.
         * @return string[]
         */
        function directorist_page_cache_discover_warm_urls( array $args = [] ) {
            $discovery = new Directorist\Cache\Warm_URL_Discovery( directorist_page_cache_warm_url_registry() );

            return $discovery->discover( $args );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_warm_urls' ) ) {
        /**
         * Dispatch public URLs to the selected cache provider.
         *
         * @param string[] $urls Public URLs.
         * @return array
         */
        function directorist_page_cache_warm_urls( array $urls ) {
            return directorist_page_cache()->warm( $urls );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_flush_provider_detection' ) ) {
        /**
         * Invalidate cached drop-in ownership after plugin lifecycle changes.
         *
         * @param mixed $unused First lifecycle argument.
         * @param mixed $unused_two Second lifecycle argument.
         * @return void
         */
        function directorist_page_cache_flush_provider_detection( $unused = null, $unused_two = null ) {
            unset( $unused, $unused_two );

            Dropin_Owner_Detector::invalidate_persistent_cache();
        }
    }

    if ( ! function_exists( 'directorist_page_cache_has_provider_signal' ) ) {
        /**
         * Avoid loading the registry on the normal no-provider path.
         *
         * @return bool
         */
        function directorist_page_cache_has_provider_signal() {
            return function_exists( 'wpsc_delete_url_cache' )
                || function_exists( 'wp_cache_clear_cache' )
                || class_exists( 'Cache_Enabler', false )
                || function_exists( 'wpfc_clear_all_cache' )
                || function_exists( 'rocket_clean_files' )
                || function_exists( 'rocket_clean_domain' )
                || defined( 'LSCWP_V' )
                || ! empty( $GLOBALS['directorist_page_cache_custom_provider_registered'] )
                || has_filter( 'directorist_page_cache_providers' );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_boot_provider' ) ) {
        /**
         * Select one non-conflicting provider after all plugins have loaded.
         *
         * @return void
         */
        function directorist_page_cache_boot_provider() {
            if ( ! directorist_page_cache_has_provider_signal() ) {
                return;
            }

            $selection = directorist_page_cache_provider_registry()->select();
            $provider  = $selection->get_provider();

            if ( $provider instanceof Cache_Provider ) {
                directorist_page_cache()->enable_invalidation( $provider );
            }

            do_action( 'directorist_page_cache_provider_selected', $selection );
        }
    }

    add_action( 'plugins_loaded', 'directorist_page_cache_boot_provider', PHP_INT_MAX );

    if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        add_action( 'activated_plugin', 'directorist_page_cache_flush_provider_detection', 10, 2 );
        add_action( 'deactivated_plugin', 'directorist_page_cache_flush_provider_detection', 10, 2 );
        add_action( 'deleted_plugin', 'directorist_page_cache_flush_provider_detection', 10, 2 );
        add_action( 'upgrader_process_complete', 'directorist_page_cache_flush_provider_detection', 10, 2 );
    }
}
