<?php
/**
 * Persistent Performance resource catalog behavior locks.
 */

use Directorist\Cache\Performance_Resource_Store;

final class Directorist_Page_Cache_Performance_Resource_Store_Test extends WP_UnitTestCase {
    /** @var Performance_Resource_Store */
    private $store;

    protected function setUp(): void {
        parent::setUp();
        $this->store = new Performance_Resource_Store();
        $this->drop_table();
        delete_option( Performance_Resource_Store::VERSION_OPTION );
        delete_option( Performance_Resource_Store::STATUS_OPTION );
        $this->assertTrue( $this->store->create() );
    }

    protected function tearDown(): void {
        $this->drop_table();
        delete_option( Performance_Resource_Store::VERSION_OPTION );
        delete_option( Performance_Resource_Store::STATUS_OPTION );
        parent::tearDown();
    }

    public function test_translation_variants_are_persisted_as_one_logical_resource() {
        $english    = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ] );
        $swedish    = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ] );
        $generation = 100;
        $this->store->begin_generation( $generation );
        $stored = $this->store->upsert(
            [
                [ 'logical_key' => 'wpml-77', 'title' => 'English hotel', 'url' => home_url( '/en/hotel/' ), 'type' => 'listing', 'route_type' => 'listing', 'object_id' => $english, 'language' => 'en' ],
                [ 'logical_key' => 'wpml-77', 'title' => 'Swedish hotel', 'url' => home_url( '/sv/hotel/' ), 'type' => 'listing', 'route_type' => 'listing', 'object_id' => $swedish, 'language' => 'sv' ],
            ],
            $generation
        );
        $this->store->complete_generation( $generation );

        $result = $this->store->query( [ 'type' => 'listing', 'page' => 1, 'per_page' => 20 ] );

        $this->assertSame( 2, $stored );
        $this->assertTrue( $result['ready'] );
        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 2, $result['items'][0]['variants'] );
        $this->assertEqualsCanonicalizing( [ 'en', 'sv' ], $result['items'][0]['languages'] );
    }

    public function test_unpublished_translation_variants_are_excluded_before_reconciliation_completes() {
        $published = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Published translation' ] );
        $draft     = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Draft translation' ] );
        $this->store->begin_generation( 100 );
        $this->store->upsert(
            [
                [ 'logical_key' => 'wpml-88', 'title' => 'Published translation', 'url' => get_permalink( $published ), 'type' => 'listing', 'route_type' => 'listing', 'object_id' => $published, 'language' => 'en' ],
                [ 'logical_key' => 'wpml-88', 'title' => 'Draft translation', 'url' => home_url( '/?post_type=at_biz_dir&p=' . $draft ), 'type' => 'listing', 'route_type' => 'listing', 'object_id' => $draft, 'language' => 'no' ],
            ],
            100
        );
        $this->store->complete_generation( 100 );
        remove_action( 'transition_post_status', 'directorist_page_cache_sync_resource_post_visibility', 30 );
        wp_update_post( [ 'ID' => $draft, 'post_status' => 'draft' ] );
        add_action( 'transition_post_status', 'directorist_page_cache_sync_resource_post_visibility', 30, 3 );

        $result = $this->store->query( [ 'type' => 'listing' ] );

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( [ get_permalink( $published ) ], $result['items'][0]['variant_urls'] );
        $this->assertSame( [ 'en' ], $result['items'][0]['languages'] );
        $this->assertSame( 1, $result['items'][0]['variants'] );
        $this->assertSame( 1, $this->store->coverage()['total'] );
    }

    public function test_stale_generation_cannot_update_or_complete_a_newer_catalog_build() {
        $this->store->begin_generation( 100 );
        $this->store->upsert( [ [ 'logical_key' => 'old', 'title' => 'Old', 'url' => home_url( '/old/' ), 'type' => 'page', 'route_type' => 'page' ] ], 100 );
        $this->store->begin_generation( 200 );
        $this->store->upsert( [ [ 'logical_key' => 'new', 'title' => 'New', 'url' => home_url( '/new/' ), 'type' => 'page', 'route_type' => 'page' ] ], 200 );

        $updated   = $this->store->update_generation_status( 100, [ 'state' => 'failed', 'code' => 'stale-worker' ] );
        $completed = $this->store->complete_generation( 100 );
        $status    = $this->store->status();

        $this->assertFalse( $updated );
        $this->assertFalse( $completed );
        $this->assertSame( 'building', $status['state'] );
        $this->assertSame( 200, $status['generation'] );
        $this->assertSame( 2, $this->raw_count() );
    }

    public function test_restarted_generation_advances_identity_and_retains_latest_active_catalog() {
        $this->store->begin_generation( 100 );
        $this->store->upsert( [ [ 'logical_key' => 'current', 'title' => 'Current', 'url' => home_url( '/current/' ), 'type' => 'page', 'route_type' => 'page' ] ], 100 );
        $this->store->complete_generation( 100 );

        $status = $this->store->begin_generation( 100 );

        $this->assertSame( 'building', $status['state'] );
        $this->assertSame( 101, $status['generation'] );
        $this->assertSame( 100, $status['active_generation'] );
        $this->assertSame( 'current', $this->store->query( [ 'type' => 'page' ] )['items'][0]['logical_key'] );
    }

    public function test_successful_admin_purge_recorder_marks_exact_and_site_resources_uncached() {
        $first  = home_url( '/first-cacheable-page/' );
        $second = home_url( '/second-cacheable-page/' );
        $this->store->begin_generation( 100 );
        $this->store->upsert(
            [
                [ 'logical_key' => 'first', 'title' => 'First', 'url' => $first, 'type' => 'page', 'route_type' => 'page' ],
                [ 'logical_key' => 'second', 'title' => 'Second', 'url' => $second, 'type' => 'page', 'route_type' => 'page' ],
            ],
            100
        );
        $this->store->complete_generation( 100 );
        $cached = [ 'state' => 'current', 'created_at' => time(), 'expires_at' => time() + HOUR_IN_SECONDS, 'stale_until' => time() + DAY_IN_SECONDS ];
        $this->store->update_cache_state( $first, $cached );
        $this->store->update_cache_state( $second, $cached );

        $this->assertTrue( directorist_page_cache_record_resource_purge( [ 'success' => true ], [ 'urls' => [ $first ], 'conservative' => false ] ) );
        $items = $this->store->query( [ 'type' => 'page' ] )['items'];
        $this->assertSame( 'uncached', $items[0]['stored_cache_state'] );
        $this->assertSame( 'current', $items[1]['stored_cache_state'] );

        $this->assertTrue( directorist_page_cache_record_resource_purge( [ 'success' => true ], [ 'urls' => [], 'conservative' => true ] ) );
        $items = $this->store->query( [ 'type' => 'page' ] )['items'];
        $this->assertSame( [ 'uncached', 'uncached' ], wp_list_pluck( $items, 'stored_cache_state' ) );
        $this->assertSame( [ 0, 0 ], wp_list_pluck( wp_list_pluck( $items, 'stored_cache' ), 'created_at' ) );
    }

    public function test_unpublished_post_rows_are_not_queryable_even_before_cleanup() {
        $listing = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Published listing' ] );
        $this->store->begin_generation( 100 );
        $this->store->upsert( [ [ 'logical_key' => 'listing-' . $listing, 'title' => 'Published listing', 'url' => get_permalink( $listing ), 'type' => 'listing', 'route_type' => 'listing', 'object_id' => $listing ] ], 100 );
        $this->store->complete_generation( 100 );

        remove_action( 'transition_post_status', 'directorist_page_cache_sync_resource_post_visibility', 30 );
        wp_update_post( [ 'ID' => $listing, 'post_status' => 'private' ] );
        add_action( 'transition_post_status', 'directorist_page_cache_sync_resource_post_visibility', 30, 3 );

        $this->assertSame( 0, $this->store->query( [ 'type' => 'listing' ] )['total'] );
        $this->assertSame( 0, $this->store->coverage()['total'] );
        $this->assertSame( [], $this->store->get_variants( get_permalink( $listing ), 'listing' ) );
    }

    public function test_post_resource_removal_returns_urls_and_removes_every_generation() {
        $listing = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ] );
        $url     = get_permalink( $listing );
        $this->store->begin_generation( 100 );
        $this->store->upsert( [ [ 'logical_key' => 'listing-' . $listing, 'title' => 'Listing', 'url' => $url, 'type' => 'listing', 'route_type' => 'listing', 'object_id' => $listing ] ], 100 );
        $this->store->complete_generation( 100 );
        $this->store->begin_generation( 200 );
        $this->store->upsert( [ [ 'logical_key' => 'listing-' . $listing, 'title' => 'Listing', 'url' => $url, 'type' => 'listing', 'route_type' => 'listing', 'object_id' => $listing ] ], 200 );

        $result = $this->store->remove_post_resources( $listing );

        $this->assertTrue( $result['success'] );
        $this->assertSame( [ $url ], $result['urls'] );
        $this->assertSame( 2, $result['removed'] );
        $this->assertSame( 0, $this->raw_count() );
    }

    public function test_resource_titles_are_entity_decoded_before_persistence() {
        $this->store->begin_generation( 100 );
        $this->store->upsert(
            [
                [
                    'logical_key' => 'encoded-title',
                    'title'       => 'Agios Nikolaos &#8211; Gialos &lt;script&gt;',
                    'url'         => home_url( '/directory/agios-nikolaos-gialos/' ),
                    'type'        => 'listing',
                    'route_type'  => 'listing',
                ],
            ],
            100
        );
        $this->store->complete_generation( 100 );

        $expected = html_entity_decode( 'Agios Nikolaos &#8211; Gialos', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $result   = $this->store->query( [ 'type' => 'listing' ] );

        $this->assertSame( $expected, $result['items'][0]['title'] );
    }

    public function test_variant_lookup_resolves_the_complete_active_logical_resource() {
        $this->store->begin_generation( 100 );
        $this->store->upsert(
            [
                [ 'logical_key' => 'wpml-77', 'title' => 'English hotel', 'url' => home_url( '/en/hotel/' ), 'type' => 'listing', 'route_type' => 'listing', 'language' => 'en' ],
                [ 'logical_key' => 'wpml-77', 'title' => 'Swedish hotel', 'url' => home_url( '/sv/hotel/' ), 'type' => 'listing', 'route_type' => 'listing', 'language' => 'sv' ],
                [ 'logical_key' => 'other', 'title' => 'Other page', 'url' => home_url( '/other/' ), 'type' => 'page', 'route_type' => 'page' ],
            ],
            100
        );
        $this->store->complete_generation( 100 );

        $variants = $this->store->get_variants( home_url( '/sv/hotel/' ), 'listing' );

        $this->assertSame( [ home_url( '/en/hotel/' ), home_url( '/sv/hotel/' ) ], wp_list_pluck( $variants, 'url' ) );
        $this->assertSame( [ 'en', 'sv' ], wp_list_pluck( $variants, 'language' ) );
        $this->assertSame( [], $this->store->get_variants( home_url( '/sv/hotel/' ), 'page' ) );
    }

    public function test_old_rows_are_pruned_only_after_a_generation_completes() {
        $this->store->begin_generation( 100 );
        $this->store->upsert( [ [ 'logical_key' => 'old', 'title' => 'Old', 'url' => home_url( '/old/' ), 'type' => 'page', 'route_type' => 'page' ] ], 100 );
        $this->store->complete_generation( 100 );
        $this->store->begin_generation( 200 );
        $this->store->upsert( [ [ 'logical_key' => 'new', 'title' => 'New', 'url' => home_url( '/new/' ), 'type' => 'page', 'route_type' => 'page' ] ], 200 );

        $during = $this->raw_count();
        $this->store->complete_generation( 200 );

        $this->assertSame( 2, $during );
        $this->assertSame( 1, $this->raw_count() );
        $this->assertSame( 'new', $this->store->query( [ 'type' => 'page' ] )['items'][0]['logical_key'] );
    }

    public function test_cache_state_is_queryable_without_reading_cache_bodies() {
        $this->store->begin_generation( 100 );
        $this->store->upsert(
            [
                [ 'logical_key' => 'one', 'title' => 'One', 'url' => home_url( '/one/' ), 'type' => 'page', 'route_type' => 'page' ],
                [ 'logical_key' => 'two', 'title' => 'Two', 'url' => home_url( '/two/' ), 'type' => 'page', 'route_type' => 'page' ],
            ],
            100
        );
        $this->store->complete_generation( 100 );
        $this->assertTrue( $this->store->update_cache_state( home_url( '/two/' ), [ 'state' => 'current', 'created_at' => time(), 'expires_at' => time() + HOUR_IN_SECONDS ] ) );

        $result = $this->store->query( [ 'type' => 'page', 'cache_state' => 'current' ] );

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 'two', $result['items'][0]['logical_key'] );
    }

    public function test_logical_resource_is_current_only_when_every_translation_variant_is_current() {
        $this->store->begin_generation( 100 );
        $this->store->upsert(
            [
                [ 'logical_key' => 'wpml-77', 'title' => 'English hotel', 'url' => home_url( '/en/hotel/' ), 'type' => 'listing', 'route_type' => 'listing', 'language' => 'en' ],
                [ 'logical_key' => 'wpml-77', 'title' => 'Swedish hotel', 'url' => home_url( '/sv/hotel/' ), 'type' => 'listing', 'route_type' => 'listing', 'language' => 'sv' ],
            ],
            100
        );
        $this->store->complete_generation( 100 );
        $this->store->update_cache_state( home_url( '/en/hotel/' ), [ 'state' => 'current', 'created_at' => time(), 'expires_at' => time() + HOUR_IN_SECONDS ] );

        $current = $this->store->query( [ 'type' => 'listing', 'cache_state' => 'current' ] );
        $needed  = $this->store->query( [ 'type' => 'listing', 'cache_state' => 'needs-refresh' ] );

        $this->assertSame( 0, $current['total'] );
        $this->assertSame( 1, $needed['total'] );
        $this->assertSame( 'uncached', $needed['items'][0]['stored_cache_state'] );

        $this->store->update_cache_state( home_url( '/sv/hotel/' ), [ 'state' => 'current', 'created_at' => time(), 'expires_at' => time() + HOUR_IN_SECONDS ] );
        $this->assertSame( 1, $this->store->query( [ 'type' => 'listing', 'cache_state' => 'current' ] )['total'] );
    }

    public function test_cache_coverage_counts_logical_resources_by_type_and_requires_every_variant() {
        $this->store->begin_generation( 100 );
        $this->store->upsert(
            [
                [ 'logical_key' => 'wpml-77', 'title' => 'English hotel', 'url' => home_url( '/en/hotel/' ), 'type' => 'listing', 'route_type' => 'listing', 'language' => 'en' ],
                [ 'logical_key' => 'wpml-77', 'title' => 'Swedish hotel', 'url' => home_url( '/sv/hotel/' ), 'type' => 'listing', 'route_type' => 'listing', 'language' => 'sv' ],
                [ 'logical_key' => 'about', 'title' => 'About', 'url' => home_url( '/about/' ), 'type' => 'page', 'route_type' => 'page' ],
                [ 'logical_key' => 'search-hotels', 'title' => 'Hotel search', 'url' => home_url( '/search/?q=hotel' ), 'type' => 'search', 'route_type' => 'search' ],
            ],
            100
        );
        $this->store->complete_generation( 100 );
        $this->store->update_cache_state( home_url( '/en/hotel/' ), [ 'state' => 'current', 'created_at' => time(), 'expires_at' => time() + HOUR_IN_SECONDS ] );
        $this->store->update_cache_state( home_url( '/about/' ), [ 'state' => 'current', 'created_at' => time(), 'expires_at' => time() + HOUR_IN_SECONDS ] );
        $this->store->update_cache_state( home_url( '/search/?q=hotel' ), [ 'state' => 'current', 'created_at' => 10, 'expires_at' => 20 ] );

        $coverage = $this->store->coverage();

        $this->assertTrue( $coverage['ready'] );
        $this->assertSame( 3, $coverage['total'] );
        $this->assertSame( 1, $coverage['cached'] );
        $this->assertSame( 2, $coverage['needs_refresh'] );
        $this->assertSame( [ 'total' => 1, 'cached' => 0, 'needs_refresh' => 1 ], $coverage['types']['listing'] );
        $this->assertSame( [ 'total' => 0, 'cached' => 0, 'needs_refresh' => 0 ], $coverage['types']['archive'] );
        $this->assertSame( [ 'total' => 1, 'cached' => 1, 'needs_refresh' => 0 ], $coverage['types']['page'] );
        $this->assertSame( [ 'total' => 1, 'cached' => 0, 'needs_refresh' => 1 ], $coverage['types']['search'] );

        $this->store->update_cache_state( home_url( '/sv/hotel/' ), [ 'state' => 'current', 'created_at' => time(), 'expires_at' => time() + HOUR_IN_SECONDS ] );
        $updated = $this->store->coverage();

        $this->assertSame( 2, $updated['cached'] );
        $this->assertSame( 1, $updated['types']['listing']['cached'] );
    }

    public function test_expired_entries_are_refreshed_before_status_filtering() {
        $this->store->begin_generation( 100 );
        $this->store->upsert( [ [ 'logical_key' => 'old', 'title' => 'Old', 'url' => home_url( '/old/' ), 'type' => 'page', 'route_type' => 'page' ] ], 100 );
        $this->store->complete_generation( 100 );
        $this->store->update_cache_state( home_url( '/old/' ), [ 'state' => 'current', 'created_at' => 10, 'expires_at' => 20 ] );

        $result = $this->store->query( [ 'type' => 'page', 'cache_state' => 'expired' ] );

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 'expired', $result['items'][0]['stored_cache_state'] );
    }

    public function test_soft_expired_entries_are_refreshing_until_the_hard_boundary() {
        $this->store->begin_generation( 100 );
        $url = home_url( '/stale/' );
        $this->store->upsert( [ [ 'logical_key' => 'stale', 'title' => 'Stale', 'url' => $url, 'type' => 'page', 'route_type' => 'page' ] ], 100 );
        $this->store->complete_generation( 100 );
        $this->store->update_cache_state( $url, [ 'state' => 'current', 'created_at' => 10, 'expires_at' => 20, 'stale_until' => time() + HOUR_IN_SECONDS ] );

        $result = $this->store->query( [ 'type' => 'page', 'cache_state' => 'stale' ] );

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 'stale', $result['items'][0]['stored_cache_state'] );
    }

    public function test_due_refresh_lookup_is_bounded_and_suppresses_recent_claims() {
        $this->store->begin_generation( 100 );
        $urls = [ home_url( '/due-one/' ), home_url( '/due-two/' ), home_url( '/later/' ) ];
        $this->store->upsert(
            [
                [ 'logical_key' => 'one', 'title' => 'One', 'url' => $urls[0], 'type' => 'page', 'route_type' => 'page' ],
                [ 'logical_key' => 'two', 'title' => 'Two', 'url' => $urls[1], 'type' => 'page', 'route_type' => 'page' ],
                [ 'logical_key' => 'later', 'title' => 'Later', 'url' => $urls[2], 'type' => 'page', 'route_type' => 'page' ],
            ],
            100
        );
        $this->store->complete_generation( 100 );
        $now = time();
        $this->store->update_cache_state( $urls[0], [ 'state' => 'current', 'expires_at' => $now + 10, 'stale_until' => $now + HOUR_IN_SECONDS ] );
        $this->store->update_cache_state( $urls[1], [ 'state' => 'current', 'expires_at' => $now + 20, 'stale_until' => $now + HOUR_IN_SECONDS ] );
        $this->store->update_cache_state( $urls[2], [ 'state' => 'current', 'expires_at' => $now + HOUR_IN_SECONDS, 'stale_until' => $now + 2 * HOUR_IN_SECONDS ] );

        $this->assertSame( [ $urls[0] ], $this->store->due_refresh_urls( $now + 30, 1, $now - 300 ) );
        $this->assertTrue( $this->store->mark_refresh_requested( [ $urls[0] ], $now ) );
        $this->assertSame( [ $urls[1] ], $this->store->due_refresh_urls( $now + 30, 5, $now - 300 ) );
    }

    public function test_partial_cache_state_updates_preserve_existing_cache_timestamps() {
        $this->store->begin_generation( 100 );
        $url = home_url( '/preserve/' );
        $this->store->upsert( [ [ 'logical_key' => 'preserve', 'title' => 'Preserve', 'url' => $url, 'type' => 'page', 'route_type' => 'page' ] ], 100 );
        $this->store->complete_generation( 100 );
        $this->store->update_cache_state( $url, [ 'state' => 'current', 'created_at' => 100, 'expires_at' => time() + 600, 'stale_until' => time() + 1200 ] );
        $this->store->update_cache_state( $url, [ 'state' => 'failed' ] );

        $item = $this->store->query( [ 'type' => 'page', 'cache_state' => 'failed' ] )['items'][0]['stored_cache'];

        $this->assertSame( 100, $item['created_at'] );
        $this->assertGreaterThan( time(), $item['expires_at'] );
    }

    public function test_failure_reason_is_persisted_and_cleared_after_verified_success() {
        $this->store->begin_generation( 100 );
        $url = home_url( '/failure-reason/' );
        $this->store->upsert( [ [ 'logical_key' => 'failure-reason', 'title' => 'Failure reason', 'url' => $url, 'type' => 'page', 'route_type' => 'page' ] ], 100 );
        $this->store->complete_generation( 100 );

        directorist_page_cache_record_resource_warm_result( $url, [ 'success' => false, 'code' => 'http-status-503' ] );
        $failed = $this->store->query( [ 'type' => 'page', 'cache_state' => 'failed' ] )['items'][0];

        $this->assertSame( 'http-status-503', $failed['stored_cache']['failure_code'] );
        $this->assertSame( 'http-status-503', $failed['failures'][0]['code'] );
        $this->assertGreaterThan( 0, $failed['failures'][0]['checked_at'] );

        $this->store->update_cache_state( $url, [ 'state' => 'current', 'created_at' => time(), 'expires_at' => time() + HOUR_IN_SECONDS ] );
        $current = $this->store->query( [ 'type' => 'page', 'cache_state' => 'current' ] )['items'][0];

        $this->assertSame( '', $current['stored_cache']['failure_code'] );
        $this->assertSame( [], $current['failures'] );
    }

    public function test_missing_table_is_reported_without_using_a_cached_positive_result() {
        global $wpdb;

        $this->assertTrue( $this->store->exists() );
        $prefix       = $wpdb->prefix;
        $wpdb->prefix = 'missing_' . wp_generate_password( 8, false ) . '_';

        try {
            $this->assertFalse( $this->store->exists() );
            $this->assertFalse( $this->store->query( [ 'type' => 'all' ] )['ready'] );
            $this->assertFalse( $this->store->coverage()['ready'] );
        } finally {
            $wpdb->prefix = $prefix;
        }
    }

    private function raw_count() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->store->table_name() );
    }

    private function drop_table() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query( 'DROP TABLE IF EXISTS ' . $this->store->table_name() );
    }
}
