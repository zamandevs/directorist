<?php
/**
 * Behavior locks for the current postmeta-backed listing query path.
 */

use Directorist\database\DB;

class Directorist_Listing_Query_Behavior_Test extends WP_UnitTestCase {
    private $directory_one;

    private $directory_two;

    private $category;

    private $listing_ids = [];

    public function set_up() {
        parent::set_up();

        $this->directory_one = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Directory One',
            ]
        );
        $this->directory_two = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Directory Two',
            ]
        );
        $this->category      = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_CATEGORY,
                'name'     => 'Shared Category',
            ]
        );

        $this->listing_ids['alpha'] = $this->create_listing(
            'Alpha',
            $this->directory_one,
            [
                '_featured'                  => 1,
                '_price'                     => 200,
                '_directorist_review_rating' => 4.5,
                '_atbdp_post_views_count'    => 40,
                '_select_1'                  => 'gold',
                '_checkbox_1'                => [ 'wifi', 'parking' ],
                '_number_1'                  => 45,
                '_text_1'                    => 'Quiet river view',
            ]
        );
        $this->listing_ids['beta']  = $this->create_listing(
            'Beta',
            $this->directory_one,
            [
                '_featured'                  => 0,
                '_price'                     => 100,
                '_directorist_review_rating' => 3.5,
                '_atbdp_post_views_count'    => 80,
                '_select_1'                  => 'silver',
                '_checkbox_1'                => [ 'parking' ],
                '_number_1'                  => 25,
                '_text_1'                    => 'Central city view',
            ]
        );
        $this->listing_ids['gamma'] = $this->create_listing(
            'Gamma',
            $this->directory_two,
            [
                '_featured'                  => 1,
                '_price'                     => 150,
                '_directorist_review_rating' => 5,
                '_atbdp_post_views_count'    => 120,
                '_select_1'                  => 'gold',
                '_checkbox_1'                => [ 'wifi' ],
                '_number_1'                  => 35,
                '_text_1'                    => 'Quiet mountain view',
            ]
        );
    }

    public function test_directory_meta_query_is_the_current_source_of_result_semantics() {
        $results = $this->query(
            [
                'meta_query' => [
                    [
                        'key'   => '_directory_type',
                        'value' => $this->directory_one,
                    ],
                ],
                'orderby'    => 'title',
                'order'      => 'ASC',
            ]
        );

        $this->assertSame( [ $this->listing_ids['alpha'], $this->listing_ids['beta'] ], $results->ids );
    }

    public function test_mismatched_directory_meta_and_taxonomy_are_not_equivalent() {
        $listing_id = $this->listing_ids['alpha'];
        wp_set_object_terms( $listing_id, $this->directory_two, ATBDP_DIRECTORY_TYPE );

        $meta_results = $this->query(
            [
                'meta_query' => [
                    [
                        'key'   => '_directory_type',
                        'value' => $this->directory_one,
                    ],
                ],
            ]
        );
        $tax_results  = $this->query(
            [
                'tax_query' => [
                    [
                        'taxonomy' => ATBDP_DIRECTORY_TYPE,
                        'field'    => 'term_id',
                        'terms'    => [ $this->directory_one ],
                    ],
                ],
            ]
        );

        $this->assertContains( $listing_id, $meta_results->ids );
        $this->assertNotContains( $listing_id, $tax_results->ids );
    }

    public function test_taxonomy_and_directory_meta_constraints_intersect() {
        wp_set_object_terms( $this->listing_ids['alpha'], $this->category, ATBDP_CATEGORY );
        wp_set_object_terms( $this->listing_ids['gamma'], $this->category, ATBDP_CATEGORY );

        $results = $this->query(
            [
                'meta_query' => [
                    [
                        'key'   => '_directory_type',
                        'value' => $this->directory_one,
                    ],
                ],
                'tax_query'  => [
                    [
                        'taxonomy' => ATBDP_CATEGORY,
                        'field'    => 'term_id',
                        'terms'    => [ $this->category ],
                    ],
                ],
            ]
        );

        $this->assertSame( [ $this->listing_ids['alpha'] ], $results->ids );
    }

    public function test_featured_and_price_ordering_preserves_current_order() {
        $results = $this->query(
            [
                'meta_query' => [
                    'relation'       => 'AND',
                    'directory_type' => [
                        'key'   => '_directory_type',
                        'value' => $this->directory_one,
                    ],
                    '_featured'      => [
                        'key'     => '_featured',
                        'compare' => 'EXISTS',
                        'type'    => 'NUMERIC',
                    ],
                    'price'          => [
                        'key'     => '_price',
                        'compare' => 'EXISTS',
                        'type'    => 'NUMERIC',
                    ],
                ],
                'orderby'    => [
                    '_featured' => 'DESC',
                    'price'     => 'ASC',
                ],
            ]
        );

        $this->assertSame( [ $this->listing_ids['alpha'], $this->listing_ids['beta'] ], $results->ids );
    }

    public function test_custom_field_meta_semantics_include_exact_multi_numeric_and_text() {
        $results = $this->query(
            [
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key'     => '_select_1',
                        'value'   => 'gold',
                        'compare' => '=',
                    ],
                    [
                        'key'     => '_checkbox_1',
                        'value'   => 'wifi',
                        'compare' => 'LIKE',
                    ],
                    [
                        'key'     => '_number_1',
                        'value'   => [ 30, 50 ],
                        'compare' => 'BETWEEN',
                        'type'    => 'NUMERIC',
                    ],
                    [
                        'key'     => '_text_1',
                        'value'   => 'river',
                        'compare' => 'LIKE',
                    ],
                ],
            ]
        );

        $this->assertSame( [ $this->listing_ids['alpha'] ], $results->ids );
    }

    public function test_found_rows_and_no_found_rows_contract() {
        $page_one = $this->query(
            [
                'posts_per_page' => 1,
                'paged'          => 1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]
        );
        $page_two = $this->query(
            [
                'posts_per_page' => 1,
                'paged'          => 2,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]
        );
        $unpaged  = $this->query(
            [
                'posts_per_page' => 2,
                'no_found_rows'  => true,
            ]
        );

        $this->assertSame( 3, $page_one->total );
        $this->assertSame( 3, $page_one->total_pages );
        $this->assertSame( 1, $page_one->current_page );
        $this->assertSame( 2, $page_two->current_page );
        $this->assertNotSame( $page_one->ids, $page_two->ids );
        $this->assertSame( 2, $unpaged->total );
        $this->assertSame( 1, $unpaged->total_pages );
    }

    private function create_listing( $title, $directory_id, array $meta ) {
        $listing_id = self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => $title,
            ]
        );

        update_post_meta( $listing_id, '_directory_type', $directory_id );
        wp_set_object_terms( $listing_id, $directory_id, ATBDP_DIRECTORY_TYPE );

        foreach ( $meta as $key => $value ) {
            update_post_meta( $listing_id, $key, $value );
        }

        return $listing_id;
    }

    private function query( array $args ) {
        return DB::get_listings_data(
            wp_parse_args(
                $args,
                [
                    'post_type'      => ATBDP_POST_TYPE,
                    'post_status'    => 'publish',
                    'posts_per_page' => 20,
                ]
            )
        );
    }
}
