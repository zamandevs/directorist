<?php

use Directorist\Cache\Abstract_Cache_Provider;
use Directorist\Cache\Plugin_Lifecycle;

final class Directorist_Plugin_Lifecycle_Test_Provider extends Abstract_Cache_Provider {
    public $plans = [];
    public $fail = false;
    public $on_purge;

    public function __construct() {
        $this->configure( 'test-lifecycle', '1', [
            'purge_generations' => function ( $keys, $site_id ) {
                $this->plans[] = [ 'keys' => $keys, 'site_id' => $site_id ];
                if ( is_callable( $this->on_purge ) ) { call_user_func( $this->on_purge ); }
                return ! $this->fail;
            },
            'purge_dependencies' => '__return_true',
        ], true );
    }
}

final class Directorist_Page_Cache_Plugin_Lifecycle_Test extends WP_UnitTestCase {
    private $warms;
    private $provider;
    private $lifecycle;

    public function set_up() {
        parent::set_up();
        $this->warms = 0;
        $this->provider = new Directorist_Plugin_Lifecycle_Test_Provider();
        $this->lifecycle = new Plugin_Lifecycle( function () {
            ++$this->warms;
            return [ 'success' => true, 'code' => 'queued' ];
        } );
        delete_option( Plugin_Lifecycle::PENDING_OPTION );
        delete_option( Plugin_Lifecycle::LOCK_OPTION );
        delete_site_option( 'directorist_page_cache_lifecycle_pending' );
        delete_option( \Directorist\Cache\Performance_Settings::OPTION_NAME );
    }

    public function tear_down() {
        delete_option( Plugin_Lifecycle::PENDING_OPTION );
        delete_option( Plugin_Lifecycle::LOCK_OPTION );
        delete_site_option( 'directorist_page_cache_lifecycle_pending' );
        wp_clear_scheduled_hook( 'directorist_page_cache_reconcile_lifecycle' );
        parent::tear_down();
    }

    public function test_bulk_plugins_and_actions_are_preserved_and_deduplicated() {
        $pending = $this->lifecycle->merge( [], null, [ 'type' => 'plugin', 'action' => 'update', 'plugins' => [
            'directorist-booking/booking.php', 'unrelated/plugin.php', 'directorist-booking/booking.php',
        ] ], 'upgrader_process_complete' );
        $pending = $this->lifecycle->merge( $pending, 'directorist-pricing-plans/plans.php', false, 'activated_plugin' );
        $pending = $this->lifecycle->merge( $pending, 'directorist-pricing-plans/plans.php', false, 'activated_plugin' );
        $this->assertCount( 2, $pending['events'] );
        $this->assertSame( [ 'update', 'activate' ], array_column( $pending['events'], 'action' ) );
        $this->assertSame( get_current_blog_id(), $pending['site_id'] );
        $this->assertSame( is_multisite(), $pending['network_wide'] );
        $this->assertSame( $pending, $this->lifecycle->merge( $pending, null, [ 'type' => 'theme', 'action' => 'update', 'plugins' => [ 'directorist-test/test.php' ] ], 'upgrader_process_complete' ) );
    }

    public function test_relevance_can_include_integrations_but_not_invalid_paths() {
        $filter = static function ( $relevant, $plugin ) { return $relevant || 'custom-integration/plugin.php' === $plugin; };
        add_filter( 'directorist_page_cache_plugin_lifecycle_relevant', $filter, 10, 2 );
        try {
            $pending = $this->lifecycle->merge( [], null, [ 'type' => 'plugin', 'action' => 'update', 'plugins' => [
                'custom-integration/plugin.php', '../directorist-escape/plugin.php', 'directorist-escape/../plugin.php', 'https://example.org/directorist.php',
            ] ], 'upgrader_process_complete' );
            $this->assertCount( 1, $pending['events'] );
        } finally {
            remove_filter( 'directorist_page_cache_plugin_lifecycle_relevant', $filter, 10 );
        }
    }

    public function test_irrelevant_event_does_not_purge_or_preload() {
        $pending = $this->lifecycle->merge( [], 'unrelated/plugin.php', false, 'activated_plugin' );
        $this->assertSame( [], $pending );
        $this->assertTrue( $this->lifecycle->refresh( $pending, $this->provider )['success'] );
        $this->assertSame( [], $this->provider->plans );
        $this->assertSame( 0, $this->warms );
        $this->assertSame( [], $this->lifecycle->merge( [], 'directorist-booking/booking.php', false, 'deleted_plugin' ) );
    }

