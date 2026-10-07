<?php

use Directorist\Cache\Built_In\Cache_Engine;

require_once __DIR__ . '/fixtures/PerformanceReviewFixture.php';

/**
 * Cache bypass constants and request-static hook guards cannot be reset in PHP.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Directorist_Performance_Lifecycle_Review_Regression_Test extends Directorist_Performance_Review_Fixture {
    public function test_failed_plugin_deletion_is_not_queued_and_site_replay_is_not_network_wide() {
        $lifecycle = new \Directorist\Cache\Plugin_Lifecycle();
        $this->assertSame( [], $lifecycle->merge( [], 'directorist-booking/booking.php', false, 'deleted_plugin' ) );
        $pending = $lifecycle->merge( [], 'directorist-booking/booking.php', true, 'deleted_plugin', false );
        $this->assertNotEmpty( $pending['events'] );
        $this->assertFalse( $pending['network_wide'] );
    }

    public function test_replayed_successful_plugin_deletion_remains_queued_on_target_site() {
        $class = '\\Directorist\\Cache\\Plugin_Lifecycle';
        delete_option( $class::PENDING_OPTION );
        $events = [ 'directorist-booking/booking.php:delete' => [ 'plugin' => 'directorist-booking/booking.php', 'action' => 'delete' ] ];
        try {
            $queued = ( new $class() )->enqueue_events( $events, false );
            $this->assertNotEmpty( $queued['events'] ?? [], 'A completed delete event was discarded by the target-site replay.' );
        } finally {
            delete_option( $class::PENDING_OPTION );
        }
    }

    public function test_multisite_successful_deletion_fans_out_to_another_site() {
        if ( ! is_multisite() ) {
            $this->markTestSkipped( 'Multisite replay only.' );
        }
        $class = '\\Directorist\\Cache\\Plugin_Lifecycle';
        $site = self::factory()->blog->create();
        $events = [ 'directorist-booking/booking.php:delete' => [ 'plugin' => 'directorist-booking/booking.php', 'action' => 'delete' ] ];
        $original = get_current_blog_id();
        directorist_page_cache_network_plugin_lifecycle( $events, get_current_network_id() );
        $this->assertSame( $original, get_current_blog_id() );
        switch_to_blog( $site );
        try {
            $queued = get_option( $class::PENDING_OPTION, [] );
            $this->assertNotEmpty( $queued['events'] ?? [], 'Network deletion was not queued for the second site.' );
        } finally {
            delete_option( $class::PENDING_OPTION );
            wp_clear_scheduled_hook( 'directorist_page_cache_reconcile_lifecycle' );
            restore_current_blog();
        }
    }
}
