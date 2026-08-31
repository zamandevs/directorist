<?php

namespace Directorist\Cache {
    spl_autoload_register(
        static function ( $class_name ) {
            static $class_map = [
                'Directorist\\Cache\\Cache_Manager'       => 'class-cache-manager.php',
                'Directorist\\Cache\\Cache_Provider'      => 'contracts/interface-cache-provider.php',
                'Directorist\\Cache\\Cookie_Policy'          => 'class-cookie-policy.php',
                'Directorist\\Cache\\Eligibility_Result'  => 'class-eligibility-result.php',
                'Directorist\\Cache\\Internal_Request_Guard' => 'class-internal-request-guard.php',
                'Directorist\\Cache\\Null_Cache_Provider' => 'class-null-cache-provider.php',
                'Directorist\\Cache\\Request_Context'     => 'class-request-context.php',
                'Directorist\\Cache\\Request_Policy'      => 'class-request-policy.php',
            ];

            static $route_class_map = [
                'Directorist\\Cache\\Automatic_Warmer'           => 'class-automatic-warmer.php',
                'Directorist\\Cache\\Automatic_Refresh_Coordinator' => 'class-automatic-refresh-coordinator.php',
                'Directorist\\Cache\\Dependency_Collector'       => 'class-dependency-collector.php',
                'Directorist\\Cache\\Dependency_Key'             => 'class-dependency-key.php',
                'Directorist\\Cache\\Query_Normalization_Result' => 'class-query-normalization-result.php',
                'Directorist\\Cache\\Query_Normalizer'           => 'class-query-normalizer.php',
                'Directorist\\Cache\\Query_Schema_Resolver'      => 'class-query-schema-resolver.php',
                'Directorist\\Cache\\Route_Identity'             => 'class-route-identity.php',
                'Directorist\\Cache\\Route_Resolver'             => 'class-route-resolver.php',
                'Directorist\\Cache\\Response_Capture'           => 'class-response-capture.php',
                'Directorist\\Cache\\Warm_URL_Discovery'         => 'class-warm-url-discovery.php',
                'Directorist\\Cache\\Warm_URL_Registry'          => 'class-warm-url-registry.php',
                'Directorist\\Cache\\Warm_Background_Process'    => 'class-warm-background-process.php',
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
                'Directorist\\Cache\\LiteSpeed_Compatibility'      => 'class-litespeed-compatibility.php',
                'Directorist\\Cache\\LiteSpeed_Cache_Provider'  => 'providers/class-litespeed-cache-provider.php',
                'Directorist\\Cache\\Plugin_Version'           => 'providers/class-provider-plugin-version.php',
                'Directorist\\Cache\\Provider_Capabilities'     => 'class-provider-capabilities.php',
                'Directorist\\Cache\\Provider_Registry'         => 'class-provider-registry.php',
                'Directorist\\Cache\\Provider_Selection'        => 'class-provider-selection.php',
                'Directorist\\Cache\\WP_Fastest_Cache_Provider' => 'providers/class-wp-fastest-cache-provider.php',
                'Directorist\\Cache\\WP_Rocket_Provider'        => 'providers/class-wp-rocket-provider.php',
                'Directorist\\Cache\\WP_Super_Cache_Provider'   => 'providers/class-wp-super-cache-provider.php',
                'Directorist\\Cache\\WP_Super_Cache_Compatibility' => 'class-wp-super-cache-compatibility.php',
            ];

            static $provider_compatibility_class_map = [
                'Directorist\\Cache\\Cache_Enabler_Compatibility'    => 'class-cache-enabler-compatibility.php',
                'Directorist\\Cache\\WP_Fastest_Cache_Compatibility' => 'class-wp-fastest-cache-compatibility.php',
            ];

            static $performance_class_map = [
                'Directorist\\Cache\\Performance_Admin'      => 'class-performance-admin.php',
                'Directorist\\Cache\\Performance_Event_Log'  => 'class-performance-event-log.php',
                'Directorist\\Cache\\Performance_Listing_Index_Service' => 'class-performance-listing-index-service.php',
                'Directorist\\Cache\\Performance_Job_Manager'           => 'class-performance-job-manager.php',
                'Directorist\\Cache\\Performance_Job_Ledger'            => 'class-performance-job-ledger.php',
                'Directorist\\Cache\\Performance_Job_Process'           => 'class-performance-job-process.php',
                'Directorist\\Cache\\Performance_Operations' => 'class-performance-operations.php',
                'Directorist\\Cache\\Performance_Resource_Catalog'      => 'class-performance-resource-catalog.php',
                'Directorist\\Cache\\Performance_Resource_Discovery'    => 'class-performance-resource-discovery.php',
                'Directorist\\Cache\\Performance_Resource_Process'      => 'class-performance-resource-process.php',
                'Directorist\\Cache\\Performance_Resource_Reconciler'   => 'class-performance-resource-reconciler.php',
                'Directorist\\Cache\\Performance_Resource_Store'        => 'class-performance-resource-store.php',
                'Directorist\\Cache\\Performance_Translation_Resolver' => 'class-performance-translation-resolver.php',
                'Directorist\\Cache\\Performance_REST_Controller'       => 'class-performance-rest-controller.php',
                'Directorist\\Cache\\Performance_Settings'   => 'class-performance-settings.php',
                'Directorist\\Cache\\Performance_Settings_Automation'   => 'class-performance-settings-automation.php',
                'Directorist\\Cache\\Performance_Status'     => 'class-performance-status.php',
            ];

            static $built_in_class_map = [
                'Directorist\\Cache\\Built_In\\Activation_Policy'          => 'class-activation-policy.php',
                'Directorist\\Cache\\Built_In\\Atomic_File_Writer'         => 'class-atomic-file-writer.php',
                'Directorist\\Cache\\Built_In\\Atomic_Writer'              => 'class-atomic-writer.php',
                'Directorist\\Cache\\Built_In\\Cache_Engine'               => 'class-cache-engine.php',
                'Directorist\\Cache\\Built_In\\Cache_Cleaner'              => 'class-cache-cleaner.php',
                'Directorist\\Cache\\Built_In\\Cache_Inventory'            => 'class-cache-inventory.php',
                'Directorist\\Cache\\Built_In\\Cache_Paths'                => 'class-cache-paths.php',
                'Directorist\\Cache\\Built_In\\Cache_Storage'              => 'class-cache-storage.php',
                'Directorist\\Cache\\Built_In\\Cleanup_Background_Process' => 'class-cleanup-background-process.php',
                'Directorist\\Cache\\Built_In\\Cookie_Policy'              => 'class-cookie-policy.php',
                'Directorist\\Cache\\Built_In\\Dropin_Installer'           => 'class-dropin-installer.php',
                'Directorist\\Cache\\Built_In\\Early_Config'               => 'class-early-config.php',
                'Directorist\\Cache\\Built_In\\Early_Config_Manager'       => 'class-early-config-manager.php',
                'Directorist\\Cache\\Built_In\\Early_Refresh_Dispatcher'    => 'class-early-refresh-dispatcher.php',
                'Directorist\\Cache\\Built_In\\Generation_Store'           => 'class-generation-store.php',
                'Directorist\\Cache\\Built_In\\Lifecycle'                  => 'class-lifecycle.php',
                'Directorist\\Cache\\Built_In\\Ownership'                  => 'class-ownership.php',
                'Directorist\\Cache\\Built_In\\Provider'                   => 'class-provider.php',
                'Directorist\\Cache\\Built_In\\Request_Guard'              => 'class-request-guard.php',
                'Directorist\\Cache\\Built_In\\Request_Key'                => 'class-request-key.php',
                'Directorist\\Cache\\Built_In\\Refresh_Policy'             => 'class-refresh-policy.php',
                'Directorist\\Cache\\Built_In\\Response_Validator'         => 'class-response-validator.php',
                'Directorist\\Cache\\Built_In\\Runtime_Manager'            => 'class-runtime-manager.php',
                'Directorist\\Cache\\Built_In\\WP_Cache_Config'            => 'class-wp-cache-config.php',
            ];

            if ( isset( $class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $class_map[ $class_name ];
            } elseif ( isset( $route_class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $route_class_map[ $class_name ];
            } elseif ( isset( $mutation_class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $mutation_class_map[ $class_name ];
            } elseif ( isset( $provider_class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $provider_class_map[ $class_name ];
            } elseif ( isset( $provider_compatibility_class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $provider_compatibility_class_map[ $class_name ];
            } elseif ( isset( $performance_class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/' . $performance_class_map[ $class_name ];
            } elseif ( isset( $built_in_class_map[ $class_name ] ) ) {
                require_once __DIR__ . '/built-in/' . $built_in_class_map[ $class_name ];
            }
        }
    );
}

namespace {
    use Directorist\Cache\Built_In\Atomic_Writer as Built_In_Atomic_Writer;
    use Directorist\Cache\Built_In\Cache_Inventory as Built_In_Cache_Inventory;
    use Directorist\Cache\Built_In\Cache_Storage as Built_In_Cache_Storage;
    use Directorist\Cache\Built_In\Cleanup_Background_Process as Built_In_Cleanup_Background_Process;
    use Directorist\Cache\Built_In\Dropin_Installer as Built_In_Dropin_Installer;
    use Directorist\Cache\Built_In\Lifecycle as Built_In_Lifecycle;
    use Directorist\Cache\Built_In\Provider as Built_In_Provider;
    use Directorist\Cache\Built_In\Runtime_Manager as Built_In_Runtime_Manager;
    use Directorist\Cache\Built_In\WP_Cache_Config as Built_In_WP_Cache_Config;
    use Directorist\Cache\Automatic_Warmer;
    use Directorist\Cache\Automatic_Refresh_Coordinator;
    use Directorist\Cache\Cache_Manager;
    use Directorist\Cache\Cache_Provider;
    use Directorist\Cache\Cache_Enabler_Compatibility;
    use Directorist\Cache\Cookie_Policy;
    use Directorist\Cache\Dropin_Owner_Detector;
    use Directorist\Cache\Internal_Request_Guard;
    use Directorist\Cache\LiteSpeed_Compatibility;
    use Directorist\Cache\Performance_Admin;
    use Directorist\Cache\Performance_Event_Log;
    use Directorist\Cache\Performance_Job_Manager;
    use Directorist\Cache\Performance_Job_Process;
    use Directorist\Cache\Performance_Listing_Index_Service;
    use Directorist\Cache\Performance_Operations;
    use Directorist\Cache\Performance_Resource_Catalog;
    use Directorist\Cache\Performance_Resource_Discovery;
    use Directorist\Cache\Performance_Resource_Process;
    use Directorist\Cache\Performance_Resource_Reconciler;
    use Directorist\Cache\Performance_Resource_Store;
    use Directorist\Cache\Performance_REST_Controller;
    use Directorist\Cache\Performance_Settings;
    use Directorist\Cache\Performance_Settings_Automation;
    use Directorist\Cache\Performance_Status;

    use Directorist\Cache\Provider_Registry;
    use Directorist\Cache\Warm_Background_Process;
    use Directorist\Cache\WP_Fastest_Cache_Compatibility;
    use Directorist\Cache\WP_Super_Cache_Compatibility;

    if ( ! function_exists( 'directorist_page_cache_refresh_token' ) ) {
        /** @return string */
        function directorist_page_cache_refresh_token() {
            return function_exists( 'wp_salt' )
                ? hash_hmac( 'sha256', 'directorist-page-cache-refresh-v1', wp_salt( 'auth' ) )
                : '';
        }
    }

    if ( ! function_exists( 'directorist_page_cache_builtin_wp_config_path' ) ) {
        /** @return string */
        function directorist_page_cache_builtin_wp_config_path() {
            $root_path = ABSPATH . 'wp-config.php';

            if ( is_file( $root_path ) || is_link( $root_path ) ) {
                return $root_path;
            }

            return dirname( ABSPATH ) . '/wp-config.php';
        }
    }

    if ( ! function_exists( 'directorist_page_cache_builtin_runtime' ) ) {
        /** @return Built_In_Runtime_Manager */
        function directorist_page_cache_builtin_runtime() {
            static $runtime;

            if ( ! $runtime instanceof Built_In_Runtime_Manager ) {
                $writer    = new Built_In_Atomic_Writer();
                $installer = new Built_In_Dropin_Installer( WP_CONTENT_DIR, ATBDP_DIR, $writer );
                $wp_cache  = new Built_In_WP_Cache_Config( directorist_page_cache_builtin_wp_config_path(), $writer );
                $runtime   = new Built_In_Runtime_Manager( $installer, $wp_cache );
            }

            return $runtime;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_builtin_external_probe' ) ) {
        /** @return array */
        function directorist_page_cache_builtin_external_probe() {
            $registry  = new Provider_Registry();
            $selection = $registry->select( true, false );
            $provider  = $selection->get_provider();

            return [
                'code'       => $selection->is_selected() ? 'selected' : $selection->get_code(),
                'provider'   => $provider instanceof Cache_Provider ? $provider->get_id() : '',
                'candidates' => $selection->get_candidate_ids(),
            ];
        }
    }

    if ( ! function_exists( 'directorist_page_cache_builtin_bump_lifecycle_generation' ) ) {
        /** @return array */
        function directorist_page_cache_builtin_bump_lifecycle_generation() {
            $storage = new Built_In_Cache_Storage( WP_CONTENT_DIR . '/cache/directorist-page-cache' );

            return $storage->bump_generations( [ 'directorist:0:lifecycle' ] );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_builtin_is_network_wide' ) ) {
        /** @return bool */
        function directorist_page_cache_builtin_is_network_wide() {
            if ( ! is_multisite() ) {
                return true;
            }

            $plugins = get_site_option( 'active_sitewide_plugins', [] );
            $base    = plugin_basename( ATBDP_DIR . 'directorist-base.php' );

            return is_array( $plugins ) && isset( $plugins[ $base ] );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_builtin_lifecycle' ) ) {
        /** @return Built_In_Lifecycle */
        function directorist_page_cache_builtin_lifecycle() {
            static $lifecycle;

            if ( ! $lifecycle instanceof Built_In_Lifecycle ) {
                $lifecycle = new Built_In_Lifecycle(
                    directorist_page_cache_builtin_runtime(),
                    [
                        'external_probe'   => 'directorist_page_cache_builtin_external_probe',
                        'owner_resolver'   => static function () {
                            return ( new Dropin_Owner_Detector() )->detect();
                        },
                        'enabled_resolver' => 'directorist_page_cache_is_enabled',
                        'generation_bump'  => 'directorist_page_cache_builtin_bump_lifecycle_generation',
                        'is_multisite'     => 'is_multisite',
                        'network_wide'     => 'directorist_page_cache_builtin_is_network_wide',
                    ]
                );
            }

            return $lifecycle;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_builtin_runtime_is_healthy' ) ) {
        /** @return bool */
        function directorist_page_cache_builtin_runtime_is_healthy() {
            if ( ! apply_filters( 'directorist_page_cache_allow_runtime_mutation', true, 'health' ) ) {
                return false;
            }

            return directorist_page_cache_is_enabled() && directorist_page_cache_builtin_lifecycle()->is_runtime_healthy();
        }
    }

    if ( ! function_exists( 'directorist_page_cache_builtin_engine_instance' ) ) {
        /** @return Directorist\Cache\Built_In\Cache_Engine */
        function directorist_page_cache_builtin_engine_instance() {
            if ( ! function_exists( 'directorist_page_cache_builtin_engine' ) ) {
                require_once __DIR__ . '/built-in/class-cache-engine.php';
            }

            $settings = directorist_page_cache_performance_settings();
            $values   = $settings->get();
            $engine   = directorist_page_cache_builtin_engine(
                [
                    'cache_dir'              => WP_CONTENT_DIR . '/cache/directorist-page-cache',
                    'ttl'                    => $settings->get_cache_ttl(),
                    'stale_ttl'              => 30,
                    'refresh_policy'         => $settings->get_cache_policy(),
                    'refresh_endpoint'       => admin_url( 'admin-ajax.php' ),
                    'refresh_token'          => directorist_page_cache_refresh_token(),
                    'cache_filtered_results' => ! empty( $values['cache_filtered_results'] ),
                    'debug'                  => false,
                ]
            );

            $engine->set_warm_handler( 'directorist_page_cache_queue_warm_urls' );

            return $engine;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_builtin_inventory' ) ) {
        /** @return array */
        function directorist_page_cache_builtin_inventory( array $args = [] ) {
            return ( new Built_In_Cache_Inventory( WP_CONTENT_DIR . '/cache/directorist-page-cache' ) )->status( $args );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_builtin_cleanup_process' ) ) {
        /** @return Built_In_Cleanup_Background_Process */
        function directorist_page_cache_builtin_cleanup_process() {
            static $worker;

            if ( ! $worker instanceof Built_In_Cleanup_Background_Process ) {
                $worker = new Built_In_Cleanup_Background_Process();
            }

            return $worker;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_boot_cron_workers' ) ) {
        /**
         * Register custom recurring schedules before WordPress reschedules cron events.
         *
         * @param bool|null $doing_cron Explicit test boundary or detected cron state.
         * @return bool
         */
        function directorist_page_cache_boot_cron_workers( $doing_cron = null ) {
            if ( null === $doing_cron ) {
                $doing_cron = function_exists( 'wp_doing_cron' )
                    ? wp_doing_cron()
                    : defined( 'DOING_CRON' ) && DOING_CRON;
            }

            if ( ! $doing_cron ) {
                return false;
            }

            $warm_worker     = directorist_page_cache_warm_background_process();
            $cleanup_worker  = directorist_page_cache_builtin_cleanup_process();
            $resource_worker = directorist_page_cache_performance_resource_process();
            $job_worker      = directorist_page_cache_performance_job_process();
            $job_cron_hook   = 'wp_' . get_current_blog_id() . '_' . Performance_Job_Process::ACTION . '_cron';

            add_filter( 'cron_schedules', [ $warm_worker, 'schedule_cron_healthcheck' ] );
            add_filter( 'cron_schedules', [ $cleanup_worker, 'schedule_cron_healthcheck' ] );
            add_filter( 'cron_schedules', [ $resource_worker, 'schedule_cron_healthcheck' ] );
            add_filter( 'cron_schedules', [ $job_worker, 'schedule_cron_healthcheck' ] );

            if ( false === has_action( $job_cron_hook, [ $job_worker, 'handle_cron_healthcheck' ] ) ) {
                add_action( $job_cron_hook, [ $job_worker, 'handle_cron_healthcheck' ] );
            }

            return true;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_queue_builtin_cleanup' ) ) {
        /** @return array */
        function directorist_page_cache_queue_builtin_cleanup() {
            if ( ! directorist_page_cache_builtin_runtime_is_healthy() ) {
                return [ 'success' => false, 'code' => 'runtime_unavailable', 'queued' => 0 ];
            }

            return directorist_page_cache_builtin_cleanup_process()->enqueue();
        }
    }

    if ( ! function_exists( 'directorist_page_cache_sync_builtin_maintenance' ) ) {
        /**
         * Keep maintenance scheduling aligned with the selected delivery state.
         *
         * @param array $state Lifecycle result.
         * @return bool
         */
        function directorist_page_cache_sync_builtin_maintenance( array $state ) {
            $built_in = ! empty( $state['success'] ) && 'built_in' === ( isset( $state['state'] ) ? $state['state'] : '' );

            if ( $built_in ) {
                if ( ! wp_next_scheduled( 'directorist_page_cache_daily_cleanup' ) ) {
                    wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'directorist_page_cache_daily_cleanup' );
                }

                if ( ! wp_next_scheduled( 'directorist_page_cache_refresh_due_entries' ) ) {
                    wp_schedule_single_event( time() + 300, 'directorist_page_cache_refresh_due_entries' );
                }

                return true;
            }

            wp_clear_scheduled_hook( 'directorist_page_cache_daily_cleanup' );
            wp_clear_scheduled_hook( 'directorist_page_cache_refresh_due_entries' );
            directorist_page_cache_builtin_cleanup_process()->reset();

            return true;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_builtin_provider' ) ) {
        /** @return Built_In_Provider */
        function directorist_page_cache_builtin_provider() {
            return new Built_In_Provider(
                'directorist_page_cache_builtin_engine_instance',
                'directorist_page_cache_builtin_runtime_is_healthy',
                'directorist_page_cache_is_enabled',
                'directorist_page_cache_queue_builtin_cleanup',
                'directorist_page_cache_builtin_inventory'
            );
        }
    }

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

    if ( ! function_exists( 'directorist_page_cache_cookie_policy' ) ) {
        /**
         * Return the normalized cookie policy shared with early providers.
         *
         * @return array
         */
        function directorist_page_cache_cookie_policy() {
            return ( new Cookie_Policy() )->to_array();
        }
    }

    if ( ! function_exists( 'directorist_page_cache_warm_background_process' ) ) {
        /** @return Warm_Background_Process */
        function directorist_page_cache_warm_background_process() {
            static $worker;

            if ( ! $worker instanceof Warm_Background_Process ) {
                $worker = new Warm_Background_Process(
                    [
                        'verifier' => 'directorist_page_cache_verify_warmed_url',
                    ]
                );
            }

            return $worker;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_wp_super_cache_compatibility' ) ) {
        /** @return WP_Super_Cache_Compatibility */
        function directorist_page_cache_wp_super_cache_compatibility() {
            static $compatibility;

            if ( ! $compatibility instanceof WP_Super_Cache_Compatibility ) {
                $compatibility = new WP_Super_Cache_Compatibility();
            }

            return $compatibility;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_cache_enabler_compatibility' ) ) {
        /** @return Cache_Enabler_Compatibility */
        function directorist_page_cache_cache_enabler_compatibility() {
            static $compatibility;

            if ( ! $compatibility instanceof Cache_Enabler_Compatibility ) {
                $compatibility = new Cache_Enabler_Compatibility();
            }

            return $compatibility;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_wp_fastest_cache_compatibility' ) ) {
        /** @return WP_Fastest_Cache_Compatibility */
        function directorist_page_cache_wp_fastest_cache_compatibility() {
            static $compatibility;

            if ( ! $compatibility instanceof WP_Fastest_Cache_Compatibility ) {
                $compatibility = new WP_Fastest_Cache_Compatibility();
            }

            return $compatibility;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_litespeed_compatibility' ) ) {
        /** @return LiteSpeed_Compatibility */
        function directorist_page_cache_litespeed_compatibility() {
            static $compatibility;

            if ( ! $compatibility instanceof LiteSpeed_Compatibility ) {
                $compatibility = new LiteSpeed_Compatibility();
            }

            return $compatibility;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_queue_warm_urls' ) ) {
        /**
         * Queue bounded anonymous same-origin warm requests for the selected provider.
         *
         * @param string[] $urls Public URLs.
         * @return array
         */
        function directorist_page_cache_queue_warm_urls( array $urls ) {
            return directorist_page_cache_warm_background_process()->enqueue( $urls );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_queue_refresh_request' ) ) {
        /**
         * Queue one exact cache variant from an authenticated stale handoff.
         *
         * @param string $url Canonical public URL.
         * @param array  $variation Validated cache variation.
         * @param string $cache_hash Expected cache hash.
         * @return array
         */
        function directorist_page_cache_queue_refresh_request( $url, array $variation, $cache_hash ) {
            return directorist_page_cache_warm_background_process()->enqueue_refresh( $url, $variation, $cache_hash );
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

    if ( ! function_exists( 'directorist_page_cache_performance_settings' ) ) {
        /** @return Performance_Settings */
        function directorist_page_cache_performance_settings() {
            static $settings;

            if ( ! $settings instanceof Performance_Settings ) {
                $settings = new Performance_Settings();
            }

            return $settings;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_is_enabled' ) ) {
        /** @return bool */
        function directorist_page_cache_is_enabled() {
            return directorist_page_cache_performance_settings()->is_enabled();
        }
    }

    if ( ! function_exists( 'directorist_page_cache_sample_performance_event' ) ) {
        /**
         * @param array  $decision Eligibility descriptor.
         * @param string $phase Capture phase.
         * @return void
         */
        function directorist_page_cache_sample_performance_event( $decision, $phase ) {
            if ( ! is_array( $decision ) ) {
                return;
            }

            if ( 'begin' === $phase && ! empty( $decision['eligible'] ) ) {
                return;
            }

            static $events;

            if ( ! $events instanceof Performance_Event_Log ) {
                $events = new Performance_Event_Log( directorist_page_cache_performance_settings() );
            }

            $events->maybe_sample( $decision, $phase );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_record_performance_event' ) ) {
        /**
         * Record a bounded operational event for cache providers and integrations.
         *
         * @param string $level Event level.
         * @param string $code Stable event code.
         * @param array  $context Bounded scalar context.
         * @return bool
         */
        function directorist_page_cache_record_performance_event( $level, $code, array $context = [] ) {
            static $events;

            if ( ! $events instanceof Performance_Event_Log ) {
                $events = new Performance_Event_Log( directorist_page_cache_performance_settings() );
            }

            return $events->record( $level, $code, $context );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_record_invalidation_outcome' ) ) {
        /** @return void */
        function directorist_page_cache_record_invalidation_outcome( $result, $plan ) {
            $result = is_array( $result ) ? $result : [];
            $plan   = is_array( $plan ) ? $plan : [];
            directorist_page_cache_record_performance_event(
                ! empty( $result['success'] ) ? 'success' : 'error',
                'cache-' . ( isset( $result['code'] ) ? sanitize_key( (string) $result['code'] ) : 'invalidation-failed' ),
                [
                    'urls'         => isset( $plan['urls'] ) && is_array( $plan['urls'] ) ? count( $plan['urls'] ) : 0,
                    'generations'  => isset( $plan['generations'] ) && is_array( $plan['generations'] ) ? count( $plan['generations'] ) : 0,
                    'conservative' => ! empty( $plan['conservative'] ) ? 'yes' : 'no',
                ]
            );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_record_warm_outcome' ) ) {
        /** @return void */
        function directorist_page_cache_record_warm_outcome( $result, $urls ) {
            $result = is_array( $result ) ? $result : [];
            directorist_page_cache_record_performance_event(
                ! empty( $result['success'] ) ? 'success' : 'warning',
                'automatic-warm-' . ( isset( $result['code'] ) ? sanitize_key( (string) $result['code'] ) : 'failed' ),
                [ 'queued' => isset( $result['queued'] ) ? max( 0, (int) $result['queued'] ) : ( is_array( $urls ) ? count( $urls ) : 0 ) ]
            );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_record_cleanup_outcome' ) ) {
        /** @return void */
        function directorist_page_cache_record_cleanup_outcome( $result ) {
            $result = is_array( $result ) ? $result : [];
            directorist_page_cache_record_performance_event(
                ! empty( $result['success'] ) ? 'success' : 'warning',
                'cleanup-' . ( isset( $result['code'] ) ? sanitize_key( (string) $result['code'] ) : 'failed' ),
                [
                    'examined' => isset( $result['examined'] ) ? max( 0, (int) $result['examined'] ) : 0,
                    'removed'  => isset( $result['removed'] ) ? max( 0, (int) $result['removed'] ) : 0,
                ]
            );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_performance_admin' ) ) {
        /** @return Performance_Admin */
        function directorist_page_cache_performance_admin() {
            static $admin;

            if ( ! $admin instanceof Performance_Admin ) {
                $admin = new Performance_Admin();
            }

            return $admin;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_performance_operations' ) ) {
        /** @return Performance_Operations */
        function directorist_page_cache_performance_operations() {
            static $operations;

            if ( ! $operations instanceof Performance_Operations ) {
                $settings   = directorist_page_cache_performance_settings();
                $operations = new Performance_Operations(
                    directorist_page_cache()->get_provider(),
                    $settings,
                    new Performance_Event_Log( $settings )
                );
            }

            return $operations;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_performance_status' ) ) {
        /** @return Performance_Status */
        function directorist_page_cache_performance_status() {
            static $status;

            if ( ! $status instanceof Performance_Status ) {
                $settings = directorist_page_cache_performance_settings();
                $status   = new Performance_Status(
                    directorist_page_cache()->get_provider(),
                    $settings,
                    new Performance_Event_Log( $settings )
                );
            }

            return $status;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_performance_resource_catalog' ) ) {
        /** @return Performance_Resource_Catalog */
        function directorist_page_cache_performance_resource_catalog() {
            static $resources;

            if ( ! $resources instanceof Performance_Resource_Catalog ) {
                $resources = new Performance_Resource_Catalog(
                    directorist_page_cache()->get_provider(),
                    null,
                    null,
                    null,
                    directorist_page_cache_performance_resource_store()
                );
            }

            return $resources;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_performance_resource_store' ) ) {
        /** @return Performance_Resource_Store */
        function directorist_page_cache_performance_resource_store() {
            static $store;

            if ( ! $store instanceof Performance_Resource_Store ) {
                $store = new Performance_Resource_Store();
            }

            return $store;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_performance_resource_reconciler' ) ) {
        /** @return Performance_Resource_Reconciler */
        function directorist_page_cache_performance_resource_reconciler() {
            static $reconciler;

            if ( ! $reconciler instanceof Performance_Resource_Reconciler ) {
                $reconciler = new Performance_Resource_Reconciler(
                    directorist_page_cache_performance_resource_store(),
                    new Performance_Resource_Discovery()
                );
            }

            return $reconciler;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_performance_resource_process' ) ) {
        /** @return Performance_Resource_Process */
        function directorist_page_cache_performance_resource_process() {
            static $process;

            if ( ! $process instanceof Performance_Resource_Process ) {
                $process = new Performance_Resource_Process();
            }

            return $process;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_reconcile_performance_resources' ) ) {
        /** @return array */
        function directorist_page_cache_reconcile_performance_resources( $force = false ) {
            return directorist_page_cache_performance_resource_reconciler()->start( (bool) $force );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_schedule_resource_reconciliation' ) ) {
        /**
         * Coalesce content/settings mutations into one near-term catalog scan.
         *
         * @return bool
         */
        function directorist_page_cache_schedule_resource_reconciliation() {
            if ( ! apply_filters( 'directorist_page_cache_allow_runtime_mutation', true, 'resource_catalog' ) ) {
                return false;
            }

            if ( ! wp_next_scheduled( 'directorist_page_cache_reconcile_resources' ) ) {
                return wp_schedule_single_event( time() + 15, 'directorist_page_cache_reconcile_resources' );
            }

            return true;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_sync_resource_catalog_maintenance' ) ) {
        /** @return bool */
        function directorist_page_cache_sync_resource_catalog_maintenance() {
            if ( ! apply_filters( 'directorist_page_cache_allow_runtime_mutation', true, 'resource_catalog' ) ) {
                return false;
            }

            $store = directorist_page_cache_performance_resource_store();
            $schema_changed = Performance_Resource_Store::VERSION !== get_option( Performance_Resource_Store::VERSION_OPTION, '' );

            if ( ( $schema_changed || ! $store->exists() ) && ! $store->create() ) {
                return false;
            }

            if ( ! wp_next_scheduled( 'directorist_page_cache_hourly_resource_reconciliation' ) ) {
                wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'directorist_page_cache_hourly_resource_reconciliation' );
            }

            if ( ! wp_next_scheduled( 'directorist_page_cache_daily_resource_reconciliation' ) ) {
                wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'directorist_page_cache_daily_resource_reconciliation' );
            }

            $status = $store->status();

            if ( $schema_changed || ! in_array( isset( $status['state'] ) ? $status['state'] : '', [ 'ready', 'building' ], true ) ) {
                directorist_page_cache_schedule_resource_reconciliation();
            }

            return true;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_schedule_resource_post_reconciliation' ) ) {
        /** @return bool */
        function directorist_page_cache_schedule_resource_post_reconciliation( $post_id, $post = null ) {
            $post = $post instanceof WP_Post ? $post : get_post( $post_id );

            if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, [ ATBDP_POST_TYPE, 'page' ], true ) ) {
                return false;
            }

            return directorist_page_cache_schedule_resource_reconciliation();
        }
    }

    if ( ! function_exists( 'directorist_page_cache_remove_deleted_resource' ) ) {
        /** @return array{success:bool,code:string,urls:string[],removed:int} */
        function directorist_page_cache_remove_deleted_resource( $post_id, $post = null ) {
            $post = $post instanceof WP_Post ? $post : get_post( $post_id );

            if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, [ ATBDP_POST_TYPE, 'page' ], true ) ) {
                return [ 'success' => true, 'code' => 'ignored', 'urls' => [], 'removed' => 0 ];
            }

            $result = directorist_page_cache_performance_resource_store()->remove_post_resources( $post_id );
            directorist_page_cache_schedule_resource_reconciliation();

            return $result;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_sync_resource_post_visibility' ) ) {
        /**
         * Remove post-backed resources as soon as they leave public view.
         *
         * @param string                          $new_status New post status.
         * @param string                          $old_status Previous post status.
         * @param WP_Post                         $post Current post.
         * @param Performance_Resource_Store|null $store Optional test boundary.
         * @return array{success:bool,code:string,urls:string[],removed:int}
         */
        function directorist_page_cache_sync_resource_post_visibility( $new_status, $old_status, $post, $store = null ) {
            if ( $new_status === $old_status || ! $post instanceof WP_Post || ! in_array( $post->post_type, [ ATBDP_POST_TYPE, 'page' ], true ) ) {
                return [ 'success' => true, 'code' => 'unchanged', 'urls' => [], 'removed' => 0 ];
            }

            $result = [ 'success' => true, 'code' => 'scheduled', 'urls' => [], 'removed' => 0 ];

            if ( 'publish' !== $new_status ) {
                $store  = $store instanceof Performance_Resource_Store ? $store : directorist_page_cache_performance_resource_store();
                $result = $store->remove_post_resources( $post->ID );
            }

            directorist_page_cache_schedule_resource_reconciliation();

            return $result;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_schedule_resource_term_reconciliation' ) ) {
        /** @return bool */
        function directorist_page_cache_schedule_resource_term_reconciliation( $term_id, $tt_id, $taxonomy ) {
            unset( $term_id, $tt_id );

            if ( ! in_array( $taxonomy, [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS, ATBDP_DIRECTORY_TYPE ], true ) ) {
                return false;
            }

            return directorist_page_cache_schedule_resource_reconciliation();
        }
    }

    if ( ! function_exists( 'directorist_page_cache_performance_listing_index' ) ) {
        /** @return Performance_Listing_Index_Service */
        function directorist_page_cache_performance_listing_index() {
            static $service;

            if ( ! $service instanceof Performance_Listing_Index_Service ) {
                $service = new Performance_Listing_Index_Service();
            }

            return $service;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_performance_job_manager' ) ) {
        /** @return Performance_Job_Manager */
        function directorist_page_cache_performance_job_manager() {
            static $manager;

            if ( ! $manager instanceof Performance_Job_Manager ) {
                $manager = new Performance_Job_Manager(
                    directorist_page_cache_performance_resource_catalog(),
                    directorist_page_cache_performance_operations()
                );
            }

            return $manager;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_automatic_refresh_coordinator' ) ) {
        /** @return Automatic_Refresh_Coordinator|null */
        function directorist_page_cache_automatic_refresh_coordinator() {
            $provider = directorist_page_cache_performance_provider();

            return $provider instanceof Cache_Provider
                ? new Automatic_Refresh_Coordinator( $provider, directorist_page_cache_performance_resource_store() )
                : null;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_refresh_due_entries' ) ) {
        /** @return array */
        function directorist_page_cache_refresh_due_entries() {
            $coordinator = directorist_page_cache_automatic_refresh_coordinator();
            $result      = $coordinator instanceof Automatic_Refresh_Coordinator
                ? $coordinator->run()
                : [ 'success' => false, 'code' => 'provider_unavailable', 'more_due' => false ];
            $provider    = directorist_page_cache_performance_provider();

            if ( directorist_page_cache_is_enabled() && $provider instanceof Cache_Provider && 'directorist-cache' === $provider->get_id() ) {
                $delay = ! empty( $result['more_due'] ) ? 15 : 300;

                if ( ! wp_next_scheduled( 'directorist_page_cache_refresh_due_entries' ) ) {
                    wp_schedule_single_event( time() + $delay, 'directorist_page_cache_refresh_due_entries' );
                }
            }

            return $result;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_handle_refresh_handoff' ) ) {
        /**
         * Validate one early stale-response handoff and queue its canonical URL.
         *
         * @param array $input Request values.
         * @param array $runtime Testable provider, queue, and storage boundaries.
         * @return array
         */
        function directorist_page_cache_handle_refresh_handoff( array $input, array $runtime = [] ) {
            $secret    = directorist_page_cache_refresh_token();
            $signature = isset( $input['signature'] ) && is_scalar( $input['signature'] ) ? strtolower( (string) $input['signature'] ) : '';
            $timestamp = isset( $input['timestamp'] ) && is_scalar( $input['timestamp'] ) && ctype_digit( (string) $input['timestamp'] ) ? (int) $input['timestamp'] : 0;
            $url       = isset( $input['url'] ) && is_scalar( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '';
            $hash      = isset( $input['hash'] ) && is_scalar( $input['hash'] ) ? strtolower( (string) $input['hash'] ) : '';
            $variation_source = isset( $input['variation'] ) && is_scalar( $input['variation'] ) && 2048 >= strlen( (string) $input['variation'] ) ? (string) $input['variation'] : '[]';
            $variation = json_decode( $variation_source, true );
            $variation = is_array( $variation ) ? $variation : [];
            $expected  = hash_hmac( 'sha256', $timestamp . "\n" . $hash . "\n" . $url, $secret );

            if ( '' === $secret || 60 < abs( time() - $timestamp ) || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) || ! hash_equals( $expected, $signature ) ) {
                return [ 'success' => false, 'code' => 'invalid_refresh_token' ];
            }

            $registry = new Directorist\Cache\Warm_URL_Registry( home_url( '/' ), 1 );
            $registry->add( [ $url ], 'soft-expiry' );
            $urls = $registry->all();
            $key  = ( new Directorist\Cache\Built_In\Request_Key() )->from_url( $url, $variation );

            if ( 1 !== count( $urls ) || empty( $key['success'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || ! hash_equals( $key['hash'], $hash ) ) {
                return [ 'success' => false, 'code' => 'invalid_refresh_url' ];
            }

            $provider = isset( $runtime['provider'] ) ? $runtime['provider'] : directorist_page_cache_performance_provider();

            if ( ! $provider instanceof Cache_Provider || 'directorist-cache' !== $provider->get_id() ) {
                return [ 'success' => false, 'code' => 'built_in_provider_inactive' ];
            }

            $queue  = isset( $runtime['queue'] ) && is_callable( $runtime['queue'] ) ? $runtime['queue'] : 'directorist_page_cache_queue_refresh_request';
            $result = call_user_func( $queue, $urls[0], $variation, $key['hash'] );
            $result = is_array( $result ) ? $result : [ 'success' => false, 'code' => 'invalid_queue_result' ];

            if ( empty( $result['success'] ) ) {
                $storage = isset( $runtime['storage'] ) ? $runtime['storage'] : new Built_In_Cache_Storage( WP_CONTENT_DIR . '/cache/directorist-page-cache' );

                if ( is_object( $storage ) && is_callable( [ $storage, 'release_refresh_claim' ] ) ) {
                    $storage->release_refresh_claim( $key );
                }

                return [ 'success' => false, 'code' => isset( $result['code'] ) ? $result['code'] : 'preload_queue_failed' ];
            }

            $store = isset( $runtime['resource_store'] ) ? $runtime['resource_store'] : directorist_page_cache_performance_resource_store();

            if ( is_object( $store ) && is_callable( [ $store, 'mark_refresh_requested' ] ) ) {
                $store->mark_refresh_requested( $urls, time() );
            }

            return [
                'success' => true,
                'code'    => isset( $result['code'] ) ? $result['code'] : 'queued',
                'queued'  => isset( $result['queued'] ) ? max( 0, (int) $result['queued'] ) : 0,
            ];
        }
    }

    if ( ! function_exists( 'directorist_page_cache_receive_refresh_handoff' ) ) {
        /** @return void */
        function directorist_page_cache_receive_refresh_handoff() {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated by the private refresh token.
            $result = directorist_page_cache_handle_refresh_handoff( wp_unslash( $_POST ) );
            wp_send_json( $result, ! empty( $result['success'] ) ? 202 : 403 );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_performance_job_process' ) ) {
        /** @return Performance_Job_Process */
        function directorist_page_cache_performance_job_process() {
            static $process;

            if ( ! $process instanceof Performance_Job_Process ) {
                $process = new Performance_Job_Process();
            }

            return $process;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_monitor_performance_job' ) ) {
        /**
         * Reconcile warm-job state after its background queue becomes idle.
         *
         * @param string $job_id Performance job identity.
         * @return array
         */
        function directorist_page_cache_monitor_performance_job( $job_id ) {
            return directorist_page_cache_performance_job_manager()->monitor( $job_id );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_performance_rest_controller' ) ) {
        /** @return Performance_REST_Controller */
        function directorist_page_cache_performance_rest_controller() {
            static $controller;

            if ( ! $controller instanceof Performance_REST_Controller ) {
                $controller = new Performance_REST_Controller(
                    directorist_page_cache_performance_status(),
                    directorist_page_cache_performance_resource_catalog(),
                    directorist_page_cache_performance_settings(),
                    directorist_page_cache_performance_operations(),
                    directorist_page_cache_performance_listing_index(),
                    directorist_page_cache_performance_job_manager()
                );
            }

            return $controller;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_register_performance_rest_routes' ) ) {
        /** @return void */
        function directorist_page_cache_register_performance_rest_routes() {
            directorist_page_cache_performance_rest_controller()->register_routes();
        }
    }

    if ( ! function_exists( 'directorist_page_cache_boot_performance_admin' ) ) {
        /** @return void */
        function directorist_page_cache_boot_performance_admin() {
            directorist_page_cache_performance_admin()->register();
        }
    }

    if ( ! function_exists( 'directorist_page_cache_prepare_external_activation' ) ) {
        /**
         * Release the owned drop-in before a supported external activation hook.
         *
         * @param string $plugin Plugin basename.
         * @param bool   $network_wide Network activation state.
         * @return array
         */
        function directorist_page_cache_prepare_external_activation( $plugin, $network_wide = false ) {
            unset( $network_wide );

            $result = directorist_page_cache_builtin_lifecycle()->prepare_external_activation( $plugin );

            if ( 'external_activation_prepared' === ( isset( $result['code'] ) ? $result['code'] : '' ) ) {
                directorist_page_cache_sync_builtin_maintenance( [ 'success' => true, 'state' => 'external' ] );
            }

            return $result;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_schedule_lifecycle_reconciliation' ) ) {
        /**
         * Schedule one bounded reconciliation after plugin state is fully persisted.
         *
         * @param mixed $subject Plugin basename or upgrader instance.
         * @param mixed $context Lifecycle context.
         * @return bool
         */
        function directorist_page_cache_schedule_lifecycle_reconciliation( $subject = null, $context = null ) {
            $plugin  = is_string( $subject ) ? sanitize_text_field( wp_unslash( $subject ) ) : '';
            $reason  = is_array( $context ) ? 'plugin_update' : 'plugin_lifecycle';
            $pending = [
                'reason'     => $reason,
                'plugin'     => substr( $plugin, 0, 255 ),
                'created_at' => time(),
            ];

            update_site_option( 'directorist_page_cache_lifecycle_pending', $pending );

            if ( ! wp_next_scheduled( 'directorist_page_cache_reconcile_lifecycle' ) ) {
                wp_schedule_single_event( time() + 5, 'directorist_page_cache_reconcile_lifecycle' );
            }

            return true;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_reconcile_lifecycle' ) ) {
        /**
         * Decide whether a stored lifecycle result still matches the active runtime.
         *
         * @param array $state Stored lifecycle state.
         * @param array $resolvers Testable clock and runtime boundaries.
         * @return bool
         */
        function directorist_page_cache_lifecycle_state_is_current( array $state, array $resolvers = [] ) {
            $resolvers = array_merge(
                [
                    'clock'           => 'time',
                    'enabled'         => 'directorist_page_cache_is_enabled',
                    'runtime_healthy' => 'directorist_page_cache_builtin_runtime_is_healthy',
                    'external_probe'  => 'directorist_page_cache_builtin_external_probe',
                    'owner'           => static function () {
                        return ( new Dropin_Owner_Detector( null, false ) )->detect();
                    },
                ],
                $resolvers
            );

            foreach ( $resolvers as $resolver ) {
                if ( ! is_callable( $resolver ) ) {
                    return false;
                }
            }

            $now        = (int) call_user_func( $resolvers['clock'] );
            $updated_at = isset( $state['updated_at'] ) ? (int) $state['updated_at'] : 0;
            $lifecycle  = isset( $state['state'] ) ? sanitize_key( (string) $state['state'] ) : '';
            $enabled    = (bool) call_user_func( $resolvers['enabled'] );

            if ( 0 >= $updated_at || DAY_IN_SECONDS <= $now - $updated_at ) {
                return false;
            }

            if ( 'disabled' === $lifecycle ) {
                return ! $enabled;
            }

            if ( ! $enabled ) {
                return false;
            }

            $external = call_user_func( $resolvers['external_probe'] );
            $external = is_array( $external ) ? $external : [];
            $code     = isset( $external['code'] ) ? sanitize_key( (string) $external['code'] ) : '';
            $provider = isset( $external['provider'] ) ? sanitize_key( (string) $external['provider'] ) : '';

            if ( 'built_in' === $lifecycle ) {
                return 'no_available_provider' === $code && (bool) call_user_func( $resolvers['runtime_healthy'] );
            }

            if ( 'external' === $lifecycle ) {
                $stored_provider = isset( $state['provider'] ) ? sanitize_key( (string) $state['provider'] ) : '';
                $owner           = sanitize_key( (string) call_user_func( $resolvers['owner'] ) );

                return 'selected' === $code && '' !== $stored_provider && $stored_provider === $provider && $stored_provider === $owner;
            }

            return false;
        }

        /**
         * Reconcile pending/admin/background cache lifecycle state.
         *
         * @param string $reason Explicit transition reason.
         * @return array|null
         */
        function directorist_page_cache_reconcile_lifecycle( $reason = '' ) {
            $pending = get_site_option( 'directorist_page_cache_lifecycle_pending', [] );
            $state   = get_site_option( Built_In_Lifecycle::STATE_OPTION, [] );
            $reason  = sanitize_key( (string) $reason );

            if ( '' === $reason && is_array( $pending ) && ! empty( $pending['reason'] ) ) {
                $reason = sanitize_key( (string) $pending['reason'] );
            }

            if ( '' === $reason ) {
                if ( is_array( $state ) && directorist_page_cache_lifecycle_state_is_current( $state ) ) {
                    directorist_page_cache_sync_builtin_maintenance( $state );
                    directorist_page_cache_sync_external_compatibility( $state );

                    return null;
                }

                $reason = 'health';
            }

            delete_site_option( 'directorist_page_cache_lifecycle_pending' );
            Dropin_Owner_Detector::invalidate_persistent_cache();

            $result = directorist_page_cache_builtin_lifecycle()->reconcile( $reason );
            directorist_page_cache_sync_builtin_maintenance( $result );
            directorist_page_cache_sync_external_compatibility( $result );

            if ( ! is_array( $state ) || ( isset( $state['state'], $state['code'] ) && ( $state['state'] !== $result['state'] || $state['code'] !== $result['code'] ) ) ) {
                directorist_page_cache_record_performance_event(
                    ! empty( $result['success'] ) ? 'success' : 'warning',
                    'lifecycle-' . sanitize_key( (string) $result['state'] ),
                    [ 'code' => $result['code'], 'provider' => $result['provider'] ]
                );
            }

            return $result;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_activate_builtin_runtime' ) ) {
        /**
         * Reconcile built-in ownership during Directorist activation.
         *
         * @param bool $network_wide Network activation state.
         * @return array
         */
        function directorist_page_cache_activate_builtin_runtime( $network_wide = false ) {
            Dropin_Owner_Detector::invalidate_persistent_cache();

            $result = directorist_page_cache_builtin_lifecycle()->reconcile(
                'activation',
                [
                    'is_multisite' => is_multisite(),
                    'network_wide' => (bool) $network_wide,
                ]
            );

            directorist_page_cache_sync_builtin_maintenance( $result );
            directorist_page_cache_sync_external_compatibility( $result );

            return $result;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_deactivate_builtin_runtime' ) ) {
        /** @return array */
        function directorist_page_cache_deactivate_builtin_runtime() {
            wp_clear_scheduled_hook( 'directorist_page_cache_reconcile_lifecycle' );
            wp_clear_scheduled_hook( 'directorist_page_cache_daily_cleanup' );
            wp_clear_scheduled_hook( Performance_Job_Manager::MONITOR_HOOK );
            delete_site_option( 'directorist_page_cache_lifecycle_pending' );
            directorist_page_cache_performance_job_manager()->cancel_current();
            directorist_page_cache_warm_background_process()->reset();
            directorist_page_cache_performance_job_process()->reset();
            directorist_page_cache_builtin_cleanup_process()->reset();

            if ( ! empty( WP_Super_Cache_Compatibility::current() ) && apply_filters( 'directorist_page_cache_allow_runtime_mutation', true, 'wpsc_deactivate' ) ) {
                directorist_page_cache_wp_super_cache_compatibility()->deactivate();
            }

            foreach ( [
                'cache-enabler/cache-enabler.php',
                'wp-fastest-cache/wpFastestCache.php',
                'litespeed-cache/litespeed-cache.php',
            ] as $plugin ) {
                directorist_page_cache_cleanup_external_compatibility( $plugin );
            }

            return directorist_page_cache_builtin_lifecycle()->deactivate_core();
        }
    }

    if ( ! function_exists( 'directorist_page_cache_cleanup_external_compatibility' ) ) {
        /**
         * Remove Directorist-owned external-provider compatibility state.
         *
         * @param string        $plugin Plugin basename.
         * @param bool          $network_wide Network deactivation state.
         * @param callable|null $deactivator Explicit test boundary.
         * @return array
         */
        function directorist_page_cache_cleanup_external_compatibility( $plugin, $network_wide = false, $deactivator = null ) {
            unset( $network_wide );
            $plugin = (string) $plugin;
            $map    = [
                'wp-super-cache/wp-cache.php'         => [ 'current' => [ 'Directorist\\Cache\\WP_Super_Cache_Compatibility', 'current' ], 'mutation' => 'wpsc_deactivate', 'service' => 'directorist_page_cache_wp_super_cache_compatibility' ],
                'cache-enabler/cache-enabler.php'     => [ 'current' => [ 'Directorist\\Cache\\Cache_Enabler_Compatibility', 'current' ], 'mutation' => 'cache_enabler_deactivate', 'service' => 'directorist_page_cache_cache_enabler_compatibility' ],
                'wp-fastest-cache/wpFastestCache.php' => [ 'current' => [ 'Directorist\\Cache\\WP_Fastest_Cache_Compatibility', 'current' ], 'mutation' => 'wpfc_deactivate', 'service' => 'directorist_page_cache_wp_fastest_cache_compatibility' ],
                'litespeed-cache/litespeed-cache.php' => [ 'current' => [ 'Directorist\\Cache\\LiteSpeed_Compatibility', 'current' ], 'mutation' => 'litespeed_deactivate', 'service' => 'directorist_page_cache_litespeed_compatibility' ],
            ];

            if ( ! isset( $map[ $plugin ] ) ) {
                return [ 'success' => true, 'code' => 'compatibility_not_required' ];
            }

            if ( null === $deactivator ) {
                $entry = $map[ $plugin ];

                if ( empty( call_user_func( $entry['current'] ) ) ) {
                    return [ 'success' => true, 'code' => 'configuration_absent' ];
                }

                if ( ! apply_filters( 'directorist_page_cache_allow_runtime_mutation', true, $entry['mutation'] ) ) {
                    return [ 'success' => true, 'code' => 'runtime_mutation_disabled' ];
                }

                $service     = call_user_func( $entry['service'] );
                $deactivator = [ $service, 'deactivate' ];
            }

            if ( ! is_callable( $deactivator ) ) {
                return [ 'success' => false, 'code' => 'configuration_cleanup_unavailable' ];
            }

            $result = call_user_func( $deactivator );

            return is_array( $result ) ? $result : [ 'success' => false, 'code' => 'configuration_cleanup_failed' ];
        }
    }

    if ( ! function_exists( 'directorist_page_cache_cleanup_wp_super_cache_before_deactivation' ) ) {
        /**
         * Clear Directorist policy before WP Super Cache removes its writable cache path.
         *
         * @param bool $network_wide Network deactivation state.
         * @return array
         */
        function directorist_page_cache_cleanup_wp_super_cache_before_deactivation( $network_wide = false ) {
            return directorist_page_cache_cleanup_external_compatibility( 'wp-super-cache/wp-cache.php', $network_wide );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_cleanup_cache_enabler_before_deactivation' ) ) {
        /** @return array */
        function directorist_page_cache_cleanup_cache_enabler_before_deactivation( $network_wide = false ) {
            return directorist_page_cache_cleanup_external_compatibility( 'cache-enabler/cache-enabler.php', $network_wide );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_cleanup_wp_fastest_cache_before_deactivation' ) ) {
        /** @return array */
        function directorist_page_cache_cleanup_wp_fastest_cache_before_deactivation( $network_wide = false ) {
            return directorist_page_cache_cleanup_external_compatibility( 'wp-fastest-cache/wpFastestCache.php', $network_wide );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_cleanup_litespeed_before_deactivation' ) ) {
        /** @return array */
        function directorist_page_cache_cleanup_litespeed_before_deactivation( $network_wide = false ) {
            return directorist_page_cache_cleanup_external_compatibility( 'litespeed-cache/litespeed-cache.php', $network_wide );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_sync_external_compatibility' ) ) {
        /**
         * Synchronize selected external-provider policy outside public requests.
         *
         * @param array $state Lifecycle state.
         * @return array
         */
        function directorist_page_cache_sync_external_compatibility( array $state ) {
            $external    = 'external' === ( isset( $state['state'] ) ? $state['state'] : '' );
            $provider_id = $external && isset( $state['provider'] ) ? (string) $state['provider'] : '';
            $cleanup     = [ 'success' => true, 'code' => 'configuration_absent' ];

            $providers = [
                'wp-super-cache'   => [ 'plugin' => 'wp-super-cache/wp-cache.php', 'service' => 'directorist_page_cache_wp_super_cache_compatibility', 'mutation' => 'wpsc_sync' ],
                'cache-enabler'    => [ 'plugin' => 'cache-enabler/cache-enabler.php', 'service' => 'directorist_page_cache_cache_enabler_compatibility', 'mutation' => 'cache_enabler_sync' ],
                'wp-fastest-cache' => [ 'plugin' => 'wp-fastest-cache/wpFastestCache.php', 'service' => 'directorist_page_cache_wp_fastest_cache_compatibility', 'mutation' => 'wpfc_sync' ],
                'litespeed-cache'  => [ 'plugin' => 'litespeed-cache/litespeed-cache.php', 'service' => 'directorist_page_cache_litespeed_compatibility', 'mutation' => 'litespeed_sync' ],
            ];

            foreach ( $providers as $id => $compatibility ) {
                if ( $id === $provider_id ) {
                    continue;
                }

                $cleanup = directorist_page_cache_cleanup_external_compatibility( $compatibility['plugin'] );

                if ( empty( $cleanup['success'] ) ) {
                    return $cleanup;
                }
            }

            if ( ! isset( $providers[ $provider_id ] ) ) {
                return $cleanup;
            }

            $mutation = $providers[ $provider_id ]['mutation'];

            if ( ! apply_filters( 'directorist_page_cache_allow_runtime_mutation', true, $mutation ) ) {
                return [ 'success' => true, 'code' => 'runtime_mutation_disabled' ];
            }

            $selection = directorist_page_cache_provider_registry()->select();
            $provider  = $selection->get_provider();

            if ( ! $provider instanceof Cache_Provider || $provider_id !== $provider->get_id() ) {
                return [ 'success' => false, 'code' => 'provider_not_selected' ];
            }

            $service = call_user_func( $providers[ $provider_id ]['service'] );
            $result  = $service->activate( $provider );
            directorist_page_cache_record_performance_event(
                ! empty( $result['success'] ) ? 'success' : 'warning',
                sanitize_key( $provider_id ) . '-' . ( isset( $result['code'] ) ? sanitize_key( (string) $result['code'] ) : 'compatibility-failed' )
            );

            return $result;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_handle_enabled_changed' ) ) {
        /** @return array|null */
        function directorist_page_cache_handle_enabled_changed() {
            return directorist_page_cache_reconcile_lifecycle( 'setting' );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_performance_provider' ) ) {
        /** @return Cache_Provider|null */
        function directorist_page_cache_performance_provider() {
            $provider = directorist_page_cache()->get_provider();

            try {
                if ( $provider instanceof Cache_Provider && $provider->is_available() ) {
                    return $provider;
                }
            } catch ( \Throwable $exception ) {
                unset( $exception );
            }

            $selection = directorist_page_cache_provider_registry()->select();
            $provider  = $selection->get_provider();

            if ( ! $provider instanceof Cache_Provider && 'no_available_provider' === $selection->get_code() && directorist_page_cache_builtin_runtime_is_healthy() ) {
                directorist_page_cache_provider_registry()->register( directorist_page_cache_builtin_provider(), 10 );
                $provider = directorist_page_cache_provider_registry()->select( false )->get_provider();
            }

            return $provider instanceof Cache_Provider ? $provider : null;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_handle_performance_settings_changed' ) ) {
        /**
         * Invalidate old policy output and queue bounded warming after lifecycle synchronization.
         *
         * @param array $current Current normalized settings.
         * @param array $previous Previous normalized settings.
         * @return array
         */
        function directorist_page_cache_handle_performance_settings_changed( $current, $previous ) {
            if ( ! is_array( $current ) || ! is_array( $previous ) ) {
                return [ 'success' => false, 'code' => 'invalid-settings' ];
            }

            if ( ! apply_filters( 'directorist_page_cache_allow_runtime_mutation', true, 'performance_settings' ) ) {
                return [ 'success' => true, 'code' => 'runtime-mutation-disabled' ];
            }

            $provider = directorist_page_cache_performance_provider();

            if ( ! $provider instanceof Cache_Provider ) {
                return [ 'success' => false, 'code' => 'provider-unavailable' ];
            }

            $result = ( new Performance_Settings_Automation( $provider ) )->apply( $current, $previous );

            if ( isset( $result['invalidation'], $result['plan'] ) ) {
                directorist_page_cache_record_invalidation_outcome( $result['invalidation'], $result['plan'] );
            }

            return $result;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_verify_and_repair' ) ) {
        /** @return mixed */
        function directorist_page_cache_verify_and_repair( $result, $action ) {
            if ( 'verify' !== $action ) {
                return $result;
            }

            if ( ! apply_filters( 'directorist_page_cache_allow_runtime_mutation', true, 'verify' ) ) {
                return $result;
            }

            return directorist_page_cache_reconcile_lifecycle( 'verify' );
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
                || directorist_page_cache_builtin_runtime_is_healthy()
                || ! empty( $GLOBALS['directorist_page_cache_custom_provider_registered'] )
                || has_filter( 'directorist_page_cache_providers' );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_is_internal_control_request' ) ) {
        /**
         * Return whether the current request is an authenticated cache worker controller.
         *
         * @return bool
         */
        function directorist_page_cache_is_internal_control_request() {
            $doing_ajax = function_exists( 'wp_doing_ajax' )
                ? wp_doing_ajax()
                : defined( 'DOING_AJAX' ) && DOING_AJAX;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The guard validates its worker nonce before returning true.
            $request = is_array( $_REQUEST ) ? wp_unslash( $_REQUEST ) : [];

            return ( new Internal_Request_Guard() )->is_control_request( $request, $doing_ajax, get_current_blog_id() );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_register_warm_worker' ) ) {
        /**
         * Register the signed worker controller independently of mutation tracking.
         *
         * @param Cache_Provider $provider Selected provider.
         * @return bool
         */
        function directorist_page_cache_register_warm_worker( Cache_Provider $provider ) {
            if ( ! directorist_page_cache_is_enabled() || ! $provider->supports( Directorist\Cache\Provider_Capabilities::WARM_URLS ) ) {
                return false;
            }

            $worker     = directorist_page_cache_warm_background_process();
            $identifier = 'wp_' . get_current_blog_id() . '_' . Warm_Background_Process::ACTION;

            if ( false === has_action( 'wp_ajax_' . $identifier, [ $worker, 'maybe_handle' ] ) ) {
                add_action( 'wp_ajax_' . $identifier, [ $worker, 'maybe_handle' ] );
            }

            if ( false === has_action( 'wp_ajax_nopriv_' . $identifier, [ $worker, 'maybe_handle' ] ) ) {
                add_action( 'wp_ajax_nopriv_' . $identifier, [ $worker, 'maybe_handle' ] );
            }

            return true;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_verify_warmed_url' ) ) {
        /**
         * Verify that a successful anonymous request produced a current cache entry.
         *
         * External providers do not expose a portable exact-entry API, so their
         * successful HTTP response is the strongest provider-neutral signal.
         *
         * @param string $url Warmed public URL.
         * @param mixed  $response HTTP response, unused by built-in inspection.
         * @param array  $item Worker item carrying exact cache variation.
         * @return array
         */
        function directorist_page_cache_verify_warmed_url( $url, $response = null, $item = [] ) {
            unset( $response );
            $provider = directorist_page_cache_performance_provider();

            if ( ! $provider instanceof Cache_Provider ) {
                return [ 'success' => false, 'code' => 'provider-unavailable' ];
            }

            if ( 'directorist-cache' !== $provider->get_id() ) {
                return [ 'success' => true, 'code' => 'provider-http-current' ];
            }

            $variation = is_array( $item ) && isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : [];
            $key       = ( new Directorist\Cache\Built_In\Request_Key() )->from_url( esc_url_raw( (string) $url ), $variation );

            if ( empty( $key['success'] ) || ( ! empty( $item['cache_hash'] ) && ! hash_equals( $key['hash'], (string) $item['cache_hash'] ) ) ) {
                return [ 'success' => false, 'code' => 'invalid-cache-key' ];
            }

            $state = ( new Built_In_Cache_Storage( WP_CONTENT_DIR . '/cache/directorist-page-cache' ) )->inspect( $key );
            $code  = isset( $state['state'] ) ? sanitize_key( (string) $state['state'] ) : 'uncached';

            if ( 'current' === $code ) {
                return [ 'success' => true, 'code' => 'current' ];
            }

            if ( 'invalidated' === $code ) {
                $code = 'mutation-during-warm';
            }

            return [ 'success' => false, 'code' => $code ];
        }
    }

    if ( ! function_exists( 'directorist_page_cache_record_performance_warm_result' ) ) {
        /** @return array */
        function directorist_page_cache_record_performance_warm_result( $job_id, $url, $result ) {
            $result = is_array( $result ) ? $result : [ 'success' => false, 'code' => 'invalid-worker-result' ];

            return directorist_page_cache_performance_job_manager()->record_warm_result(
                $job_id,
                $url,
                $result
            );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_record_resource_warm_result' ) ) {
        /** @return bool */
        function directorist_page_cache_record_resource_warm_result( $url, $result ) {
            $result = is_array( $result ) ? $result : [];

            return directorist_page_cache_performance_resource_store()->update_cache_state(
                $url,
                [
                    'state'                => ! empty( $result['success'] ) ? 'current' : 'failed',
                    'failure_code'         => empty( $result['success'] ) && isset( $result['code'] ) ? $result['code'] : '',
                    'refresh_requested_at' => 0,
                ]
            );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_record_resource_entry' ) ) {
        /** @return bool */
        function directorist_page_cache_record_resource_entry( $metadata ) {
            if ( ! is_array( $metadata ) || empty( $metadata['canonical_url'] ) ) {
                return false;
            }

            return directorist_page_cache_performance_resource_store()->update_cache_state(
                $metadata['canonical_url'],
                [
                    'state'      => 'current',
                    'created_at' => isset( $metadata['created_at'] ) ? $metadata['created_at'] : 0,
                    'expires_at' => isset( $metadata['expires_at'] ) ? $metadata['expires_at'] : 0,
                    'stale_until' => isset( $metadata['stale_until'] ) ? $metadata['stale_until'] : 0,
                    'refresh_requested_at' => 0,
                ]
            );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_record_resource_invalidation' ) ) {
        /** @return bool */
        function directorist_page_cache_record_resource_invalidation( $result, $plan ) {
            if ( ! is_array( $result ) || empty( $result['success'] ) || ! is_array( $plan ) ) {
                return false;
            }

            $store = directorist_page_cache_performance_resource_store();

            foreach ( isset( $plan['urls'] ) && is_array( $plan['urls'] ) ? $plan['urls'] : [] as $url ) {
                $store->update_cache_state( $url, [ 'state' => 'invalidated' ] );
            }

            if ( ! empty( $plan['conservative'] ) || ! empty( $plan['generations'] ) ) {
                $store->mark_all_cache_state( 'invalidated' );
            }

            return true;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_record_resource_purge' ) ) {
        /** @return bool */
        function directorist_page_cache_record_resource_purge( $result, $plan ) {
            if ( ! is_array( $result ) || empty( $result['success'] ) || ! is_array( $plan ) ) {
                return false;
            }

            $store = directorist_page_cache_performance_resource_store();

            foreach ( isset( $plan['urls'] ) && is_array( $plan['urls'] ) ? $plan['urls'] : [] as $url ) {
                $store->update_cache_state(
                    $url,
                    [
                        'state'                => 'uncached',
                        'created_at'           => 0,
                        'expires_at'           => 0,
                        'stale_until'          => 0,
                        'refresh_requested_at' => 0,
                    ]
                );
            }

            if ( ! empty( $plan['conservative'] ) || ! empty( $plan['generations'] ) ) {
                $store->mark_all_cache_state( 'uncached' );
            }

            return true;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_register_builtin_cleanup_worker' ) ) {
        /**
         * Register the signed built-in cleanup controller only for its internal request.
         *
         * @return bool
         */
        function directorist_page_cache_register_builtin_cleanup_worker() {
            $worker     = directorist_page_cache_builtin_cleanup_process();
            $identifier = 'wp_' . get_current_blog_id() . '_' . Built_In_Cleanup_Background_Process::ACTION;

            if ( false === has_action( 'wp_ajax_' . $identifier, [ $worker, 'maybe_handle' ] ) ) {
                add_action( 'wp_ajax_' . $identifier, [ $worker, 'maybe_handle' ] );
            }

            if ( false === has_action( 'wp_ajax_nopriv_' . $identifier, [ $worker, 'maybe_handle' ] ) ) {
                add_action( 'wp_ajax_nopriv_' . $identifier, [ $worker, 'maybe_handle' ] );
            }

            return true;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_register_performance_job_worker' ) ) {
        /**
         * Register the signed Performance job controller only for its internal request.
         *
         * @param Cache_Provider|null $provider Request-scoped selected provider.
         * @return bool
         */
        function directorist_page_cache_register_performance_job_worker( Cache_Provider $provider = null ) {
            $worker = directorist_page_cache_performance_job_process();

            if ( $provider instanceof Cache_Provider ) {
                $settings = directorist_page_cache_performance_settings();
                $manager  = new Performance_Job_Manager(
                    new Performance_Resource_Catalog( $provider, null, null, null, directorist_page_cache_performance_resource_store() ),
                    new Performance_Operations( $provider, $settings, new Performance_Event_Log( $settings ) )
                );
                $worker->set_manager(
                    static function () use ( $manager ) {
                        return $manager;
                    }
                );
            }

            $identifier = 'wp_' . get_current_blog_id() . '_' . Performance_Job_Process::ACTION;

            if ( false === has_action( 'wp_ajax_' . $identifier, [ $worker, 'maybe_handle' ] ) ) {
                add_action( 'wp_ajax_' . $identifier, [ $worker, 'maybe_handle' ] );
            }

            if ( false === has_action( 'wp_ajax_nopriv_' . $identifier, [ $worker, 'maybe_handle' ] ) ) {
                add_action( 'wp_ajax_nopriv_' . $identifier, [ $worker, 'maybe_handle' ] );
            }

            return true;
        }
    }

    if ( ! function_exists( 'directorist_page_cache_register_performance_resource_worker' ) ) {
        /** @return bool */
        function directorist_page_cache_register_performance_resource_worker() {
            $worker     = directorist_page_cache_performance_resource_process();
            $identifier = 'wp_' . get_current_blog_id() . '_' . Performance_Resource_Process::ACTION;

            if ( false === has_action( 'wp_ajax_' . $identifier, [ $worker, 'maybe_handle' ] ) ) {
                add_action( 'wp_ajax_' . $identifier, [ $worker, 'maybe_handle' ] );
            }

            if ( false === has_action( 'wp_ajax_nopriv_' . $identifier, [ $worker, 'maybe_handle' ] ) ) {
                add_action( 'wp_ajax_nopriv_' . $identifier, [ $worker, 'maybe_handle' ] );
            }

            return true;
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

            if ( ! $provider instanceof Cache_Provider && 'no_available_provider' === $selection->get_code() && directorist_page_cache_builtin_runtime_is_healthy() ) {
                directorist_page_cache_provider_registry()->register( directorist_page_cache_builtin_provider(), 10 );
                $selection = directorist_page_cache_provider_registry()->select( false );
                $provider  = $selection->get_provider();
            }

            if ( directorist_page_cache_is_enabled() && $provider instanceof Cache_Provider ) {
                $warm_worker_registered = directorist_page_cache_register_warm_worker( $provider );
                $internal_request       = directorist_page_cache_is_internal_control_request();

                if ( $internal_request ) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The internal request guard has already validated the worker nonce.
                    $control_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
                    $cleanup_action = 'wp_' . get_current_blog_id() . '_' . Built_In_Cleanup_Background_Process::ACTION;
                    $job_action     = 'wp_' . get_current_blog_id() . '_' . Performance_Job_Process::ACTION;
                    $resource_action = 'wp_' . get_current_blog_id() . '_' . Performance_Resource_Process::ACTION;

                    if ( 'directorist-cache' === $provider->get_id() && $cleanup_action === $control_action ) {
                        directorist_page_cache_register_builtin_cleanup_worker();
                    }

                    if ( $job_action === $control_action ) {
                        directorist_page_cache_register_performance_job_worker( $provider );
                    }

                    if ( $resource_action === $control_action ) {
                        directorist_page_cache_register_performance_resource_worker();
                    }

                    do_action( 'directorist_page_cache_provider_selected', $selection );

                    return;
                }

                directorist_page_cache()->enable_invalidation( $provider );

                if ( 'wp-super-cache' === $provider->get_id() ) {
                    directorist_page_cache_wp_super_cache_compatibility()->register();
                }

                if ( 'litespeed-cache' === $provider->get_id() ) {
                    directorist_page_cache_litespeed_compatibility()->register();
                }

                if ( 'directorist-cache' === $provider->get_id() ) {
                    directorist_page_cache_builtin_engine_instance()->register_wordpress_hooks();
                }

                if ( $warm_worker_registered ) {
                    ( new Automatic_Warmer( $provider ) )->register();
                }
            }

            do_action( 'directorist_page_cache_provider_selected', $selection );
        }
    }

    if ( ! function_exists( 'directorist_page_cache_boot_resource_worker' ) ) {
        /** @return bool */
        function directorist_page_cache_boot_resource_worker() {
            if ( ! directorist_page_cache_is_internal_control_request() ) {
                return false;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Internal_Request_Guard verified this controller nonce.
            $action   = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
            $expected = 'wp_' . get_current_blog_id() . '_' . Performance_Resource_Process::ACTION;

            return $expected === $action && directorist_page_cache_register_performance_resource_worker();
        }
    }

    directorist_page_cache_boot_cron_workers();

    add_action( 'plugins_loaded', 'directorist_page_cache_boot_provider', PHP_INT_MAX );
    add_action( 'plugins_loaded', 'directorist_page_cache_boot_resource_worker', PHP_INT_MAX );
    add_action( 'rest_api_init', 'directorist_page_cache_register_performance_rest_routes' );
    add_action( 'directorist_page_cache_eligibility_decided', 'directorist_page_cache_sample_performance_event', 10, 2 );
    add_action( 'activate_plugin', 'directorist_page_cache_prepare_external_activation', 1, 2 );
    add_action( 'activated_plugin', 'directorist_page_cache_schedule_lifecycle_reconciliation', 20, 2 );
    add_action( 'deactivate_wp-super-cache/wp-cache.php', 'directorist_page_cache_cleanup_wp_super_cache_before_deactivation', 1, 1 );
    add_action( 'deactivate_cache-enabler/cache-enabler.php', 'directorist_page_cache_cleanup_cache_enabler_before_deactivation', 1, 1 );
    add_action( 'deactivate_wp-fastest-cache/wpFastestCache.php', 'directorist_page_cache_cleanup_wp_fastest_cache_before_deactivation', 1, 1 );
    add_action( 'deactivate_litespeed-cache/litespeed-cache.php', 'directorist_page_cache_cleanup_litespeed_before_deactivation', 1, 1 );
    add_action( 'deactivated_plugin', 'directorist_page_cache_cleanup_external_compatibility', 5, 2 );
    add_action( 'deactivated_plugin', 'directorist_page_cache_schedule_lifecycle_reconciliation', 20, 2 );
    add_action( 'deleted_plugin', 'directorist_page_cache_schedule_lifecycle_reconciliation', 20, 2 );
    add_action( 'upgrader_process_complete', 'directorist_page_cache_schedule_lifecycle_reconciliation', 20, 2 );
    add_action( 'directorist_updated', 'directorist_page_cache_reconcile_lifecycle', 20 );
    add_action( 'admin_init', 'directorist_page_cache_reconcile_lifecycle', 20 );
    add_action( 'directorist_page_cache_reconcile_lifecycle', 'directorist_page_cache_reconcile_lifecycle', 10 );
    add_action( 'directorist_page_cache_daily_cleanup', 'directorist_page_cache_queue_builtin_cleanup', 10 );
    add_action( 'directorist_page_cache_refresh_due_entries', 'directorist_page_cache_refresh_due_entries', 10 );
    add_action( 'wp_ajax_directorist_page_cache_refresh_due', 'directorist_page_cache_receive_refresh_handoff' );
    add_action( 'wp_ajax_nopriv_directorist_page_cache_refresh_due', 'directorist_page_cache_receive_refresh_handoff' );
    add_action( 'directorist_page_cache_invalidated', 'directorist_page_cache_record_invalidation_outcome', 20, 2 );
    add_action( 'directorist_page_cache_invalidation_failed', 'directorist_page_cache_record_invalidation_outcome', 20, 2 );
    add_action( 'directorist_page_cache_automatic_warm_scheduled', 'directorist_page_cache_record_warm_outcome', 20, 2 );
    add_action( 'directorist_page_cache_cleanup_completed', 'directorist_page_cache_record_cleanup_outcome', 20, 1 );
    add_action( 'directorist_page_cache_performance_warm_result', 'directorist_page_cache_record_performance_warm_result', 10, 3 );
    add_action( 'directorist_page_cache_warm_result', 'directorist_page_cache_record_resource_warm_result', 10, 2 );
    add_action( Performance_Job_Manager::MONITOR_HOOK, 'directorist_page_cache_monitor_performance_job' );
    add_action( 'directorist_page_cache_entry_stored', 'directorist_page_cache_record_resource_entry', 10, 1 );
    add_action( 'directorist_page_cache_invalidated', 'directorist_page_cache_record_resource_invalidation', 25, 2 );
    add_action( 'directorist_page_cache_enabled_changed', 'directorist_page_cache_handle_enabled_changed', 20, 2 );
    add_action( 'directorist_page_cache_performance_settings_changed', 'directorist_page_cache_handle_performance_settings_changed', 30, 2 );
    add_action( 'directorist_page_cache_reconcile_resources', 'directorist_page_cache_reconcile_performance_resources', 10, 0 );
    add_action( 'directorist_page_cache_hourly_resource_reconciliation', 'directorist_page_cache_reconcile_performance_resources', 10, 0 );
    add_action( 'directorist_page_cache_daily_resource_reconciliation', 'directorist_page_cache_reconcile_performance_resources', 10, 0 );
    add_action( 'directorist_updated', 'directorist_page_cache_sync_resource_catalog_maintenance', 30 );
    add_action( 'admin_init', 'directorist_page_cache_sync_resource_catalog_maintenance', 30 );
    add_action( 'save_post', 'directorist_page_cache_schedule_resource_post_reconciliation', 30, 3 );
    add_action( 'transition_post_status', 'directorist_page_cache_sync_resource_post_visibility', 30, 3 );
    add_action( 'deleted_post', 'directorist_page_cache_remove_deleted_resource', 30, 2 );
    add_action( 'created_term', 'directorist_page_cache_schedule_resource_term_reconciliation', 30, 4 );
    add_action( 'edited_term', 'directorist_page_cache_schedule_resource_term_reconciliation', 30, 4 );
    add_action( 'delete_term', 'directorist_page_cache_schedule_resource_term_reconciliation', 30, 5 );
    add_action( 'directorist_options_updated', 'directorist_page_cache_schedule_resource_reconciliation', 120 );
    add_filter( 'directorist_page_cache_performance_operation', 'directorist_page_cache_verify_and_repair', 20, 2 );

    if ( is_admin() ) {
        add_action( 'plugins_loaded', 'directorist_page_cache_boot_performance_admin', PHP_INT_MAX );
    }
}
