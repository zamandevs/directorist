<?php

use Directorist\Cache\Abstract_Cache_Provider;
use Directorist\Cache\Performance_Resource_Catalog;
use Directorist\Cache\Performance_Resource_Store;
use Directorist\Cache\WP_Fastest_Cache_Provider;

class Directorist_Page_Cache_Managed_Catalog_Regression_Test extends WP_UnitTestCase {
    private $store;

    protected function setUp(): void {
        parent::setUp();
        $this->store = new Performance_Resource_Store();
        $this->reset_store();
        $this->assertTrue( $this->store->create() );
        $this->store->begin_generation( 100 );
        foreach ( [ 'Cached before switch', 'Uncached before switch' ] as $title ) {
            $post = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title ] );
            $url = get_permalink( $post );
            $this->store->upsert( [ [ 'logical_key' => 'post-' . $post, 'object_id' => $post, 'title' => $title, 'url' => $url, 'type' => 'page', 'route_type' => 'page' ] ], 100 );
            if ( 'Cached before switch' === $title ) {
                $this->store->update_cache_state( $url, [ 'state' => 'current', 'created_at' => time(), 'expires_at' => time() + HOUR_IN_SECONDS ] );
            }
        }
        $this->store->complete_generation( 100 );
    }

    protected function tearDown(): void {
        $this->reset_store();
        parent::tearDown();
    }

    private function reset_store() {
        global $wpdb;
        $wpdb->query( 'DROP TABLE IF EXISTS ' . $this->store->table_name() );
        delete_option( Performance_Resource_Store::VERSION_OPTION );
        delete_option( Performance_Resource_Store::STATUS_OPTION );
    }

    private function external_catalog() {
        $provider = new WP_Fastest_Cache_Provider( [ 'purge_site' => static function () { return true; }, 'warm_urls' => static function () { return []; } ] );
        return new Performance_Resource_Catalog( $provider, null, null, null, $this->store );
    }

    public function test_external_current_filter_cannot_match_previous_builtin_entries() {
        $result = $this->external_catalog()->get_items( [ 'type' => 'page', 'cache_state' => 'current' ] );
        $this->assertSame( 0, $result['total'] );
        $this->assertSame( [], $result['items'] );
    }

    public function test_external_needs_refresh_filter_cannot_guess_from_builtin_entries() {
        $result = $this->external_catalog()->get_items( [ 'type' => 'page', 'cache_state' => 'needs-refresh' ] );
        $this->assertSame( 0, $result['total'] );
        $this->assertSame( [], $result['items'] );
    }

    public function test_external_url_discovery_does_not_use_previous_builtin_status() {
        $catalog = $this->external_catalog();
        $this->assertSame( 0, $catalog->get_urls( [ 'type' => 'page', 'cache_state' => 'needs-refresh' ] )['total'] );
        $this->assertSame( 2, $catalog->get_urls( [ 'type' => 'page' ] )['total'] );
    }

    public function test_external_unfiltered_and_managed_pagination_include_both_states() {
        $catalog = $this->external_catalog();
        foreach ( [ '', 'managed' ] as $state ) {
            $first = $catalog->get_items( [ 'type' => 'page', 'cache_state' => $state, 'per_page' => 1 ] );
            $second = $catalog->get_items( [ 'type' => 'page', 'cache_state' => $state, 'per_page' => 1, 'page' => 2 ] );
            $this->assertSame( 2, $first['total'] );
            $this->assertSame( 2, $second['total'] );
            $this->assertNotSame( $first['items'][0]['id'], $second['items'][0]['id'] );
            $this->assertSame( 'managed', $first['items'][0]['cache']['state'] );
            $this->assertFalse( $first['items'][0]['cache']['exact'] );
            $this->assertFalse( $first['items'][0]['actions']['purge'] );
        }
    }

    public function test_builtin_exact_status_filters_are_unchanged() {
        $provider = new class() extends Abstract_Cache_Provider {
            public function __construct() { $this->configure( 'directorist-cache', 'test', [], true ); }
        };
        $catalog = new Performance_Resource_Catalog( $provider, null, null, null, $this->store );
        $this->assertSame( 1, $catalog->get_items( [ 'type' => 'page', 'cache_state' => 'current' ] )['total'] );
        $this->assertSame( 1, $catalog->get_items( [ 'type' => 'page', 'cache_state' => 'needs-refresh' ] )['total'] );
    }

    public function test_explicit_custom_state_resolver_contract_is_preserved() {
        $provider = new WP_Fastest_Cache_Provider( [ 'purge_site' => static function () { return true; } ] );
        $catalog = new Performance_Resource_Catalog( $provider, null, static function () { return [ 'state' => 'current' ]; }, null, $this->store );
        $result = $catalog->get_items( [ 'type' => 'page', 'cache_state' => 'current' ] );
        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 'current', $result['items'][0]['cache']['state'] );
    }
}
