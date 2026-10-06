<?php
/**
 * Behavior locks for frontend count helpers and pagination boundaries.
 */

use Directorist\database\DB;

class Directorist_Pagination_Count_Behavior_Test extends WP_UnitTestCase {
    private $directory_one;

    private $directory_two;

    private $category_parent;

    private $category_child;

    private $location_parent;

    private $location_child;

    private $tag;

    private $listing_ids = [];

    public function set_up() {
        parent::set_up();

        directorist_clear_price_existence_cache();

        $this->directory_one = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Count Directory One',
            ]
        );
        $this->directory_two = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Count Directory Two',
            ]
        );
        $this->category_parent = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_CATEGORY,
                'name'     => 'Count Category Parent',
            ]
        );
        $this->category_child = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_CATEGORY,
                'name'     => 'Count Category Child',
                'parent'   => $this->category_parent,
            ]
        );
        $this->location_parent = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_LOCATION,
                'name'     => 'Count Location Parent',
            ]
        );
        $this->location_child = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_LOCATION,
                'name'     => 'Count Location Child',
                'parent'   => $this->location_parent,
            ]
        );
        $this->tag = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_TAGS,
                'name'     => 'Count Tag',
            ]
        );

        $this->listing_ids['one_parent'] = $this->create_listing(
            'Count One Parent',
            $this->directory_one,
            $this->category_parent,
            $this->location_parent,
            [ '_never_expire' => 1 ]
        );
        $this->listing_ids['one_child'] = $this->create_listing(
            'Count One Child',
            $this->directory_one,
            $this->category_child,
            $this->location_child,
            [ '_expiry_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ]
        );
        $this->listing_ids['two_child'] = $this->create_listing(
            'Count Two Child',
            $this->directory_two,
            $this->category_child,
            $this->location_child,
            [ '_expiry_date' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ]
        );
        $this->listing_ids['draft'] = $this->create_listing(
            'Count Draft Child',
            $this->directory_one,
            $this->category_child,
            $this->location_child,
            [ '_never_expire' => 1 ],
            'draft'
        );
    }

    public function test_category_count_includes_children_and_can_be_restricted_to_a_directory() {
        $this->assertSame( 3, atbdp_listings_count_by_category( $this->category_parent ) );
        $this->assertSame( 2, atbdp_listings_count_by_category( $this->category_parent, $this->directory_one ) );
        $this->assertSame( 1, atbdp_listings_count_by_category( $this->category_parent, $this->directory_two ) );
    }

    public function test_location_count_includes_children_and_can_be_restricted_to_a_directory() {
        $this->assertSame( 3, atbdp_listings_count_by_location( $this->location_parent ) );
        $this->assertSame( 2, atbdp_listings_count_by_location( $this->location_parent, $this->directory_one ) );
        $this->assertSame( 1, atbdp_listings_count_by_location( $this->location_parent, $this->directory_two ) );
    }

    public function test_taxonomy_model_batches_exact_counts_with_child_and_directory_semantics() {
        wp_set_object_terms(
            $this->listing_ids['one_child'],
            [ $this->category_parent, $this->category_child ],
            ATBDP_CATEGORY
        );

        $taxonomy                       = new \Directorist\Directorist_Listing_Taxonomy( [], 'category' );
        $taxonomy->tax                  = ATBDP_CATEGORY;
        $taxonomy->type                 = 'category';
        $taxonomy->view                 = 'list';
        $taxonomy->current_listing_type = $this->directory_one;

        $queries = [];
        $capture = static function( $sql ) use ( &$queries ) {
            if ( false !== strpos( $sql, 'directorist_taxonomy_count_batch' ) ) {
                $queries[] = $sql;
            }

            return $sql;
        };

        add_filter( 'query', $capture );
        $counts = $taxonomy->get_batched_listing_counts(
            [
                get_term( $this->category_parent, ATBDP_CATEGORY ),
                get_term( $this->category_child, ATBDP_CATEGORY ),
            ]
        );
        remove_filter( 'query', $capture );

        $this->assertSame( 2, $counts[ $this->category_parent ] );
        $this->assertSame( 1, $counts[ $this->category_child ] );
        $this->assertCount( 1, $queries );
        $this->assertStringContainsString( 'COUNT(DISTINCT directorist_count_rel.object_id)', $queries[0] );
    }

    public function test_taxonomy_batch_count_cache_invalidates_after_relationship_mutation() {
        $terms = [ get_term( $this->category_child, ATBDP_CATEGORY ) ];

        $taxonomy                       = new \Directorist\Directorist_Listing_Taxonomy( [], 'category' );
        $taxonomy->tax                  = ATBDP_CATEGORY;
        $taxonomy->type                 = 'category';
        $taxonomy->view                 = 'grid';
        $taxonomy->current_listing_type = $this->directory_one;
        $before                         = $taxonomy->get_batched_listing_counts( $terms );

        wp_set_object_terms( $this->listing_ids['one_parent'], $this->category_child, ATBDP_CATEGORY, true );

        $after_model                       = new \Directorist\Directorist_Listing_Taxonomy( [], 'category' );
        $after_model->tax                  = ATBDP_CATEGORY;
        $after_model->type                 = 'category';
        $after_model->view                 = 'grid';
        $after_model->current_listing_type = $this->directory_one;
        $after                             = $after_model->get_batched_listing_counts( $terms );

        $this->assertSame( 1, $before[ $this->category_child ] );
        $this->assertSame( 2, $after[ $this->category_child ] );
    }

    public function test_taxonomy_batch_count_can_fall_back_for_compatibility() {
        $taxonomy                       = new \Directorist\Directorist_Listing_Taxonomy( [], 'category' );
        $taxonomy->tax                  = ATBDP_CATEGORY;
        $taxonomy->type                 = 'category';
        $taxonomy->view                 = 'grid';
        $taxonomy->current_listing_type = $this->directory_one;

        add_filter( 'directorist_use_batched_taxonomy_listing_counts', '__return_false' );
        $counts = $taxonomy->get_batched_listing_counts( [ get_term( $this->category_parent, ATBDP_CATEGORY ) ] );
        remove_filter( 'directorist_use_batched_taxonomy_listing_counts', '__return_false' );

        $this->assertFalse( $counts );
    }

    public function test_tag_count_keeps_expiry_and_never_expire_semantics() {
        $this->assertSame( 2, atbdp_listings_count_by_tag( $this->tag ) );
    }

    public function test_count_helpers_return_zero_for_an_empty_term() {
        $empty_category = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_CATEGORY,
                'name'     => 'Empty Count Category',
            ]
        );

        $this->assertSame( 0, atbdp_listings_count_by_category( $empty_category ) );
    }

    public function test_taxonomy_count_query_is_bounded_and_explicitly_marked() {
        $captured = null;
        $capture  = static function( $query ) use ( &$captured ) {
            if ( 'taxonomy_exact_count' === $query->get( 'directorist_query_purpose' ) ) {
                $captured = $query;
            }
        };

        add_action( 'pre_get_posts', $capture );
        $this->assertSame( 3, atbdp_listings_count_by_category( $this->category_parent ) );
        remove_action( 'pre_get_posts', $capture );

        $this->assertInstanceOf( WP_Query::class, $captured );
        $this->assertSame( 1, $captured->get( 'posts_per_page' ) );
        $this->assertFalse( $captured->get( 'no_found_rows' ) );
        $this->assertSame( 'ids', $captured->get( 'fields' ) );
        $this->assertSame( 'none', $captured->get( 'orderby' ) );
    }

    public function test_repeated_taxonomy_count_reuses_query_cache_and_post_mutation_invalidates_it() {
        wp_cache_flush();

        $queries = [];
        $capture = static function( $sql ) use ( &$queries ) {
            if ( false !== strpos( $sql, 'SQL_CALC_FOUND_ROWS' ) && false !== strpos( $sql, "post_type = '" . ATBDP_POST_TYPE . "'" ) ) {
                $queries[] = $sql;
            }

            return $sql;
        };

        add_filter( 'query', $capture );
        $this->assertSame( 3, atbdp_listings_count_by_category( $this->category_parent ) );
        $first_queries = $queries;
        $queries       = [];

        $this->assertSame( 3, atbdp_listings_count_by_category( $this->category_parent ) );
        $this->assertSame( [], $queries );

        $this->create_listing(
            'Count Cache Mutation',
            $this->directory_one,
            $this->category_child,
            $this->location_child,
            [ '_never_expire' => 1 ]
        );

        $this->assertSame( 4, atbdp_listings_count_by_category( $this->category_parent ) );
        remove_filter( 'query', $capture );

        $this->assertNotEmpty( $first_queries );
        $this->assertNotEmpty( $queries );
    }

    public function test_price_existence_query_exposes_its_bounded_purpose() {
        $captured = null;
        $capture  = static function( $query ) use ( &$captured ) {
            if ( 'price_existence' === $query->get( 'directorist_query_purpose' ) ) {
                $captured = $query;
            }
        };

        add_action( 'pre_get_posts', $capture );
        $this->assertTrue( directorist_have_listings_with_price() );
        remove_action( 'pre_get_posts', $capture );

        $this->assertInstanceOf( WP_Query::class, $captured );
        $this->assertSame( 'price_existence', $captured->get( 'directorist_query_purpose' ) );
        $this->assertSame( 1, $captured->get( 'posts_per_page' ) );
        $this->assertTrue( $captured->get( 'no_found_rows' ) );
    }

    public function test_price_existence_request_cache_can_be_disabled() {
        $query_count = 0;
        $capture     = static function( $query ) use ( &$query_count ) {
            if ( 'price_existence' === $query->get( 'directorist_query_purpose' ) ) {
                ++$query_count;
            }
        };

        add_filter( 'directorist_cache_price_existence', '__return_false' );
        add_action( 'pre_get_posts', $capture );
        $this->assertTrue( directorist_have_listings_with_price() );
        $this->assertTrue( directorist_have_listings_with_price() );
        remove_action( 'pre_get_posts', $capture );
        remove_filter( 'directorist_cache_price_existence', '__return_false' );

        $this->assertSame( 2, $query_count );
    }

    public function test_price_existence_request_cache_is_invalidated_by_price_mutation() {
        foreach ( $this->listing_ids as $listing_id ) {
            delete_post_meta( $listing_id, '_price' );
        }

        $this->assertFalse( directorist_have_listings_with_price() );

        update_post_meta( $this->listing_ids['one_parent'], '_price', '' );
        $this->assertTrue( directorist_have_listings_with_price() );

        delete_post_meta( $this->listing_ids['one_parent'], '_price' );
        $this->assertFalse( directorist_have_listings_with_price() );
    }

    public function test_primary_collection_queries_receive_a_distinct_query_purpose() {
        $captured = null;
        $capture  = static function( $query ) use ( &$captured ) {
            if ( 'listing_collection' === $query->get( 'directorist_query_purpose' ) ) {
                $captured = $query;
            }
        };

        add_action( 'pre_get_posts', $capture );
        DB::get_listings_data(
            [
                'post_type'      => ATBDP_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 2,
            ]
        );
        remove_action( 'pre_get_posts', $capture );

        $this->assertInstanceOf( WP_Query::class, $captured );
        $this->assertFalse( $captured->get( 'no_found_rows' ) );
    }

    public function test_listing_order_lookup_is_bounded_without_changing_the_returned_order() {
        $listing_id = $this->listing_ids['one_parent'];
        $order_id   = self::factory()->post->create(
            [
                'post_type'   => 'atbdp_orders',
                'post_status' => 'publish',
                'post_title'  => 'Count Listing Order',
            ]
        );
        update_post_meta( $order_id, '_listing_id', $listing_id );

        $captured = null;
        $capture  = static function( $query ) use ( &$captured ) {
            if ( 'listing_order_lookup' === $query->get( 'directorist_query_purpose' ) ) {
                $captured = $query;
            }
        };

        add_action( 'pre_get_posts', $capture );
        $order = atbdp_get_listing_order( $listing_id );
        remove_action( 'pre_get_posts', $capture );

        $this->assertInstanceOf( WP_Post::class, $order );
        $this->assertSame( $order_id, $order->ID );
        $this->assertSame( 1, $captured->get( 'posts_per_page' ) );
        $this->assertTrue( $captured->get( 'no_found_rows' ) );
    }

    public function test_popular_and_related_widget_queries_skip_unused_totals() {
        $current_post = get_post( $this->listing_ids['one_parent'] );
        $previous     = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $current_post;
        setup_postdata( $current_post );

        $popular = new \Directorist\Widgets\Popular_Listings();
        $related = new \Directorist\Widgets\Similar_Listing();

        $popular_query = $popular->popular_listings_query( 2 );
        $related_query = $related->directorist_related_listings_query( 2 );

        wp_reset_postdata();
        $GLOBALS['post'] = $previous;

        $this->assertTrue( $popular_query->get( 'no_found_rows' ) );
        $this->assertSame( 'popular_listing_collection', $popular_query->get( 'directorist_query_purpose' ) );
        $this->assertTrue( $related_query->get( 'no_found_rows' ) );
        $this->assertSame( 'related_listing_collection', $related_query->get( 'directorist_query_purpose' ) );
        $this->assertLessThanOrEqual( 2, count( $popular_query->posts ) );
        $this->assertLessThanOrEqual( 2, count( $related_query->posts ) );
    }

    public function test_last_page_retains_exact_total_and_page_count() {
        $results = DB::get_listings_data(
            [
                'post_type'      => ATBDP_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'paged'          => 3,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]
        );

        $this->assertCount( 1, $results->ids );
        $this->assertSame( 3, $results->total );
        $this->assertSame( 3, $results->total_pages );
        $this->assertSame( 3, $results->current_page );
    }

    public function test_deep_empty_page_keeps_current_zero_total_contract() {
        $results = DB::get_listings_data(
            [
                'post_type'      => ATBDP_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'paged'          => 99,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]
        );

        $this->assertSame( [], $results->ids );
        $this->assertSame( 0, $results->total );
        $this->assertSame( 0, $results->total_pages );
        $this->assertSame( 99, $results->current_page );
    }

    private function create_listing( $title, $directory_id, $category_id, $location_id, array $meta, $post_status = 'publish' ) {
        $listing_id = self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => $post_status,
                'post_title'  => $title,
            ]
        );

        update_post_meta( $listing_id, '_directory_type', $directory_id );
        update_post_meta( $listing_id, '_price', 100 );
        wp_set_object_terms( $listing_id, $directory_id, ATBDP_DIRECTORY_TYPE );
        wp_set_object_terms( $listing_id, $category_id, ATBDP_CATEGORY );
        wp_set_object_terms( $listing_id, $location_id, ATBDP_LOCATION );
        wp_set_object_terms( $listing_id, $this->tag, ATBDP_TAGS );

        foreach ( $meta as $key => $value ) {
            update_post_meta( $listing_id, $key, $value );
        }

        return $listing_id;
    }
}
