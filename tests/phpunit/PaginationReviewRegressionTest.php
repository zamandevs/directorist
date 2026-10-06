<?php

use Directorist\Cache\Built_In\Cache_Engine;

require_once __DIR__ . '/fixtures/PerformanceReviewFixture.php';

/**
 * Cache bypass constants and request-static hook guards cannot be reset in PHP.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Directorist_Performance_Pagination_Review_Regression_Test extends Directorist_Performance_Review_Fixture {
    public function test_listing_order_lookup_preserves_latest_order_selection() {
        $listing = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ] );
        $old = self::factory()->post->create( [ 'post_type' => 'atbdp_orders', 'post_status' => 'publish', 'post_date' => '2024-01-01 12:00:00' ] );
        $latest = self::factory()->post->create( [ 'post_type' => 'atbdp_orders', 'post_status' => 'publish', 'post_date' => '2025-01-01 12:00:00' ] );
        update_post_meta( $old, '_listing_id', $listing );
        update_post_meta( $latest, '_listing_id', $listing );
        update_post_meta( $old, '_payment_status', 'pending' );
        update_post_meta( $latest, '_payment_status', 'completed' );
        $canonical = new WP_Query( [
            'post_type' => 'atbdp_orders', 'per_page' => 1,
            'meta_query' => [ [ 'key' => '_listing_id', 'value' => $listing, 'compare' => '=' ] ],
        ] );
        $this->assertSame( $latest, $canonical->post->ID );
        $this->assertSame( 'publish', directorist_get_featured_listing_status( $listing, 'publish' ), 'A completed renewal must not be evaluated using the older pending order.' );
        $this->assertSame( $latest, atbdp_get_listing_order( $listing )->ID, 'Optimized lookup must select the same order as the previous query.' );
    }

    public function test_batched_taxonomy_count_preserves_existing_query_filter_contract() {
        $category = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY ] );
        $visible = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ] );
        $hidden = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ] );
        wp_set_object_terms( $visible, [ $category ], ATBDP_CATEGORY );
        wp_set_object_terms( $hidden, [ $category ], ATBDP_CATEGORY );
        $restrict = static function ( $query ) use ( $visible ) {
            if ( ATBDP_POST_TYPE === $query->get( 'post_type' ) ) {
                $query->set( 'post__in', [ $visible ] );
            }
        };
        add_action( 'pre_get_posts', $restrict );
        try {
            $canonical = atbdp_listings_count_by_category( $category );
            $model = new \Directorist\Directorist_Listing_Taxonomy( [], 'category' );
            $model->current_listing_type = 0;
            $model->batched_term_counts = null;
            $counts = $model->get_batched_listing_counts( [ get_term( $category, ATBDP_CATEGORY ) ] );
            $this->assertSame( 1, $canonical );
            $method = new ReflectionMethod( $model, 'listing_count' );
            $method->setAccessible( true );
            $this->assertSame( $canonical, $method->invoke( $model, $category ), 'Count rendering bypassed the existing WP_Query restriction.' );
        } finally {
            remove_action( 'pre_get_posts', $restrict );
        }
    }
}