    public function test_unchanged_policy_plugin_events_invalidate_then_preload_once() {
        $pending = $this->lifecycle->merge( [], 'directorist-booking/booking.php', false, 'activated_plugin' );
        $pending = $this->lifecycle->merge( $pending, 'directorist-booking/booking.php', false, 'deactivated_plugin' );
        $result = $this->lifecycle->refresh( $pending, $this->provider );
        $this->assertTrue( $result['success'] );
        $this->assertCount( 1, $this->provider->plans );
        $this->assertSame( [ 'directorist:' . get_current_blog_id() . ':site' ], $this->provider->plans[0]['keys'] );
        $this->assertSame( 1, $this->warms );
        $stages = [];
        $lifecycle = new Plugin_Lifecycle(
            static function () use ( &$stages ) { $stages[] = 'preload'; return [ 'success' => true, 'code' => 'queued' ]; },
            static function () use ( &$stages ) { $stages[] = 'catalog'; return true; }
        );
        $this->assertTrue( $lifecycle->refresh( $pending, $this->provider )['success'] );
        $this->assertSame( [ 'catalog', 'preload' ], $stages, 'Mark resource states before an async preload can finish.' );
    }

    public function test_failed_purge_never_preloads_and_failed_preload_does_not_claim_purge_failed() {
        $pending = $this->lifecycle->merge( [], 'directorist-booking/booking.php', false, 'activated_plugin' );
        $this->provider->fail = true;
        $this->assertFalse( $this->lifecycle->refresh( $pending, $this->provider )['success'] );
        $this->assertSame( 0, $this->warms );
        $this->provider->fail = false;
        $lifecycle = new Plugin_Lifecycle( static function () { throw new RuntimeException( 'preload' ); } );
        $result = $lifecycle->refresh( $pending, $this->provider );
        $this->assertTrue( $result['success'] );
        $this->assertFalse( $result['preload']['success'] );
    }

    public function test_network_context_is_retained_and_site_mismatch_does_not_purge() {
        $pending = $this->lifecycle->merge( [], 'directorist-booking/booking.php', true, 'activated_plugin' );
        $this->assertTrue( $pending['network_wide'] );
        $pending['site_id'] = get_current_blog_id() + 100;
        $this->assertFalse( $this->lifecycle->refresh( $pending, $this->provider )['success'] );
        $this->assertSame( [], $this->provider->plans );
    }

    public function test_queue_is_bounded_without_losing_invalidation_need() {
        $plugins = [];
        for ( $i = 0; $i < 130; ++$i ) { $plugins[] = 'directorist-test-' . $i . '/plugin.php'; }
        $pending = $this->lifecycle->merge( [], null, [ 'type' => 'plugin', 'action' => 'update', 'plugins' => $plugins ], 'upgrader_process_complete' );
        $this->assertLessThanOrEqual( Plugin_Lifecycle::MAX_EVENTS, count( $pending['events'] ) );
        $this->assertTrue( $this->lifecycle->refresh( $pending, $this->provider )['success'] );
        $this->assertCount( 1, $this->provider->plans );
    }

    public function test_bootstrap_coalesces_relevant_pending_work_without_purging_synchronously() {
        directorist_page_cache_schedule_lifecycle_reconciliation( 'directorist-booking/booking.php', false );
        directorist_page_cache_schedule_lifecycle_reconciliation( 'directorist-pricing-plans/plans.php', false );
        $pending = get_option( Plugin_Lifecycle::PENDING_OPTION, [] );
        $this->assertCount( 2, $pending['events'] );
        $this->assertSame( [], $this->provider->plans );
        $this->assertNotFalse( wp_next_scheduled( 'directorist_page_cache_reconcile_lifecycle' ) );
    }

    public function test_core_update_records_its_actual_basename_for_deferred_invalidation() {
        $this->assertNotFalse( has_action( 'directorist_updated', 'directorist_page_cache_schedule_core_lifecycle_reconciliation' ) );
        directorist_page_cache_schedule_core_lifecycle_reconciliation();
        $pending = get_option( Plugin_Lifecycle::PENDING_OPTION );
        $this->assertSame( [ plugin_basename( ATBDP_DIR . 'directorist-base.php' ) ], array_column( $pending['events'], 'plugin' ) );
        $this->assertSame( [ 'update' ], array_column( $pending['events'], 'action' ) );
    }

