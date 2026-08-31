<?php
/**
 * Built-in cache bootstrap and lifecycle hook behavior locks.
 */

use Directorist\Cache\Built_In\Lifecycle;
use Directorist\Cache\Built_In\Cleanup_Background_Process;
use Directorist\Cache\Cache_Enabler_Compatibility;
use Directorist\Cache\LiteSpeed_Compatibility;
use Directorist\Cache\Performance_Settings;
use Directorist\Cache\Warm_Background_Process;
use Directorist\Cache\WP_Fastest_Cache_Compatibility;

final class Directorist_Page_Cache_Built_In_Bootstrap_Test extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_site_option( 'directorist_page_cache_lifecycle_pending' );
        delete_site_option( Lifecycle::STATE_OPTION );
        wp_clear_scheduled_hook( 'directorist_page_cache_reconcile_lifecycle' );
        wp_clear_scheduled_hook( 'directorist_page_cache_daily_cleanup' );
        wp_clear_scheduled_hook( 'directorist_page_cache_refresh_due_entries' );
        directorist_page_cache_builtin_cleanup_process()->reset();
        Cache_Enabler_Compatibility::reset();
        LiteSpeed_Compatibility::reset();
        WP_Fastest_Cache_Compatibility::reset();

        parent::tearDown();
    }

    public function test_lifecycle_hooks_are_registered_without_a_frontend_repair_hook() {
        $this->assertNotFalse( has_action( 'activate_plugin', 'directorist_page_cache_prepare_external_activation' ) );
        $this->assertNotFalse( has_action( 'activated_plugin', 'directorist_page_cache_schedule_lifecycle_reconciliation' ) );
        $this->assertNotFalse( has_action( 'deactivate_wp-super-cache/wp-cache.php', 'directorist_page_cache_cleanup_wp_super_cache_before_deactivation' ) );
        $this->assertNotFalse( has_action( 'deactivate_cache-enabler/cache-enabler.php', 'directorist_page_cache_cleanup_cache_enabler_before_deactivation' ) );
        $this->assertNotFalse( has_action( 'deactivate_wp-fastest-cache/wpFastestCache.php', 'directorist_page_cache_cleanup_wp_fastest_cache_before_deactivation' ) );
        $this->assertNotFalse( has_action( 'deactivate_litespeed-cache/litespeed-cache.php', 'directorist_page_cache_cleanup_litespeed_before_deactivation' ) );
        $this->assertNotFalse( has_action( 'deactivated_plugin', 'directorist_page_cache_schedule_lifecycle_reconciliation' ) );
        $this->assertNotFalse( has_action( 'deactivated_plugin', 'directorist_page_cache_cleanup_external_compatibility' ) );
        $this->assertNotFalse( has_action( 'upgrader_process_complete', 'directorist_page_cache_schedule_lifecycle_reconciliation' ) );
        $this->assertNotFalse( has_action( 'admin_init', 'directorist_page_cache_reconcile_lifecycle' ) );
        $this->assertNotFalse( has_action( 'directorist_page_cache_reconcile_lifecycle', 'directorist_page_cache_reconcile_lifecycle' ) );
        $this->assertFalse( has_action( 'init', 'directorist_page_cache_reconcile_lifecycle' ) );
        $this->assertFalse( has_action( 'template_redirect', 'directorist_page_cache_reconcile_lifecycle' ) );
        $this->assertFalse( has_action( 'init', 'directorist_page_cache_activate_wp_super_cache_compatibility' ) );
    }

    public function test_external_compatibility_cleanup_targets_supported_provider_state_only() {
        $calls   = 0;
        $cleanup = static function () use ( &$calls ) {
            ++$calls;

            return [ 'success' => true, 'code' => 'configuration_removed' ];
        };

        update_option( LiteSpeed_Compatibility::OPTION_NAME, [ 'policy_version' => 1 ], false );
        update_option( Cache_Enabler_Compatibility::OPTION_NAME, [ 'policy_version' => 1 ], false );
        update_option( WP_Fastest_Cache_Compatibility::OPTION_NAME, [ 'policy_version' => 1 ], false );

        $unrelated = directorist_page_cache_cleanup_external_compatibility( 'akismet/akismet.php', false, $cleanup );
        $wpsc      = directorist_page_cache_cleanup_external_compatibility( 'wp-super-cache/wp-cache.php', false, $cleanup );
        $litespeed = directorist_page_cache_cleanup_external_compatibility(
            'litespeed-cache/litespeed-cache.php',
            false,
            static function () {
                LiteSpeed_Compatibility::reset();

                return [ 'success' => true, 'code' => 'configuration_removed' ];
            }
        );
        $enabler   = directorist_page_cache_cleanup_external_compatibility(
            'cache-enabler/cache-enabler.php',
            false,
            static function () {
                Cache_Enabler_Compatibility::reset();

                return [ 'success' => true, 'code' => 'configuration_removed' ];
            }
        );
        $fastest   = directorist_page_cache_cleanup_external_compatibility(
            'wp-fastest-cache/wpFastestCache.php',
            false,
            static function () {
                WP_Fastest_Cache_Compatibility::reset();

                return [ 'success' => true, 'code' => 'configuration_removed' ];
            }
        );

        $this->assertSame( 'compatibility_not_required', $unrelated['code'] );
        $this->assertSame( 'configuration_removed', $wpsc['code'] );
        $this->assertSame( 'configuration_removed', $litespeed['code'] );
        $this->assertSame( 'configuration_removed', $enabler['code'] );
        $this->assertSame( 'configuration_removed', $fastest['code'] );
        $this->assertSame( [], LiteSpeed_Compatibility::current() );
        $this->assertSame( [], Cache_Enabler_Compatibility::current() );
        $this->assertSame( [], WP_Fastest_Cache_Compatibility::current() );
        $this->assertSame( 1, $calls );
    }

    public function test_external_compatibility_sync_preserves_stable_runtime_mutation_keys() {
        $mutations = [];
        $filter    = static function ( $allowed, $mutation ) use ( &$mutations ) {
            if ( false !== strpos( (string) $mutation, 'sync' ) ) {
                $mutations[] = (string) $mutation;

                return false;
            }

            return $allowed;
        };

        add_filter( 'directorist_page_cache_allow_runtime_mutation', $filter, 10, 2 );

        try {
            foreach ( [ 'wp-super-cache', 'cache-enabler', 'wp-fastest-cache', 'litespeed-cache' ] as $provider ) {
                $result = directorist_page_cache_sync_external_compatibility(
                    [
                        'state'    => 'external',
                        'provider' => $provider,
                    ]
                );

                $this->assertSame( 'runtime_mutation_disabled', $result['code'] );
            }
        } finally {
            remove_filter( 'directorist_page_cache_allow_runtime_mutation', $filter, 10 );
        }

        $this->assertSame( [ 'wpsc_sync', 'cache_enabler_sync', 'wpfc_sync', 'litespeed_sync' ], $mutations );
    }

    public function test_cron_boot_registers_cleanup_healthcheck_schedule_before_rescheduling() {
        $worker     = directorist_page_cache_builtin_cleanup_process();
        $job_worker = directorist_page_cache_performance_job_process();
        $job_hook   = 'wp_1_directorist_performance_cache_job_cron';

        remove_filter( 'cron_schedules', [ $job_worker, 'schedule_cron_healthcheck' ] );
        remove_action( $job_hook, [ $job_worker, 'handle_cron_healthcheck' ] );

        $this->assertTrue( directorist_page_cache_boot_cron_workers( true ) );

        $this->assertNotFalse( has_filter( 'cron_schedules', [ $worker, 'schedule_cron_healthcheck' ] ) );
        $this->assertNotFalse( has_filter( 'cron_schedules', [ $job_worker, 'schedule_cron_healthcheck' ] ) );
        $this->assertNotFalse( has_action( $job_hook, [ $job_worker, 'handle_cron_healthcheck' ] ) );
        $this->assertArrayHasKey( 'wp_1_directorist_page_cache_cleanup_cron_interval', wp_get_schedules() );
        $this->assertArrayHasKey( 'wp_1_directorist_page_cache_warm_cron_interval', wp_get_schedules() );
        $this->assertArrayHasKey( 'wp_1_directorist_performance_cache_job_cron_interval', wp_get_schedules() );
    }

    public function test_normal_frontend_boot_does_not_construct_cron_only_workers() {
        $this->assertFalse( directorist_page_cache_boot_cron_workers( false ) );
    }

    public function test_plugin_lifecycle_event_only_persists_bounded_pending_work() {
        $result  = directorist_page_cache_schedule_lifecycle_reconciliation( 'wp-super-cache/wp-cache.php', false );
        $pending = get_site_option( 'directorist_page_cache_lifecycle_pending', [] );

        $this->assertTrue( $result );
        $this->assertSame( 'plugin_lifecycle', $pending['reason'] );
        $this->assertSame( 'wp-super-cache/wp-cache.php', $pending['plugin'] );
        $this->assertNotFalse( wp_next_scheduled( 'directorist_page_cache_reconcile_lifecycle' ) );
    }

    public function test_lifecycle_freshness_rechecks_unstable_and_changed_provider_states() {
        $now       = time();
        $resolvers = [
            'clock'           => static function () use ( $now ) {
                return $now;
            },
            'enabled'         => '__return_true',
            'runtime_healthy' => '__return_true',
            'external_probe'  => static function () {
                return [ 'code' => 'no_available_provider', 'provider' => '' ];
            },
            'owner'           => static function () {
                return 'directorist-cache';
            },
        ];

        $this->assertFalse( directorist_page_cache_lifecycle_state_is_current( [ 'state' => 'blocked', 'updated_at' => $now ], $resolvers ) );
        $this->assertFalse( directorist_page_cache_lifecycle_state_is_current( [ 'state' => 'unavailable', 'updated_at' => $now ], $resolvers ) );
        $this->assertTrue( directorist_page_cache_lifecycle_state_is_current( [ 'state' => 'built_in', 'updated_at' => $now ], $resolvers ) );

        $resolvers['external_probe'] = static function () {
            return [ 'code' => 'selected', 'provider' => 'wp-super-cache' ];
        };

        $this->assertFalse( directorist_page_cache_lifecycle_state_is_current( [ 'state' => 'built_in', 'updated_at' => $now ], $resolvers ) );

        $resolvers['owner'] = static function () {
            return 'wp-super-cache';
        };

        $this->assertTrue(
            directorist_page_cache_lifecycle_state_is_current(
                [ 'state' => 'external', 'provider' => 'wp-super-cache', 'updated_at' => $now ],
                $resolvers
            )
        );

        $resolvers['external_probe'] = static function () {
            return [ 'code' => 'no_available_provider', 'provider' => '' ];
        };

        $this->assertFalse(
            directorist_page_cache_lifecycle_state_is_current(
                [ 'state' => 'external', 'provider' => 'wp-super-cache', 'updated_at' => $now ],
                $resolvers
            )
        );
    }

    public function test_core_only_unit_boot_remains_inert_without_owned_runtime_files() {
        $this->assertFalse( directorist_page_cache_builtin_runtime_is_healthy() );
        $this->assertFalse( directorist_page_cache_has_provider_signal() );
        $this->assertSame( 'none', directorist_page_cache()->get_provider()->get_id() );
    }

    public function test_builtin_engine_delegates_warming_to_the_shared_core_background_process() {
        $engine = directorist_page_cache_builtin_engine_instance();
        $worker = directorist_page_cache_warm_background_process();

        $this->assertTrue( $engine->supports_warm() );
        $this->assertInstanceOf( Warm_Background_Process::class, $worker );
        $this->assertSame( $worker, directorist_page_cache_warm_background_process() );
        $this->assertTrue( directorist_page_cache_register_warm_worker( directorist_page_cache_builtin_provider() ) );
        $this->assertNotFalse( has_action( 'wp_ajax_wp_1_directorist_page_cache_warm', [ $worker, 'maybe_handle' ] ) );
        $this->assertNotFalse( has_action( 'wp_ajax_nopriv_wp_1_directorist_page_cache_warm', [ $worker, 'maybe_handle' ] ) );

        $worker->reset();
    }

    public function test_builtin_cleanup_controller_can_be_registered_for_its_signed_internal_request() {
        $worker = directorist_page_cache_builtin_cleanup_process();

        $this->assertTrue( directorist_page_cache_register_builtin_cleanup_worker() );
        $this->assertNotFalse( has_action( 'wp_ajax_wp_1_directorist_page_cache_cleanup', [ $worker, 'maybe_handle' ] ) );
        $this->assertNotFalse( has_action( 'wp_ajax_nopriv_wp_1_directorist_page_cache_cleanup', [ $worker, 'maybe_handle' ] ) );
        $this->assertSame( Cleanup_Background_Process::ACTION, 'directorist_page_cache_cleanup' );

        $worker->reset();
    }

    public function test_builtin_maintenance_schedule_follows_the_lifecycle_state() {
        directorist_page_cache_sync_builtin_maintenance( [ 'success' => true, 'state' => 'built_in' ] );

        $this->assertNotFalse( wp_next_scheduled( 'directorist_page_cache_daily_cleanup' ) );
        $this->assertNotFalse( wp_next_scheduled( 'directorist_page_cache_refresh_due_entries' ) );

        directorist_page_cache_sync_builtin_maintenance( [ 'success' => true, 'state' => 'external' ] );

        $this->assertFalse( wp_next_scheduled( 'directorist_page_cache_daily_cleanup' ) );
        $this->assertFalse( wp_next_scheduled( 'directorist_page_cache_refresh_due_entries' ) );
        $this->assertFalse( directorist_page_cache_builtin_cleanup_process()->status()['pending'] );
    }

    public function test_current_lifecycle_state_still_repairs_new_maintenance_schedules_after_an_upgrade() {
        $original = get_option( Performance_Settings::OPTION_NAME, null );
        update_option( Performance_Settings::OPTION_NAME, [ 'enabled' => false ], false );
        update_site_option(
            Lifecycle::STATE_OPTION,
            [
                'success'    => true,
                'state'      => 'disabled',
                'updated_at' => time(),
            ]
        );
        wp_schedule_single_event( time() + 300, 'directorist_page_cache_refresh_due_entries' );

        try {
            $result = directorist_page_cache_reconcile_lifecycle();
        } finally {
            if ( null === $original ) {
                delete_option( Performance_Settings::OPTION_NAME );
            } else {
                update_option( Performance_Settings::OPTION_NAME, $original, false );
            }
        }

        $this->assertNull( $result );
        $this->assertFalse( wp_next_scheduled( 'directorist_page_cache_refresh_due_entries' ) );
    }

    public function test_stale_refresh_receiver_rejects_forged_and_cross_origin_handoffs() {
        $this->assertNotFalse( has_action( 'wp_ajax_nopriv_directorist_page_cache_refresh_due', 'directorist_page_cache_receive_refresh_handoff' ) );
        $timestamp = time();
        $url       = 'https://foreign.test/directory/';
        $hash      = hash( 'sha256', $url );
        $signature = hash_hmac( 'sha256', $timestamp . "\n" . $hash . "\n" . $url, directorist_page_cache_refresh_token() );

        $forged  = directorist_page_cache_handle_refresh_handoff(
            [ 'timestamp' => $timestamp, 'signature' => str_repeat( 'a', 64 ), 'url' => $url, 'hash' => $hash ]
        );
        $foreign = directorist_page_cache_handle_refresh_handoff(
            [ 'timestamp' => $timestamp, 'signature' => $signature, 'url' => $url, 'hash' => $hash ]
        );

        $this->assertSame( 'invalid_refresh_token', $forged['code'] );
        $this->assertSame( 'invalid_refresh_url', $foreign['code'] );
    }

    public function test_valid_stale_refresh_handoff_queues_one_local_url_and_records_its_claim() {
        $timestamp = time();
        $url       = home_url( '/directory/refresh-me/' );
        $variation = [ 'wp-wpml_current_language' => 'sv' ];
        $key       = ( new \Directorist\Cache\Built_In\Request_Key() )->from_url( $url, $variation );
        $signature = hash_hmac( 'sha256', $timestamp . "\n" . $key['hash'] . "\n" . $url, directorist_page_cache_refresh_token() );
        $queued    = [];
        $marked    = [];
        $store     = new class( $marked ) {
            public $marked;

            public function __construct( &$marked ) {
                $this->marked =& $marked;
            }

            public function mark_refresh_requested( array $urls, $time ) {
                $this->marked = compact( 'urls', 'time' );

                return true;
            }
        };
        $result    = directorist_page_cache_handle_refresh_handoff(
            [ 'timestamp' => $timestamp, 'signature' => $signature, 'url' => $url, 'hash' => $key['hash'], 'variation' => wp_json_encode( $variation ) ],
            [
                'provider'       => directorist_page_cache_builtin_provider(),
                'queue'          => static function ( $queued_url, array $variation, $cache_hash ) use ( &$queued ) {
                    $queued = compact( 'queued_url', 'variation', 'cache_hash' );

                    return [ 'success' => true, 'code' => 'queued', 'queued' => 1 ];
                },
                'resource_store' => $store,
            ]
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( $url, $queued['queued_url'] );
        $this->assertSame( $variation, $queued['variation'] );
        $this->assertSame( $key['hash'], $queued['cache_hash'] );
        $this->assertSame( [ $url ], $marked['urls'] );
    }

    public function test_cleanup_refuses_to_queue_without_a_healthy_owned_runtime() {
        $result = directorist_page_cache_queue_builtin_cleanup();

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'runtime_unavailable', $result['code'] );
        $this->assertSame( 0, $result['queued'] );
    }
}
