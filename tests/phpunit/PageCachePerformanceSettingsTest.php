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
        $this->assertSame( 50, $settings['history_limit'] );
        $this->assertTrue( directorist_page_cache_is_enabled() );
    }

    public function test_update_normalizes_known_values_and_discards_unknown_input() {
        $settings = new Performance_Settings();
        $updated  = $settings->update(
            [
                'enabled'       => '0',
                'sample_rate'   => 7,
                'history_limit' => 1000,
                'unknown'       => 'discarded',
            ]
        );

        $this->assertFalse( $updated['enabled'] );
        $this->assertSame( 5, $updated['sample_rate'] );
        $this->assertSame( 100, $updated['history_limit'] );
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
            ]
        );

        $this->assertSame(
            [
                'enabled'       => true,
                'sample_rate'   => 0,
                'history_limit' => 50,
            ],
            ( new Performance_Settings() )->get()
        );
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