    public function test_lifecycle_purge_updates_stored_dashboard_cache_state() {
        $store = new \Directorist\Cache\Performance_Resource_Store();
        $this->assertTrue( $store->create() );
        $url = home_url( '/plugin-lifecycle-cache-state/' );
        $store->begin_generation( 100 );
        $store->upsert( [ [ 'logical_key' => 'lifecycle-page', 'title' => 'Lifecycle page', 'url' => $url, 'type' => 'page', 'route_type' => 'page' ] ], 100 );
        $store->complete_generation( 100 );
        $store->update_cache_state( $url, [ 'state' => 'current', 'created_at' => time(), 'expires_at' => time() + HOUR_IN_SECONDS ] );
        $filter = function () { return [ $this->provider ]; };
        $allow = static function ( $allowed, $operation ) { return 'plugin_lifecycle' === $operation || $allowed; };
        $guarded = false !== has_filter( 'directorist_page_cache_allow_runtime_mutation', '__return_false' );
        remove_filter( 'directorist_page_cache_allow_runtime_mutation', '__return_false', PHP_INT_MAX );
        add_filter( 'directorist_page_cache_allow_runtime_mutation', $allow, PHP_INT_MAX, 2 );
        add_filter( 'directorist_page_cache_providers', $filter );
        try {
            $this->lifecycle->enqueue( 'directorist-booking/booking.php', false, 'deactivated_plugin' );
            $this->assertTrue( directorist_page_cache_refresh_plugin_output( [ 'success' => true, 'state' => 'external', 'provider' => 'test-lifecycle' ] )['success'] );
            $this->assertSame( 'invalidated', $store->query( [ 'type' => 'page' ] )['items'][0]['stored_cache_state'] );
        } finally {
            remove_filter( 'directorist_page_cache_providers', $filter );
            remove_filter( 'directorist_page_cache_allow_runtime_mutation', $allow, PHP_INT_MAX );
            if ( $guarded ) { add_filter( 'directorist_page_cache_allow_runtime_mutation', '__return_false', PHP_INT_MAX ); }
            global $wpdb;
            $wpdb->query( 'DROP TABLE IF EXISTS ' . $store->table_name() );
            delete_option( \Directorist\Cache\Performance_Resource_Store::VERSION_OPTION );
            delete_option( \Directorist\Cache\Performance_Resource_Store::STATUS_OPTION );
        }
    }

    public function test_compare_and_swap_and_lock_do_not_erase_concurrent_work() {
        $first = $this->lifecycle->enqueue( 'directorist-booking/booking.php', false, 'activated_plugin' );
        $next = $this->lifecycle->enqueue( 'directorist-pricing-plans/plans.php', false, 'activated_plugin' );
        $this->assertFalse( $this->lifecycle->replace( $first, [] ) );
        $this->assertSame( $next, get_option( Plugin_Lifecycle::PENDING_OPTION ) );
        $lock = $this->lifecycle->acquire();
        $this->assertIsArray( $lock );
        $this->assertFalse( $this->lifecycle->acquire() );
        $this->lifecycle->release( [ 'token' => 'another-worker', 'expires' => $lock['expires'] ] );
        $this->assertFalse( $this->lifecycle->acquire() );
        $this->lifecycle->release( $lock );
        $replacement = $this->lifecycle->acquire();
        $this->assertIsArray( $replacement );
        $this->lifecycle->release( $replacement );
    }

