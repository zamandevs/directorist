<?php
/**
 * Bounded operational and sampled eligibility history behavior locks.
 */

use Directorist\Cache\Performance_Event_Log;
use Directorist\Cache\Performance_Settings;

class Directorist_Page_Cache_Performance_Event_Log_Test extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option( Performance_Settings::OPTION_NAME );
        delete_option( Performance_Event_Log::OPTION_NAME );
    }

    protected function tearDown(): void {
        delete_option( Performance_Settings::OPTION_NAME );
        delete_option( Performance_Event_Log::OPTION_NAME );
        parent::tearDown();
    }

    public function test_history_is_bounded_sanitized_and_newest_first() {
        ( new Performance_Settings() )->update( [ 'history_limit' => 10 ] );
        $now = 1000;
        $log = new Performance_Event_Log(
            null,
            static function () use ( &$now ) {
                return $now++;
            }
        );

        for ( $index = 0; $index < 15; ++$index ) {
            $log->record( 'warning', 'HTTP Failed ' . $index, [ 'url' => '<script>unsafe</script>', 'nested' => [ 'drop' ] ] );
        }

        $events = $log->recent( 1015 );

        $this->assertCount( 10, $events );
        $this->assertSame( 1014, $events[0]['time'] );
        $this->assertSame( 'http-failed-14', $events[0]['code'] );
        $this->assertSame( '', $events[0]['context']['url'] );
        $this->assertArrayNotHasKey( 'nested', $events[0]['context'] );
    }

    public function test_sampling_off_performs_no_event_option_write() {
        $settings = new Performance_Settings();
        $log      = new Performance_Event_Log(
            $settings,
            null,
            static function () {
                return 0;
            }
        );

        $this->assertFalse( $log->maybe_sample( [ 'eligible' => false, 'reason' => 'cookie_present', 'route_type' => 'listings' ], 'begin' ) );
        $this->assertFalse( get_option( Performance_Event_Log::OPTION_NAME, false ) );

        $settings->update( [ 'sample_rate' => 100 ] );
        $this->assertFalse( $log->maybe_sample( [ 'eligible' => false, 'reason' => 'cookie_present', 'route_type' => 'listings' ], 'begin' ) );

        $settings->update( [ 'sample_rate' => 100, 'diagnostics_until' => time() + HOUR_IN_SECONDS ] );
        $this->assertTrue( $log->maybe_sample( [ 'eligible' => false, 'reason' => 'cookie_present', 'route_type' => 'listings' ], 'begin' ) );
        $this->assertSame( 'cookie_present', $log->recent()[0]['code'] );
    }

    public function test_expired_events_are_hidden_and_removed_on_the_next_bounded_write() {
        $now = 40 * DAY_IN_SECONDS;
        update_option(
            Performance_Event_Log::OPTION_NAME,
            [
                [ 'time' => 1, 'site_id' => 1, 'level' => 'info', 'code' => 'expired', 'context' => [] ],
            ],
            false
        );
        $log = new Performance_Event_Log(
            null,
            static function () use ( &$now ) {
                return $now;
            }
        );

        $this->assertSame( [], $log->recent( $now ) );
        $log->record( 'success', 'current' );

        $stored = get_option( Performance_Event_Log::OPTION_NAME, [] );
        $this->assertCount( 1, $stored );
        $this->assertSame( 'current', $stored[0]['code'] );
    }

    public function test_clear_is_idempotent() {
        $log = new Performance_Event_Log();
        $log->record( 'info', 'purged' );

        $this->assertTrue( $log->clear() );
        $this->assertTrue( $log->clear() );
        $this->assertSame( [], $log->recent() );
    }

    public function test_public_event_boundary_records_sanitized_provider_events() {
        $this->assertTrue(
            directorist_page_cache_record_performance_event(
                'error',
                'Worker Failed',
                [ 'provider' => 'directorist-cache', 'unsafe' => '<script>drop</script>' ]
            )
        );

        $event = ( new Performance_Event_Log() )->recent()[0];

        $this->assertSame( 'error', $event['level'] );
        $this->assertSame( 'worker-failed', $event['code'] );
        $this->assertSame( 'directorist-cache', $event['context']['provider'] );
        $this->assertSame( '', $event['context']['unsafe'] );
    }
}
