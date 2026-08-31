<?php
/**
 * Performance dashboard setting behavior locks.
 */

use Directorist\Cache\Performance_Settings;

class Directorist_Page_Cache_Performance_Settings_Test extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option( Performance_Settings::OPTION_NAME );
    }

    protected function tearDown(): void {
        delete_option( Performance_Settings::OPTION_NAME );
        parent::tearDown();
    }

    public function test_defaults_are_enabled_with_sampling_disabled_and_bounded_history() {
        $settings = ( new Performance_Settings() )->get();

        $this->assertTrue( $settings['enabled'] );
        $this->assertSame( 0, $settings['sample_rate'] );
        $this->assertSame( 20, $settings['history_limit'] );
        $this->assertSame( 0, $settings['diagnostics_until'] );
        $this->assertSame( 'automatic', $settings['cache_duration'] );
        $this->assertTrue( $settings['cache_filtered_results'] );
        $this->assertSame( 6 * HOUR_IN_SECONDS, ( new Performance_Settings() )->get_cache_ttl() );
        $this->assertSame(
            [ 'soft_ttl' => DAY_IN_SECONDS, 'hard_ttl' => 7 * DAY_IN_SECONDS, 'jitter' => 600 ],
            ( new Performance_Settings() )->get_cache_policy()['routes']['listing']
        );
        $this->assertTrue( directorist_page_cache_is_enabled() );
    }

    public function test_update_normalizes_known_values_and_discards_unknown_input() {
        $settings = new Performance_Settings();
        $updated  = $settings->update(
            [
                'enabled'       => '0',
                'sample_rate'   => 7,
                'history_limit' => 1000,
                'diagnostics_until' => time() + HOUR_IN_SECONDS,
                'cache_duration'    => '21600',
                'cache_filtered_results' => '0',
                'unknown'       => 'discarded',
            ]
        );

        $this->assertFalse( $updated['enabled'] );
        $this->assertSame( 5, $updated['sample_rate'] );
        $this->assertSame( 50, $updated['history_limit'] );
        $this->assertGreaterThan( time(), $updated['diagnostics_until'] );
        $this->assertSame( '21600', $updated['cache_duration'] );
        $this->assertFalse( $updated['cache_filtered_results'] );
        $this->assertSame( 6 * HOUR_IN_SECONDS, $settings->get_cache_ttl() );
        $this->assertArrayNotHasKey( 'unknown', $updated );
        $this->assertFalse( directorist_page_cache_is_enabled() );
    }

    public function test_corrupt_persisted_data_falls_back_field_by_field() {
        update_option(
            Performance_Settings::OPTION_NAME,
            [
                'enabled'       => 'invalid',
                'sample_rate'   => -20,
                'history_limit' => 'broken',
                'diagnostics_until' => 'broken',
                'cache_duration'    => '90-days',
                'cache_filtered_results' => 'broken',
            ]
        );

        $this->assertSame(
            [
                'enabled'       => true,
                'sample_rate'   => 0,
                'history_limit' => 20,
                'diagnostics_until' => 0,
                'cache_duration'    => 'automatic',
                'cache_filtered_results' => true,
            ],
            ( new Performance_Settings() )->get()
        );
    }

    public function test_cache_duration_is_restricted_to_predictable_user_choices() {
        $settings = new Performance_Settings();

        foreach ( [ 'automatic', '3600', '21600', '43200', '86400' ] as $duration ) {
            $this->assertSame( $duration, $settings->update( [ 'cache_duration' => $duration ] )['cache_duration'] );
        }

        $this->assertSame( 'automatic', $settings->update( [ 'cache_duration' => '172800' ] )['cache_duration'] );
    }

    public function test_automatic_cache_policy_uses_route_aware_soft_and_hard_boundaries() {
        $policy = ( new Performance_Settings() )->get_cache_policy();

        $this->assertSame( 'automatic', $policy['mode'] );
        $this->assertSame( [ 'soft_ttl' => 6 * HOUR_IN_SECONDS, 'hard_ttl' => DAY_IN_SECONDS, 'jitter' => 300 ], $policy['default'] );
        $this->assertSame( [ 'soft_ttl' => DAY_IN_SECONDS, 'hard_ttl' => 7 * DAY_IN_SECONDS, 'jitter' => 600 ], $policy['routes']['listing'] );
        $this->assertSame( [ 'soft_ttl' => 2 * HOUR_IN_SECONDS, 'hard_ttl' => 6 * HOUR_IN_SECONDS, 'jitter' => 180 ], $policy['routes']['search'] );
        $this->assertSame( $policy['routes']['search'], $policy['routes']['search-form'] );
        $this->assertSame( $policy['default'], $policy['routes']['embedded'] );
    }

    public function test_explicit_duration_uses_selected_soft_ttl_with_bounded_stale_grace() {
        $settings = new Performance_Settings();
        $settings->update( [ 'cache_duration' => '21600' ] );
        $policy = $settings->get_cache_policy();

        $this->assertSame( 'fixed', $policy['mode'] );
        $this->assertSame(
            [ 'soft_ttl' => 6 * HOUR_IN_SECONDS, 'hard_ttl' => 7 * HOUR_IN_SECONDS, 'jitter' => 0 ],
            $policy['default']
        );
        $this->assertSame( [], $policy['routes'] );
    }

    public function test_multisite_settings_and_event_history_are_blog_scoped() {
        if ( ! is_multisite() ) {
            $this->markTestSkipped( 'Multisite-only behavior lock.' );
        }

        $events = new \Directorist\Cache\Performance_Event_Log();
        ( new Performance_Settings() )->update( [ 'sample_rate' => 10 ] );
        $events->record( 'info', 'main-site-event' );
        $blog_id = self::factory()->blog->create();
        switch_to_blog( $blog_id );

        $this->assertSame( 0, ( new Performance_Settings() )->get()['sample_rate'] );
        $this->assertSame( [], ( new \Directorist\Cache\Performance_Event_Log() )->recent() );

        ( new Performance_Settings() )->update( [ 'sample_rate' => 25 ] );
        ( new \Directorist\Cache\Performance_Event_Log() )->record( 'info', 'sub-site-event' );
        $this->assertSame( $blog_id, ( new \Directorist\Cache\Performance_Event_Log() )->recent()[0]['site_id'] );
        restore_current_blog();

        $this->assertSame( 10, ( new Performance_Settings() )->get()['sample_rate'] );
        $this->assertSame( 'main-site-event', $events->recent()[0]['code'] );
    }
}