    public function test_bootstrap_consumes_successful_work_and_bounds_failed_retries() {
        $filter = function () { return [ $this->provider ]; };
        $mutation = static function ( $allowed, $operation ) { return 'plugin_lifecycle' === $operation || $allowed; };
        add_filter( 'directorist_page_cache_providers', $filter );
        $guarded = false !== has_filter( 'directorist_page_cache_allow_runtime_mutation', '__return_false' );
        remove_filter( 'directorist_page_cache_allow_runtime_mutation', '__return_false', PHP_INT_MAX );
        add_filter( 'directorist_page_cache_allow_runtime_mutation', $mutation, PHP_INT_MAX, 2 );
        try {
            $this->lifecycle->enqueue( 'directorist-booking/booking.php', false, 'activated_plugin' );
            $state = [ 'success' => true, 'state' => 'external', 'provider' => 'test-lifecycle' ];
            $result = directorist_page_cache_refresh_plugin_output( $state );
            $this->assertTrue( $result['success'], wp_json_encode( $result ) );
            $this->assertSame( [], get_option( Plugin_Lifecycle::PENDING_OPTION ) );
            $this->assertSame( 'no_plugin_changes', directorist_page_cache_refresh_plugin_output( $state )['code'] );
            $this->lifecycle->enqueue( 'directorist-booking/booking.php', false, 'deactivated_plugin' );
            $this->provider->on_purge = function () {
                $this->lifecycle->enqueue( 'directorist-advanced-review/review.php', false, 'activated_plugin' );
            };
            $this->assertTrue( directorist_page_cache_refresh_plugin_output( $state )['success'] );
            $this->assertCount( 2, get_option( Plugin_Lifecycle::PENDING_OPTION )['events'], 'A running worker must not erase newly queued events.' );
            $this->provider->on_purge = null;
            $this->assertTrue( directorist_page_cache_refresh_plugin_output( $state )['success'] );
            $this->assertSame( [], get_option( Plugin_Lifecycle::PENDING_OPTION ) );
            $this->provider->fail = true;
            $this->lifecycle->enqueue( 'directorist-booking/booking.php', false, 'deactivated_plugin' );
            for ( $i = 1; $i <= Plugin_Lifecycle::MAX_ATTEMPTS; ++$i ) {
                $this->assertFalse( directorist_page_cache_refresh_plugin_output( $state )['success'] );
                $pending = get_option( Plugin_Lifecycle::PENDING_OPTION );
                $this->assertSame( $i, $pending['attempts'] );
                if ( $i < Plugin_Lifecycle::MAX_ATTEMPTS ) {
                    $this->assertSame( 'plugin_retry_pending', directorist_page_cache_refresh_plugin_output( $state )['code'] );
                }
                $pending['retry_at'] = 0;
                update_option( Plugin_Lifecycle::PENDING_OPTION, $pending, false );
            }
            $this->assertSame( 'plugin_retry_exhausted', directorist_page_cache_refresh_plugin_output( $state )['code'] );
            $this->assertCount( 6, $this->provider->plans );
        } finally {
            remove_filter( 'directorist_page_cache_providers', $filter );
            remove_filter( 'directorist_page_cache_allow_runtime_mutation', $mutation, PHP_INT_MAX );
            if ( $guarded ) { add_filter( 'directorist_page_cache_allow_runtime_mutation', '__return_false', PHP_INT_MAX ); }
        }
    }

    public function test_unavailable_or_blocked_provider_and_mutation_denial_keep_work_pending() {
        $this->lifecycle->enqueue( 'directorist-booking/booking.php', false, 'activated_plugin' );
        $deny = '__return_false';
        add_filter( 'directorist_page_cache_allow_runtime_mutation', $deny, 100 );
        try {
            $this->assertSame( 'plugin_mutation_disabled', directorist_page_cache_refresh_plugin_output( [ 'success' => false, 'state' => 'blocked' ] )['code'] );
            $this->assertNotEmpty( get_option( Plugin_Lifecycle::PENDING_OPTION )['events'] );
            $this->assertSame( [], $this->provider->plans );
        } finally {
            remove_filter( 'directorist_page_cache_allow_runtime_mutation', $deny, 100 );
        }
    }

    public function test_multisite_pending_and_locks_are_isolated_by_blog() {
        if ( ! is_multisite() ) { $this->markTestSkipped( 'Multisite only.' ); }
        $first = $this->lifecycle->enqueue( 'directorist-booking/booking.php', false, 'activated_plugin' );
        $lock = $this->lifecycle->acquire();
        $site = self::factory()->blog->create();
        switch_to_blog( $site );
        try {
            $this->assertSame( [], get_option( Plugin_Lifecycle::PENDING_OPTION, [] ) );
            $other = $this->lifecycle->acquire();
            $this->assertIsArray( $other );
            $this->lifecycle->release( $other );
            $second = $this->lifecycle->enqueue( 'directorist-pricing-plans/plans.php', false, 'activated_plugin' );
            $this->assertSame( (int) $site, $second['site_id'] );
            delete_option( Plugin_Lifecycle::PENDING_OPTION );
        } finally {
            restore_current_blog();
            $this->lifecycle->release( $lock );
        }
        $this->assertSame( $first, get_option( Plugin_Lifecycle::PENDING_OPTION ) );
    }

    public function test_network_fanout_keeps_actions_and_queues_each_blog_without_loading_its_plugins_here() {
        if ( ! is_multisite() ) { $this->markTestSkipped( 'Multisite only.' ); }
        $site = self::factory()->blog->create();
        $pending = $this->lifecycle->merge( [], 'directorist-booking/booking.php', true, 'deactivated_plugin' );
        $network = get_current_network_id();
        $original = get_current_blog_id();
        directorist_page_cache_network_plugin_lifecycle( $pending['events'], $network );
        $this->assertSame( $original, get_current_blog_id() );
        switch_to_blog( $site );
        try {
            $queued = get_option( Plugin_Lifecycle::PENDING_OPTION );
            $this->assertSame( [ 'deactivate' ], array_column( $queued['events'], 'action' ) );
            $this->assertFalse( $queued['network_wide'] );
            $this->assertNotFalse( wp_next_scheduled( 'directorist_page_cache_reconcile_lifecycle' ) );
            delete_option( Plugin_Lifecycle::PENDING_OPTION );
            wp_clear_scheduled_hook( 'directorist_page_cache_reconcile_lifecycle' );
        } finally {
            restore_current_blog();
        }
    }

