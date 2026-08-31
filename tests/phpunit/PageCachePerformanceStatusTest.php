<?php
/**
 * Concise cache outcome status behavior locks.
 */

use Directorist\Cache\Cache_Provider;
use Directorist\Cache\Performance_Event_Log;
use Directorist\Cache\Performance_Settings;
use Directorist\Cache\Performance_Status;

final class Directorist_Page_Cache_Status_Test_Provider implements Cache_Provider {
    public $inventory_requests = [];

    public function get_id() {
        return 'directorist-cache';
    }

    public function is_available() {
        return true;
    }

    public function get_capabilities() {
        return [ 'purge_site', 'purge_url', 'warm_urls' ];
    }

    public function supports( $capability ) {
        return in_array( $capability, $this->get_capabilities(), true );
    }

    public function invalidate( array $request ) {
        unset( $request );

        return [ 'success' => true, 'code' => 'invalidated' ];
    }

    public function warm( array $urls ) {
        return [ 'success' => true, 'code' => 'queued', 'queued' => count( $urls ) ];
    }

    public function get_status() {
        return [
            'available' => true,
        ];
    }

    public function get_inventory( array $args = [] ) {
        $this->inventory_requests[] = $args;

        return [
            'success'          => true,
            'code'             => 'ready',
            'entries'          => 42,
            'cached_entries'   => 40,
            'inactive_entries' => 2,
            'matched_entries'  => 1,
            'bytes'            => 8192,
            'cached_bytes'     => 6144,
            'inactive_bytes'   => 2048,
            'orphans'          => 1,
            'generations'      => 7,
            'truncated'        => false,
            'groups'           => [
                'listing' => [ 'route_type' => 'listing', 'entries' => 1, 'bytes' => 2048 ],
            ],
            'items'            => [
                [
                    'hash'          => str_repeat( 'a', 64 ),
                    'canonical_url' => home_url( '/directory/sample/' ),
                    'route_type'    => 'listing',
                    'created_at'    => 100,
                    'expires_at'    => 200,
                    'stale_until'   => 230,
                    'body_size'     => 2048,
                    'state'         => 'current',
                    'language'      => 'en',
                    'has_query'     => false,
                    'site_id'       => 1,
                    'object_id'     => 91,
                    'page_id'       => 10,
                ],
            ],
            'page'             => isset( $args['page'] ) ? $args['page'] : 1,
            'per_page'         => 20,
            'pages'            => 2,
            'route_type'       => isset( $args['route_type'] ) ? $args['route_type'] : '',
        ];
    }
}

final class Directorist_Page_Cache_Performance_Status_Test extends WP_UnitTestCase {
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

    public function test_snapshot_reports_delivery_inventory_queues_and_compact_last_outcomes() {
        $events = new Performance_Event_Log();
        $events->record( 'success', 'cache-invalidated', [ 'provider' => 'directorist-cache' ] );
        $events->record( 'success', 'automatic-warm-queued', [ 'queued' => 3 ] );
        $events->record( 'success', 'cleanup-completed', [ 'removed' => 2 ] );
        $provider = new Directorist_Page_Cache_Status_Test_Provider();
        $status   = $this->status( $events, $provider )->snapshot( [ 'page' => 2, 'route_type' => 'listing' ] );

        $this->assertSame( 'optimized', $status['outcome'] );
        $this->assertSame( 'built_in', $status['delivery']['mode'] );
        $this->assertSame( 'directorist-cache', $status['delivery']['provider'] );
        $this->assertSame( 42, $status['metrics']['entries'] );
        $this->assertSame( 40, $status['metrics']['cached_entries'] );
        $this->assertSame( 2, $status['metrics']['inactive_entries'] );
        $this->assertSame( 8192, $status['metrics']['bytes'] );
        $this->assertSame( 6144, $status['metrics']['cached_bytes'] );
        $this->assertSame( 2048, $status['metrics']['inactive_bytes'] );
        $this->assertSame( [ [ 'page' => 2, 'route_type' => 'listing' ] ], $provider->inventory_requests );
        $this->assertSame( '/directory/sample/', wp_parse_url( $status['inventory']['items'][0]['canonical_url'], PHP_URL_PATH ) );
        $this->assertSame( 'listing', $status['inventory']['groups']['listing']['route_type'] );
        $this->assertSame( 4, $status['warm_queue']['queued'] );
        $this->assertTrue( $status['cleanup']['pending'] );
        $this->assertSame( 'cleanup-completed', $status['last_outcomes']['cleanup']['code'] );
        $this->assertSame( 'automatic-warm-queued', $status['last_outcomes']['warm']['code'] );
        $this->assertSame( 'cache-invalidated', $status['last_outcomes']['invalidation']['code'] );
        $this->assertSame( [], $status['events'] );
        $this->assertFalse( $status['diagnostics']['active'] );
    }

    public function test_raw_events_are_exposed_only_during_the_expiring_diagnostics_window() {
        ( new Performance_Settings() )->update( [ 'diagnostics_until' => time() + HOUR_IN_SECONDS ] );
        $events = new Performance_Event_Log();
        $events->record( 'warning', 'diagnostic-event' );

        $snapshot = $this->status( $events )->snapshot();

        $this->assertTrue( $snapshot['diagnostics']['active'] );
        $this->assertSame( 'diagnostic-event', $snapshot['events'][0]['code'] );
    }

    public function test_lightweight_snapshot_does_not_scan_physical_cache_inventory() {
        $provider = new Directorist_Page_Cache_Status_Test_Provider();
        $snapshot = $this->status( new Performance_Event_Log(), $provider )->snapshot( [ 'include_inventory' => false ] );

        $this->assertSame( [], $provider->inventory_requests );
        $this->assertFalse( $snapshot['inventory']['supported'] );
        $this->assertSame( 'not-requested', $snapshot['inventory']['code'] );
    }

    private function status( Performance_Event_Log $events, Cache_Provider $provider = null ) {
        return new Performance_Status(
            $provider ?: new Directorist_Page_Cache_Status_Test_Provider(),
            new Performance_Settings(),
            $events,
            static function () {
                return [
                    'success'    => true,
                    'state'      => 'built_in',
                    'code'       => 'runtime_current',
                    'provider'   => 'directorist-cache',
                    'updated_at' => time(),
                ];
            },
            static function () {
                return [ 'queued' => 4, 'running' => false, 'circuit_open' => false ];
            },
            static function () {
                return [ 'pending' => true, 'running' => false, 'queued' => true ];
            }
        );
    }
}
