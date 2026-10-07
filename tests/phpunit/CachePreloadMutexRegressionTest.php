<?php

use Directorist\Cache\Null_Cache_Provider;
use Directorist\Cache\Performance_Job_Manager;
use Directorist\Cache\Performance_Operations;
use Directorist\Cache\Performance_Resource_Catalog;

class Directorist_Cache_Preload_Mutex_Regression_Test extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( Performance_Job_Manager::LOCK_OPTION );
        parent::tearDown();
    }

    private function manager() {
        $provider = new Null_Cache_Provider();
        return new Performance_Job_Manager(
            new Performance_Resource_Catalog( $provider ),
            new Performance_Operations( $provider )
        );
    }

    private function invoke( $manager, $method, ...$args ) {
        $reflection = new ReflectionMethod( $manager, $method );
        $reflection->setAccessible( true );
        return $reflection->invokeArgs( $manager, $args );
    }

    public function test_lock_released_by_another_request_is_not_retained_in_request_cache() {
        global $wpdb;
        $lock = [ 'token' => 'previous-request', 'acquired_at' => time() ];
        add_option( Performance_Job_Manager::LOCK_OPTION, $lock, '', false );
        $this->assertSame( $lock, get_option( Performance_Job_Manager::LOCK_OPTION ) );
        // Simulate another PHP request's release without clearing this request's cache.
        $wpdb->delete( $wpdb->options, [ 'option_name' => Performance_Job_Manager::LOCK_OPTION ] );
        $manager = $this->manager();
        $token = $this->invoke( $manager, 'acquire_job_lock' );
        $this->assertNotSame( '', $token );
        $this->assertSame( $token, get_option( Performance_Job_Manager::LOCK_OPTION )['token'] );
        $this->invoke( $manager, 'release_job_lock', $token );
    }

    public function test_old_owner_cannot_release_a_replacement_lock_hidden_by_request_cache() {
        global $wpdb;
        $old = [ 'token' => 'old-owner', 'acquired_at' => time() - 60 ];
        $new = [ 'token' => 'new-owner', 'acquired_at' => time() ];
        add_option( Performance_Job_Manager::LOCK_OPTION, $old, '', false );
        $this->assertSame( $old, get_option( Performance_Job_Manager::LOCK_OPTION ) );
        $wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( $new ) ], [ 'option_name' => Performance_Job_Manager::LOCK_OPTION ] );
        $this->invoke( $this->manager(), 'release_job_lock', 'old-owner' );
        wp_cache_delete( Performance_Job_Manager::LOCK_OPTION, 'options' );
        $this->assertSame( $new, get_option( Performance_Job_Manager::LOCK_OPTION ) );
    }

    public function test_expired_lock_can_be_reclaimed_and_released() {
        add_option( Performance_Job_Manager::LOCK_OPTION, [ 'token' => 'expired', 'acquired_at' => time() - 60 ], '', false );
        $manager = $this->manager();
        $token = $this->invoke( $manager, 'acquire_job_lock' );
        $this->assertNotSame( '', $token );
        $this->assertSame( $token, get_option( Performance_Job_Manager::LOCK_OPTION )['token'] );
        $this->invoke( $manager, 'release_job_lock', $token );
        $this->assertFalse( get_option( Performance_Job_Manager::LOCK_OPTION ) );
    }

    public function test_active_other_owner_is_not_stolen() {
        $lock = [ 'token' => 'active-other-owner', 'acquired_at' => time() ];
        add_option( Performance_Job_Manager::LOCK_OPTION, $lock, '', false );
        $this->assertSame( '', $this->invoke( $this->manager(), 'acquire_job_lock' ) );
        $this->assertSame( $lock, get_option( Performance_Job_Manager::LOCK_OPTION ) );
    }

    public function test_cached_missing_option_cannot_overwrite_another_owner() {
        global $wpdb;
        $this->assertFalse( get_option( Performance_Job_Manager::LOCK_OPTION ) );
        $lock = [ 'token' => 'concurrent-owner', 'acquired_at' => time() ];
        $wpdb->insert( $wpdb->options, [ 'option_name' => Performance_Job_Manager::LOCK_OPTION, 'option_value' => maybe_serialize( $lock ), 'autoload' => 'no' ] );
        $this->assertSame( '', $this->invoke( $this->manager(), 'acquire_job_lock' ) );
        $this->assertSame( maybe_serialize( $lock ), $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Performance_Job_Manager::LOCK_OPTION ) ) );
    }

    public function test_expired_owner_cannot_delete_a_lock_replaced_after_reading() {
        $old = [ 'token' => 'expired-owner', 'acquired_at' => time() - 60 ];
        $new = [ 'token' => 'replacement-owner', 'acquired_at' => time() ];
        add_option( Performance_Job_Manager::LOCK_OPTION, $new, '', false );
        $this->invoke( $this->manager(), 'delete_matching_job_lock', $old );
        $this->assertSame( $new, get_option( Performance_Job_Manager::LOCK_OPTION ) );
    }

    public function test_acquiring_after_cached_miss_clears_only_its_negative_cache_entry() {
        $this->assertFalse( get_option( Performance_Job_Manager::LOCK_OPTION ) );
        $this->assertFalse( get_option( 'directorist_unrelated_missing_option' ) );
        $manager = $this->manager();
        $token = $this->invoke( $manager, 'acquire_job_lock' );
        $this->assertNotSame( '', $token );
        $this->assertSame( $token, get_option( Performance_Job_Manager::LOCK_OPTION )['token'] );
        $this->assertArrayHasKey( 'directorist_unrelated_missing_option', wp_cache_get( 'notoptions', 'options' ) );
        $this->invoke( $manager, 'release_job_lock', $token );
    }
}