    public function test_network_fanout_is_bounded_and_resumes_after_the_last_blog_id() {
        if ( ! is_multisite() ) { $this->markTestSkipped( 'Multisite only.' ); }
        $sites = self::factory()->blog->create_many( 25 );
        $events = [ 'directorist-booking/booking.php:activate' => [ 'plugin' => 'directorist-booking/booking.php', 'action' => 'activate' ] ];
        $network = get_current_network_id();
        $all = get_sites( [ 'network_id' => $network, 'fields' => 'ids', 'orderby' => 'id', 'order' => 'ASC', 'deleted' => 0, 'spam' => 0, 'archived' => 0, 'number' => 100 ] );
        directorist_page_cache_network_plugin_lifecycle( $events, $network );
        $cursor = (int) $all[19];
        $args = [ $events, $network, $cursor ];
        $this->assertNotFalse( wp_next_scheduled( 'directorist_page_cache_network_plugin_lifecycle', $args ) );
        $queued = 0;
        foreach ( $all as $site_id ) {
            switch_to_blog( $site_id );
            try { if ( ! empty( get_option( Plugin_Lifecycle::PENDING_OPTION, [] )['events'] ) ) { ++$queued; } }
            finally { restore_current_blog(); }
        }
        $this->assertSame( 20, $queued );
        directorist_page_cache_network_plugin_lifecycle( $events, $network, $cursor );
        foreach ( $sites as $site_id ) {
            switch_to_blog( $site_id );
            try {
                $this->assertNotEmpty( get_option( Plugin_Lifecycle::PENDING_OPTION, [] )['events'] );
                delete_option( Plugin_Lifecycle::PENDING_OPTION );
                wp_clear_scheduled_hook( 'directorist_page_cache_reconcile_lifecycle' );
            } finally { restore_current_blog(); }
        }
        wp_clear_scheduled_hook( 'directorist_page_cache_network_plugin_lifecycle' );
    }

    public function test_relevant_plugin_change_invalidates_real_stored_html_without_changing_policy() {
        $root = sys_get_temp_dir() . '/directorist-plugin-output-' . bin2hex( random_bytes( 6 ) );
        $clock = static function () { return 1000; };
        $storage = new \Directorist\Cache\Built_In\Cache_Storage( $root, $clock );
        $key = ( new \Directorist\Cache\Built_In\Request_Key() )->from_url( 'https://example.test/directory/listing/' );
        $descriptor = [ 'eligible' => true, 'site_id' => get_current_blog_id(), 'route_type' => 'listing', 'cache_key' => 'plugin-listing', 'dependencies' => [ 'directorist:' . get_current_blog_id() . ':site', 'directorist:0:lifecycle' ] ];
        try {
            $this->assertTrue( $storage->store( $key, '<!DOCTYPE html><html><body>Old extension output</body></html>', $descriptor, [ 'content-type' => 'text/html' ], 3600, 3600 )['success'] );
            $this->assertTrue( $storage->load( $key )['hit'] );
            $engine = new \Directorist\Cache\Built_In\Cache_Engine( [ 'cache_dir' => $root ], [ 'clock' => $clock ] );
            $provider = new \Directorist\Cache\Built_In\Provider( static function () use ( $engine ) { return $engine; }, '__return_true', '__return_true', static function () {} );
            $pending = $this->lifecycle->merge( [], 'directorist-booking/booking.php', false, 'deactivated_plugin' );
            $this->assertTrue( $this->lifecycle->refresh( $pending, $provider )['success'] );
            $this->assertFalse( $storage->load( $key )['hit'], 'Old public output must be rejected after the site generation changes.' );
            $pending['network_wide'] = true;
            $result = $this->lifecycle->refresh( $pending, $provider );
            $this->assertContains( 'directorist:0:lifecycle', $result['plan']['generations'] );
        } finally {
            if ( is_dir( $root ) ) {
                $entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
                foreach ( $entries as $entry ) { $entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() ); }
                rmdir( $root );
            }
        }
    }
}
