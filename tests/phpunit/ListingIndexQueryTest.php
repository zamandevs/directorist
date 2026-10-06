<?php
/**
 * Legacy-versus-index listing query parity tests.
 */

use Directorist\database\DB;
use Directorist\database\Listing_Index;
use Directorist\database\Listing_Index_Directory_State;
use Directorist\database\Listing_Index_Maintenance;
use Directorist\database\Listing_Index_Query;
use Directorist\database\Listing_Index_Schema;

class Directorist_Listing_Index_Query_Test extends WP_UnitTestCase {
    private $directory;

    private $other_directory;

    private $category;

    private $listing_ids = [];

    private $captured_sql = '';

    public function set_up() {
        parent::set_up();

        global $wpdb;

        Listing_Index_Schema::create();
        Listing_Index::register_hooks();
        Listing_Index_Query::register_hooks();
        delete_option( Listing_Index::AMBIGUOUS_META_OPTION );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        foreach ( Listing_Index_Schema::field_tables() as $field_table ) {
            $wpdb->query( "DELETE FROM {$field_table}" );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::listing_table() );

        $this->directory       = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Lookup Directory',
            ]
        );
        $this->other_directory = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Other Directory',
            ]
        );
        $this->category        = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_CATEGORY,
                'name'     => 'Lookup Category',
            ]
        );

        update_term_meta(
            $this->directory,
            'search_form_fields',
            [
                'fields' => [
                    'select_1'   => [ 'widget_name' => 'select', 'widget_key' => 'select_1' ],
                    'checkbox_1' => [ 'widget_name' => 'checkbox', 'widget_key' => 'checkbox_1' ],
                    'number_1'   => [ 'widget_name' => 'number', 'widget_key' => 'number_1' ],
                    'date_1'     => [ 'widget_name' => 'date', 'widget_key' => 'date_1' ],
                    'time_1'     => [ 'widget_name' => 'time', 'widget_key' => 'time_1' ],
                    'text_1'     => [ 'widget_name' => 'text', 'widget_key' => 'text_1' ],
                    'textarea_1' => [ 'widget_name' => 'textarea', 'widget_key' => 'textarea_1' ],
                    'url_1'      => [ 'widget_name' => 'url', 'widget_key' => 'url_1' ],
                ],
            ]
        );
        update_term_meta(
            $this->directory,
            'submission_form_fields',
            [
                'fields' => [
                    'select_1'   => [ 'widget_name' => 'select', 'widget_key' => 'select_1', 'field_key' => 'custom-select-1' ],
                    'checkbox_1' => [ 'widget_name' => 'checkbox', 'widget_key' => 'checkbox_1', 'field_key' => 'custom-checkbox-1' ],
                    'number_1'   => [ 'widget_name' => 'number', 'widget_key' => 'number_1', 'field_key' => 'custom-number-1' ],
                    'date_1'     => [ 'widget_name' => 'date', 'widget_key' => 'date_1', 'field_key' => 'custom-date-1' ],
                    'time_1'     => [ 'widget_name' => 'time', 'widget_key' => 'time_1', 'field_key' => 'custom-time-1' ],
                    'text_1'     => [ 'widget_name' => 'text', 'widget_key' => 'text_1', 'field_key' => 'custom-text-1' ],
                    'textarea_1' => [ 'widget_name' => 'textarea', 'widget_key' => 'textarea_1', 'field_key' => 'custom-textarea-1' ],
                    'url_1'      => [ 'widget_name' => 'url', 'widget_key' => 'url_1', 'field_key' => 'custom-url-1' ],
                ],
            ]
        );

        $this->listing_ids['alpha'] = $this->create_listing(
            'Alpha',
            $this->directory,
            [
                '_featured'                         => 1,
                '_price'                            => 200,
                '_expiry_date'                      => '2026-08-03',
                '_never_expire'                     => 0,
                '_directorist_listing_rating'       => 4.5,
                '_directorist_listing_review_count' => 10,
                '_atbdp_post_views_count'           => 40,
                '_manual_lat'                       => 23.8103,
                '_manual_lng'                       => 90.4125,
                '_custom-select-1'                  => 'gold',
                '_custom-checkbox-1'                => [ 'wifi', 'parking' ],
                '_custom-number-1'                  => 45,
                '_custom-date-1'                    => '2026-08-02',
                '_custom-time-1'                    => '09:30',
                '_custom-text-1'                    => html_entity_decode( 'Quiet caf&eacute; river view', ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                '_custom-textarea-1'                => 'The AI friendly riverside workspace',
                '_custom-url-1'                     => 'https://quiet.example.test/workspaces/river-view',
            ]
        );
        $this->listing_ids['beta']  = $this->create_listing(
            'Beta',
            $this->directory,
            [
                '_featured'                         => 0,
                '_price'                            => 100,
                '_expiry_date'                      => '2026-07-15',
                '_never_expire'                     => 1,
                '_directorist_listing_rating'       => 3.5,
                '_directorist_listing_review_count' => 5,
                '_atbdp_post_views_count'           => 80,
                '_manual_lat'                       => 23.82,
                '_manual_lng'                       => 90.42,
                '_custom-select-1'                  => 'silver',
                '_custom-checkbox-1'                => [ 'parking' ],
                '_custom-number-1'                  => 25,
                '_custom-date-1'                    => '2026-07-15',
                '_custom-time-1'                    => '17:45',
                '_custom-text-1'                    => 'Central city view',
                '_custom-textarea-1'                => 'Busy central workspace',
                '_custom-url-1'                     => 'https://central.example.test/workspaces/city-view',
            ]
        );
        $this->listing_ids['gamma'] = $this->create_listing(
            'Gamma',
            $this->other_directory,
            [
                '_featured'                   => 1,
                '_price'                      => 150,
                '_directorist_listing_rating' => 5,
                '_atbdp_post_views_count'     => 120,
            ]
        );

        wp_set_object_terms( $this->listing_ids['alpha'], $this->category, ATBDP_CATEGORY );
        wp_set_object_terms( $this->listing_ids['gamma'], $this->category, ATBDP_CATEGORY );

        foreach ( $this->listing_ids as $listing_id ) {
            Listing_Index::sync_listing( $listing_id );
        }

        Listing_Index::verify();
        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_READY );
        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, Listing_Index_Schema::DATA_VERSION );
        Listing_Index::set_enabled( true );

        add_filter( 'posts_request', [ $this, 'capture_lookup_sql' ], 999, 2 );
    }

    public function tear_down() {
        remove_filter( 'posts_request', [ $this, 'capture_lookup_sql' ], 999 );
        Listing_Index_Maintenance::background_process()->reset();
        delete_option( Listing_Index_Schema::REPAIR_REQUIRED_OPTION );
        parent::tear_down();
    }

    public function test_directory_and_taxonomy_query_matches_legacy_without_postmeta_join() {
        $args = [
            'meta_query' => [ $this->directory_clause() ],
            'tax_query'  => [
                [
                    'taxonomy' => ATBDP_CATEGORY,
                    'field'    => 'term_id',
                    'terms'    => [ $this->category ],
                ],
            ],
        ];

        $legacy = $this->legacy_query( $args );
        $lookup = $this->lookup_query( $args );

        $this->assert_results_same( $legacy, $lookup );
        $this->assertSame( [ $this->listing_ids['alpha'] ], $lookup->ids );
        $this->assertStringContainsString( 'directorist_listing_index dli', $this->captured_sql );
        $this->assertStringNotContainsString( 'postmeta', $this->captured_sql );
    }

    public function test_featured_and_price_ordering_matches_legacy() {
        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
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
        ];

        $legacy = $this->legacy_query( $args );
        $lookup = $this->lookup_query( $args );

        $this->assert_results_same( $legacy, $lookup );
        $this->assertSame( [ $this->listing_ids['alpha'], $this->listing_ids['beta'] ], $lookup->ids );
        $this->assertStringContainsString( 'CAST(dli.featured AS SIGNED) DESC, dli.price_signed ASC', $this->captured_sql );
    }

    public function test_typed_meta_casts_preserve_decimal_and_date_boundaries() {
        $boundary_id = $this->create_listing(
            'Cast Boundary',
            $this->directory,
            [
                '_price'           => '199.75',
                '_custom-number-1' => '45.75',
                '_custom-date-1'   => '2026-08-02 15:30:00',
            ]
        );
        Listing_Index::sync_listing( $boundary_id );

        $price_args  = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'price'          => [
                    'key'     => '_price',
                    'value'   => 199,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ];
        $number_args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'number'         => [
                    'key'     => '_custom-number-1',
                    'value'   => 45,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ];
        $date_args   = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'date'           => [
                    'key'     => '_custom-date-1',
                    'value'   => '2026-08-02',
                    'compare' => '=',
                    'type'    => 'DATE',
                ],
            ],
        ];

        $price_lookup = $this->lookup_query( $price_args );
        $price_sql    = $this->captured_sql;
        $this->assert_results_same( $this->legacy_query( $price_args ), $price_lookup );
        $this->assertContains( $boundary_id, $price_lookup->ids );
        $this->assertStringContainsString( 'dli.price_signed', $price_sql );

        $number_lookup = $this->lookup_query( $number_args );
        $number_sql    = $this->captured_sql;
        $this->assert_results_same( $this->legacy_query( $number_args ), $number_lookup );
        $this->assertContains( $boundary_id, $number_lookup->ids );
        $this->assertStringContainsString( 'dlfi0.value_signed', $number_sql );

        $date_lookup = $this->lookup_query( $date_args );
        $date_sql    = $this->captured_sql;
        $this->assert_results_same( $this->legacy_query( $date_args ), $date_lookup );
        $this->assertContains( $boundary_id, $date_lookup->ids );
        $this->assertStringContainsString( 'CAST(dlfi0.value_date AS DATE)', $date_sql );
    }

    public function test_untyped_lossy_core_values_stay_on_postmeta() {
        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'rating'         => [
                    'key'     => directorist_get_rating_field_meta_key(),
                    'value'   => 4,
                    'compare' => '<=',
                ],
            ],
        ];

        $lookup   = $this->lookup_query( $args );
        $sql      = $this->captured_sql;
        $decision = Listing_Index_Query::get_last_decision();

        $this->assert_results_same( $this->legacy_query( $args ), $lookup );
        $this->assertStringContainsString( 'directorist_listing_index dli', $sql );
        $this->assertStringContainsString( 'postmeta', $sql );
        $this->assertSame( 'partial', $decision['status'] );
    }

    public function test_rating_and_view_filters_match_legacy() {
        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'rating'         => [
                    'key'     => directorist_get_rating_field_meta_key(),
                    'value'   => 4,
                    'compare' => '>=',
                    'type'    => 'NUMERIC',
                ],
                'views'          => [
                    'key'     => directorist_get_listing_views_count_meta_key(),
                    'value'   => 20,
                    'compare' => '>=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ];

        $this->assert_results_same( $this->legacy_query( $args ), $this->lookup_query( $args ) );
        $this->assertSame( [ $this->listing_ids['alpha'] ], $this->lookup_query( $args )->ids );
    }

    public function test_expiry_and_never_expire_filters_match_legacy() {
        $expiry_args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'expiry'         => [
                    'key'     => '_expiry_date',
                    'value'   => '2026-08-01',
                    'compare' => '<=',
                    'type'    => 'DATE',
                ],
            ],
        ];
        $never_args  = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'never'          => [
                    'key'   => '_never_expire',
                    'value' => 1,
                ],
            ],
        ];

        $expiry_lookup = $this->lookup_query( $expiry_args );
        $never_lookup  = $this->lookup_query( $never_args );

        $this->assert_results_same( $this->legacy_query( $expiry_args ), $expiry_lookup );
        $this->assert_results_same( $this->legacy_query( $never_args ), $never_lookup );
        $this->assertSame( [ $this->listing_ids['beta'] ], $expiry_lookup->ids );
        $this->assertSame( [ $this->listing_ids['beta'] ], $never_lookup->ids );
    }

    public function test_indexed_custom_select_and_number_filters_match_legacy() {
        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'select'         => [
                    'key'     => '_custom-select-1',
                    'value'   => 'gold',
                    'compare' => '=',
                ],
                'number'         => [
                    'key'     => '_custom-number-1',
                    'value'   => [ 40, 50 ],
                    'compare' => 'BETWEEN',
                    'type'    => 'NUMERIC',
                ],
            ],
        ];

        $legacy = $this->legacy_query( $args );
        $lookup = $this->lookup_query( $args );

        $this->assert_results_same( $legacy, $lookup );
        $this->assertStringContainsString( Listing_Index_Schema::field_table(), $this->captured_sql );
        $this->assertStringContainsString( Listing_Index_Schema::number_field_table(), $this->captured_sql );
        $this->assertStringContainsString( 'value_string', $this->captured_sql );
        $this->assertStringContainsString( 'value_signed', $this->captured_sql );
        $this->assertStringNotContainsString( 'postmeta', $this->captured_sql );
    }

    public function test_checkbox_membership_is_exact_and_uses_lookup_rows() {
        $overlap_id       = $this->create_listing(
            'Overlapping Checkbox',
            $this->directory,
            [ '_custom-checkbox-1' => [ 'wifi-plus' ] ]
        );
        $legacy_scalar_id = $this->create_listing(
            'Legacy Scalar Checkbox',
            $this->directory,
            [ '_custom-checkbox-1' => 'wifi,parking' ]
        );
        Listing_Index::sync_listing( $overlap_id );
        Listing_Index::sync_listing( $legacy_scalar_id );

        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'checkbox'       => [
                    'key'                     => '_custom-checkbox-1',
                    'value'                   => 'wifi',
                    'compare'                 => 'LIKE',
                    'directorist_index_match' => 'membership',
                ],
            ],
        ];

        $legacy = $this->legacy_query( $args );
        $lookup = $this->lookup_query( $args );

        $this->assertContains( $overlap_id, $legacy->ids, 'The legacy substring query demonstrates the false positive being removed.' );
        $this->assertEqualsCanonicalizing( [ $this->listing_ids['alpha'], $legacy_scalar_id ], $lookup->ids );
        $this->assertStringContainsString( Listing_Index_Schema::field_table(), $this->captured_sql );
        $this->assertStringNotContainsString( 'postmeta', $this->captured_sql );
        $this->assertSame( 'optimized', Listing_Index_Query::get_last_decision()['status'] );
    }

    public function test_multiple_checkbox_values_preserve_any_member_semantics() {
        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'checkbox'       => [
                    'relation' => 'OR',
                    [
                        'key'                     => '_custom-checkbox-1',
                        'value'                   => 'wifi',
                        'compare'                 => 'LIKE',
                        'directorist_index_match' => 'membership',
                    ],
                    [
                        'key'                     => '_custom-checkbox-1',
                        'value'                   => 'parking',
                        'compare'                 => 'LIKE',
                        'directorist_index_match' => 'membership',
                    ],
                ],
            ],
        ];

        $lookup = $this->lookup_query( $args );

        $this->assertEqualsCanonicalizing( [ $this->listing_ids['alpha'], $this->listing_ids['beta'] ], $lookup->ids );
        $this->assertStringContainsString( 'value_string IN', $this->captured_sql );
        $this->assertStringNotContainsString( 'postmeta', $this->captured_sql );
    }

    /**
     * @dataProvider fulltext_field_provider
     */
    public function test_text_fields_use_deterministic_fulltext_all_token_matching( $meta_key, $search, array $expected_names ) {
        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'text'           => [
                    'key'                     => $meta_key,
                    'value'                   => $search,
                    'compare'                 => 'LIKE',
                    'directorist_index_match' => 'fulltext',
                ],
            ],
        ];

        // InnoDB FULLTEXT indexes expose committed rows only. Commit this test's
        // fixture, then remove it before restoring the test transaction.
        $lookup = $this->committed_lookup_query( $args );

        $expected = array_map(
            function( $name ) {
                return $this->listing_ids[ $name ];
            },
            $expected_names
        );

        $this->assertSame( $expected, $lookup->ids, $this->captured_sql );
        $this->assertStringContainsString( Listing_Index_Schema::text_field_table(), $this->captured_sql );
        $this->assertStringContainsString( 'MATCH(', $this->captured_sql );
        $this->assertStringContainsString( 'IN BOOLEAN MODE', $this->captured_sql );
        $this->assertStringNotContainsString( 'postmeta', $this->captured_sql );
    }

    public function fulltext_field_provider() {
        return [
            'text all terms'       => [ '_custom-text-1', 'river quiet', [ 'alpha' ] ],
            'text case folding'    => [ '_custom-text-1', 'CAFE', [ 'alpha' ] ],
            'text no substring'    => [ '_custom-text-1', 'rive', [] ],
            'textarea short term'  => [ '_custom-textarea-1', 'AI riverside', [ 'alpha' ] ],
            'textarea stopword'    => [ '_custom-textarea-1', 'the workspace', [ 'alpha' ] ],
            'url host path tokens' => [ '_custom-url-1', 'quiet river', [ 'alpha' ] ],
        ];
    }

    public function test_text_queries_without_searchable_tokens_keep_the_canonical_fallback() {
        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'text'           => [
                    'key'                     => '_custom-text-1',
                    'value'                   => '---',
                    'compare'                 => 'LIKE',
                    'directorist_index_match' => 'fulltext',
                ],
            ],
        ];

        $lookup   = $this->lookup_query( $args );
        $decision = Listing_Index_Query::get_last_decision();

        $this->assert_results_same( $this->legacy_query( $args ), $lookup );
        $this->assertStringContainsString( 'postmeta', $this->captured_sql );
        $this->assertSame( 'partial', $decision['status'] );
    }

    public function test_non_search_text_operators_and_mixed_or_groups_are_not_reinterpreted() {
        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'text_exact'     => [
                    'key'     => '_custom-text-1',
                    'value'   => 'Quiet river view',
                    'compare' => '=',
                ],
                'mixed_group'    => [
                    'relation' => 'OR',
                    [
                        'key'                     => '_custom-checkbox-1',
                        'value'                   => 'wifi',
                        'compare'                 => 'LIKE',
                        'directorist_index_match' => 'membership',
                    ],
                    [
                        'key'     => '_custom-select-1',
                        'value'   => 'silver',
                        'compare' => '=',
                    ],
                ],
            ],
        ];

        $legacy = $this->legacy_query( $args );
        $lookup = $this->lookup_query( $args );

        $this->assert_results_same( $legacy, $lookup );
        $this->assertStringContainsString( 'postmeta', $this->captured_sql );
        $this->assertSame( 'partial', Listing_Index_Query::get_last_decision()['status'] );
    }

    public function test_unmarked_extension_field_queries_keep_wordpress_semantics() {
        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'checkbox'       => [
                    'key'     => '_custom-checkbox-1',
                    'value'   => 'wifi',
                    'compare' => 'LIKE',
                ],
                'text'           => [
                    'key'     => '_custom-text-1',
                    'value'   => 'river',
                    'compare' => 'LIKE',
                ],
            ],
        ];

        $legacy = $this->legacy_query( $args );
        $lookup = $this->lookup_query( $args );

        $this->assert_results_same( $legacy, $lookup );
        $this->assertStringContainsString( 'postmeta', $this->captured_sql );
        $this->assertSame( 'partial', Listing_Index_Query::get_last_decision()['status'] );
    }

    public function test_directorist_search_request_builder_produces_an_optimized_field_plan() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Request fixture.
        $request = $_REQUEST;

        $_REQUEST = [
            'directory_type' => $this->directory,
            'custom_field'   => [
                'custom-checkbox-1' => [ 'wifi', 'parking' ],
                'custom-text-1'     => 'river quiet',
            ],
        ];

        try {
            $listings = new \Directorist\Directorist_Listings(
                [
                    '_featured'         => 0,
                    'directory_type'    => (string) $this->directory,
                    'show_pagination'   => 'no',
                    'listings_per_page' => 20,
                ],
                'search_result',
                [
                    'post_type'      => ATBDP_POST_TYPE,
                    'posts_per_page' => 1,
                ]
            );
            $args     = $listings->parse_search_query_args();
            $prepared = Listing_Index_Query::prepare_args( $args );
            $plan     = $prepared['directorist_listing_index_plan'];
            $modes    = wp_list_pluck( $plan['field_predicates'], 'match_mode' );

            $this->assertArrayNotHasKey( 'meta_query', $prepared );
            $this->assertContains( 'membership', $modes );
            $this->assertContains( 'fulltext', $modes );
            $this->assertSame( 'optimized', $prepared['directorist_listing_index_decision']['status'] );
        } finally {
            $_REQUEST = $request;
        }
    }

    public function test_indexed_date_and_time_filters_match_legacy() {
        $date_args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'date'           => [
                    'key'     => '_custom-date-1',
                    'value'   => [ '2026-08-01', '2026-08-03' ],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
        ];
        $time_args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'time'           => [
                    'key'     => '_custom-time-1',
                    'value'   => '09:30',
                    'compare' => '=',
                ],
            ],
        ];

        $date_legacy = $this->legacy_query( $date_args );
        $date_lookup = $this->lookup_query( $date_args );

        $this->assert_results_same( $date_legacy, $date_lookup );
        $this->assertStringContainsString( Listing_Index_Schema::date_field_table(), $this->captured_sql );
        $this->assertStringContainsString( 'value_date', $this->captured_sql );
        $this->assert_results_same( $this->legacy_query( $time_args ), $this->lookup_query( $time_args ) );
        $this->assertStringContainsString( Listing_Index_Schema::field_table(), $this->captured_sql );
        $this->assertSame( [ $this->listing_ids['alpha'] ], $date_lookup->ids );
        $this->assertSame( [ $this->listing_ids['alpha'] ], $this->lookup_query( $time_args )->ids );
    }

    public function test_custom_exact_index_preserves_collation_whitespace_and_in_semantics() {
        $spaced_id     = $this->create_listing(
            'Spaced Select',
            $this->directory,
            [ '_custom-select-1' => ' gold ' ]
        );
        $mixed_case_id = $this->create_listing(
            'Mixed Case Select',
            $this->directory,
            [ '_custom-select-1' => 'GoLd' ]
        );
        Listing_Index::sync_listing( $spaced_id );
        Listing_Index::sync_listing( $mixed_case_id );

        $exact_args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'select'         => [
                    'key'   => '_custom-select-1',
                    'value' => ' gold ',
                ],
            ],
        ];
        $in_args    = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'select'         => [
                    'key'     => '_custom-select-1',
                    'value'   => [ 'gold', 'silver' ],
                    'compare' => 'IN',
                ],
            ],
        ];

        $exact_lookup = $this->lookup_query( $exact_args );
        $in_lookup    = $this->lookup_query( $in_args );

        $this->assert_results_same( $this->legacy_query( $exact_args ), $exact_lookup );
        $this->assert_results_same( $this->legacy_query( $in_args ), $in_lookup );
        $this->assertContains( $this->listing_ids['alpha'], $exact_lookup->ids );
        $this->assertContains( $mixed_case_id, $exact_lookup->ids );
        $this->assertNotContains( $spaced_id, $exact_lookup->ids );
        $this->assertNotContains( $spaced_id, $in_lookup->ids );
    }

    public function test_custom_exact_values_are_prepared_as_data() {
        $value      = "gold' OR 1=1 --";
        $listing_id = $this->create_listing(
            'Prepared Select',
            $this->directory,
            [ '_custom-select-1' => $value ]
        );
        Listing_Index::sync_listing( $listing_id );

        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'select'         => [
                    'key'   => '_custom-select-1',
                    'value' => $value,
                ],
            ],
        ];

        $legacy = $this->legacy_query( $args );
        $lookup = $this->lookup_query( $args );

        $this->assert_results_same( $legacy, $lookup );
        $this->assertSame( [ $listing_id ], $lookup->ids );
        $this->assertStringContainsString( "gold\\' OR 1=1 --", $this->captured_sql );
    }

    public function test_custom_fields_preserve_wordpress_string_comparison_and_fallback_limits() {
        $leading_zero_id = $this->create_listing(
            'Leading Zero Number',
            $this->directory,
            [ '_custom-number-1' => '045' ]
        );
        $empty_select_id = $this->create_listing(
            'Empty Select',
            $this->directory,
            [ '_custom-select-1' => '' ]
        );
        $long_value      = str_repeat( 'x', 192 );
        $long_select_id  = $this->create_listing(
            'Long Select',
            $this->directory,
            [ '_custom-select-1' => $long_value ]
        );

        Listing_Index::sync_listing( $leading_zero_id );
        Listing_Index::sync_listing( $empty_select_id );
        Listing_Index::sync_listing( $long_select_id );

        $number_args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'number'         => [
                    'key'   => '_custom-number-1',
                    'value' => '45',
                ],
            ],
        ];
        $empty_args  = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'select'         => [
                    'key'   => '_custom-select-1',
                    'value' => '',
                ],
            ],
        ];
        $long_args   = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'select'         => [
                    'key'   => '_custom-select-1',
                    'value' => $long_value,
                ],
            ],
        ];

        $number_lookup = $this->lookup_query( $number_args );
        $empty_lookup  = $this->lookup_query( $empty_args );
        $long_lookup   = $this->lookup_query( $long_args );
        $long_decision = Listing_Index_Query::get_last_decision();

        $this->assert_results_same( $this->legacy_query( $number_args ), $number_lookup );
        $this->assert_results_same( $this->legacy_query( $empty_args ), $empty_lookup );
        $this->assert_results_same( $this->legacy_query( $long_args ), $long_lookup );
        $this->assertNotContains( $leading_zero_id, $number_lookup->ids );
        $this->assertContains( $empty_select_id, $empty_lookup->ids );
        $this->assertContains( $long_select_id, $long_lookup->ids );
        $this->assertSame( 'partial', $long_decision['status'] );
    }

    public function test_duplicate_custom_meta_values_match_any_canonical_row() {
        add_post_meta( $this->listing_ids['alpha'], '_custom-select-1', 'silver' );

        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'select'         => [
                    'key'   => '_custom-select-1',
                    'value' => 'silver',
                ],
            ],
        ];

        $lookup = $this->lookup_query( $args );

        $this->assert_results_same( $this->legacy_query( $args ), $lookup );
        $this->assertContains( $this->listing_ids['alpha'], $lookup->ids );
        $this->assertContains( $this->listing_ids['beta'], $lookup->ids );
    }

    public function test_conflicting_core_meta_key_falls_back_without_disabling_other_predicates() {
        add_post_meta( $this->listing_ids['alpha'], '_price', 999 );

        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'price'          => [
                    'key'   => '_price',
                    'value' => 999,
                ],
            ],
        ];

        $lookup   = $this->lookup_query( $args );
        $decision = Listing_Index_Query::get_last_decision();

        $this->assert_results_same( $this->legacy_query( $args ), $lookup );
        $this->assertStringContainsString( 'directorist_listing_index dli', $this->captured_sql );
        $this->assertStringContainsString( 'postmeta', $this->captured_sql );
        $this->assertSame( 'partial', $decision['status'] );
    }

    public function test_ambiguous_directory_meta_keeps_directory_scoped_fields_on_postmeta() {
        add_post_meta( $this->listing_ids['alpha'], '_directory_type', $this->other_directory );

        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => [
                    'key'   => '_directory_type',
                    'value' => $this->other_directory,
                ],
                'select'         => [
                    'key'   => '_custom-select-1',
                    'value' => 'gold',
                ],
            ],
        ];

        $lookup   = $this->lookup_query( $args );
        $decision = Listing_Index_Query::get_last_decision();

        $this->assert_results_same( $this->legacy_query( $args ), $lookup );
        $this->assertContains( $this->listing_ids['alpha'], $lookup->ids );
        $this->assertStringNotContainsString( 'directorist_listing_index dli', $this->captured_sql );
        $this->assertSame( 'disabled', $decision['status'] );
        $this->assertSame( 'no_supported_predicates', $decision['reason'] );
    }

    public function test_array_value_without_compare_preserves_wordpress_in_semantics() {
        $args = [
            'meta_query' => [
                [
                    'key'   => '_price',
                    'value' => [ 100, 200 ],
                    'type'  => 'NUMERIC',
                ],
            ],
            'orderby'    => 'title',
            'order'      => 'ASC',
        ];

        $legacy = $this->legacy_query( $args );
        $lookup = $this->lookup_query( $args );

        $this->assert_results_same( $legacy, $lookup );
        $this->assertSame( [ $this->listing_ids['alpha'], $this->listing_ids['beta'] ], $lookup->ids );
        $this->assertSame( 'optimized', Listing_Index_Query::get_last_decision()['status'] );
    }

    public function test_meta_clause_without_value_preserves_wordpress_exists_semantics() {
        $args = [
            'meta_query' => [
                [
                    'key' => '_featured',
                ],
            ],
            'orderby'    => 'title',
            'order'      => 'ASC',
        ];

        $legacy = $this->legacy_query( $args );
        $lookup = $this->lookup_query( $args );

        $this->assert_results_same( $legacy, $lookup );
        $this->assertSame( array_values( $this->listing_ids ), $lookup->ids );
        $this->assertSame( 'optimized', Listing_Index_Query::get_last_decision()['status'] );
    }

    public function test_exists_with_value_preserves_wordpress_equality_semantics() {
        $args = [
            'meta_query' => [
                [
                    'key'     => '_featured',
                    'value'   => 1,
                    'compare' => 'EXISTS',
                ],
            ],
            'orderby'    => 'title',
            'order'      => 'ASC',
        ];

        $legacy = $this->legacy_query( $args );
        $lookup = $this->lookup_query( $args );

        $this->assert_results_same( $legacy, $lookup );
        $this->assertSame( [ $this->listing_ids['alpha'], $this->listing_ids['gamma'] ], $lookup->ids );
        $this->assertSame( 'optimized', Listing_Index_Query::get_last_decision()['status'] );
    }

    /**
     * @dataProvider unsupported_comparison_value_provider
     */
    public function test_unsupported_comparison_value_shapes_keep_canonical_arguments( $clause ) {
        $args     = $this->base_args( [ 'meta_query' => [ $clause ] ] );
        $prepared = Listing_Index_Query::prepare_args( $args );
        $decision = $prepared['directorist_listing_index_decision'];

        $this->assertSame( $args['meta_query'], $prepared['meta_query'] );
        $this->assertArrayNotHasKey( 'directorist_listing_index_plan', $prepared );
        $this->assertSame( 'disabled', $decision['status'] );
        $this->assertSame( 'no_supported_predicates', $decision['reason'] );
    }

    public function unsupported_comparison_value_provider() {
        return [
            'between scalar'       => [ [ 'key' => '_price', 'value' => '100,200', 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ] ],
            'between too few'      => [ [ 'key' => '_price', 'value' => [ 100 ], 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ] ],
            'between too many'     => [ [ 'key' => '_price', 'value' => [ 100, 200, 300 ], 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ] ],
            'in scalar'            => [ [ 'key' => '_price', 'value' => '100,200', 'compare' => 'IN', 'type' => 'NUMERIC' ] ],
            'in empty'             => [ [ 'key' => '_price', 'value' => [], 'compare' => 'IN', 'type' => 'NUMERIC' ] ],
            'in nested value'      => [ [ 'key' => '_price', 'value' => [ [ 100 ], 200 ], 'compare' => 'IN', 'type' => 'NUMERIC' ] ],
            'scalar operator list' => [ [ 'key' => '_price', 'value' => [ 100 ], 'compare' => '>', 'type' => 'NUMERIC' ] ],
        ];
    }

    public function test_unsupported_custom_field_value_shape_remains_canonical_in_partial_plan() {
        $custom_clause = [
            'key'     => '_custom-number-1',
            'value'   => [ 25 ],
            'compare' => 'BETWEEN',
            'type'    => 'NUMERIC',
        ];
        $args          = $this->base_args(
            [
                'meta_query' => [
                    'relation'       => 'AND',
                    'directory_type' => $this->directory_clause(),
                    'custom_number'  => $custom_clause,
                ],
            ]
        );
        $prepared      = Listing_Index_Query::prepare_args( $args );
        $decision      = $prepared['directorist_listing_index_decision'];

        $this->assertSame( $custom_clause, $prepared['meta_query']['custom_number'] );
        $this->assertSame( 'AND', $prepared['meta_query']['relation'] );
        $this->assertArrayHasKey( 'directorist_listing_index_plan', $prepared );
        $this->assertSame( 'partial', $decision['status'] );
        $this->assertSame( 'supported_clauses_only', $decision['reason'] );
    }

    public function test_rejected_filtered_plan_restores_canonical_query_and_results() {
        $args = [
            'meta_query' => [ $this->directory_clause() ],
            'orderby'    => 'title',
            'order'      => 'ASC',
        ];

        $legacy      = $this->legacy_query( $args );
        $reject_plan = static function() {
            return false;
        };

        add_filter( 'directorist_listing_index_query_plan', $reject_plan );

        try {
            $lookup   = $this->lookup_query( $args );
            $decision = Listing_Index_Query::get_last_decision();

            $this->assert_results_same( $legacy, $lookup );
            $this->assertStringContainsString( 'postmeta', $this->captured_sql );
            $this->assertStringNotContainsString( 'directorist_listing_index dli', $this->captured_sql );
            $this->assertSame( 'fallback', $decision['status'] );
            $this->assertSame( 'plan_rejected', $decision['reason'] );
        } finally {
            remove_filter( 'directorist_listing_index_query_plan', $reject_plan );
        }
    }

    public function test_invalid_filtered_plan_restores_every_destructive_argument() {
        $args            = $this->base_args(
            [
                'meta_key'        => '_price',
                'meta_query'      => [ $this->directory_clause() ],
                'atbdp_geo_query' => [
                    'lat_field'    => '_manual_lat',
                    'lng_field'    => '_manual_lng',
                    'latitude'     => 23.8103,
                    'longitude'    => 90.4125,
                    'max_distance' => 10,
                ],
                'orderby'         => 'meta_value_num',
                'order'           => 'ASC',
            ]
        );
        $invalidate_plan = static function( $plan ) {
            $plan['predicates'][0]['compare'] = 'BETWEEN';
            $plan['predicates'][0]['value']   = [ 1 ];

            return $plan;
        };

        add_filter( 'directorist_listing_index_query_plan', $invalidate_plan );

        try {
            $prepared = Listing_Index_Query::prepare_args( $args );
            $decision = $prepared['directorist_listing_index_decision'];

            $this->assertSame( $args['meta_key'], $prepared['meta_key'] );
            $this->assertSame( $args['meta_query'], $prepared['meta_query'] );
            $this->assertSame( $args['atbdp_geo_query'], $prepared['atbdp_geo_query'] );
            $this->assertSame( $args['orderby'], $prepared['orderby'] );
            $this->assertArrayNotHasKey( 'directorist_listing_index_plan', $prepared );
            $this->assertSame( 'fallback', $decision['status'] );
            $this->assertSame( 'plan_rejected', $decision['reason'] );
        } finally {
            remove_filter( 'directorist_listing_index_query_plan', $invalidate_plan );
        }
    }

    public function test_top_level_or_relation_falls_back_to_legacy_sql() {
        $args = [
            'meta_query' => [
                'relation' => 'OR',
                $this->directory_clause(),
                [
                    'key'   => '_featured',
                    'value' => 1,
                ],
            ],
        ];

        $this->assert_results_same( $this->legacy_query( $args ), $this->lookup_query( $args ) );
        $this->assertStringNotContainsString( 'directorist_listing_index dli', $this->captured_sql );
        $this->assertSame( 'unsupported_meta_relation', Listing_Index_Query::get_last_decision()['reason'] );
    }

    public function test_unconvertible_geo_constraint_is_reported_as_partial() {
        $args = [
            'meta_query'      => [ $this->directory_clause() ],
            'atbdp_geo_query' => [
                'lat_field'    => '_extension_lat',
                'lng_field'    => '_extension_lng',
                'latitude'     => 23.8103,
                'longitude'    => 90.4125,
                'max_distance' => 10,
            ],
        ];

        $this->assert_results_same( $this->legacy_query( $args ), $this->lookup_query( $args ) );
        $this->assertSame( 'partial', Listing_Index_Query::get_last_decision()['status'] );
        $this->assertSame( 'supported_clauses_only', Listing_Index_Query::get_last_decision()['reason'] );
    }

    public function test_mismatched_directory_taxonomy_keeps_legacy_meta_result() {
        wp_set_object_terms( $this->listing_ids['alpha'], $this->other_directory, ATBDP_DIRECTORY_TYPE );
        Listing_Index::sync_listing( $this->listing_ids['alpha'] );

        $args = [
            'meta_query' => [ $this->directory_clause() ],
        ];

        $legacy = $this->legacy_query( $args );
        $lookup = $this->lookup_query( $args );

        $this->assert_results_same( $legacy, $lookup );
        $this->assertContains( $this->listing_ids['alpha'], $lookup->ids );
    }

    public function test_pagination_totals_and_page_boundaries_match_legacy() {
        $args = [
            'posts_per_page' => 1,
            'paged'          => 2,
            'meta_query'     => [ $this->directory_clause() ],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        $this->assert_results_same( $this->legacy_query( $args ), $this->lookup_query( $args ) );
    }

    public function test_geo_radius_and_distance_order_match_legacy_without_coordinate_meta_joins() {
        $args = [
            'meta_query'      => [ $this->directory_clause() ],
            'atbdp_geo_query' => [
                'lat_field'    => '_manual_lat',
                'lng_field'    => '_manual_lng',
                'latitude'     => 23.8103,
                'longitude'    => 90.4125,
                'min_distance' => 0,
                'max_distance' => 10,
                'units'        => 'miles',
            ],
            'orderby'         => 'distance',
            'order'           => 'ASC',
        ];

        $legacy = $this->legacy_query( $args );
        $lookup = $this->lookup_query( $args );

        $this->assert_results_same( $legacy, $lookup );
        $this->assertSame( [ $this->listing_ids['alpha'], $this->listing_ids['beta'] ], $lookup->ids );
        $this->assertStringContainsString( 'dli.latitude', $this->captured_sql );
        $this->assertStringNotContainsString( 'atbdp_geo_query_lat', $this->captured_sql );
    }

    public function test_disabled_or_incomplete_index_uses_legacy_sql() {
        $args = [
            'meta_query' => [ $this->directory_clause() ],
        ];

        Listing_Index::set_enabled( false );
        $this->lookup_query( $args );
        $this->assertStringNotContainsString( 'directorist_listing_index dli', $this->captured_sql );

        Listing_Index::set_enabled( true );
        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_NEEDS_REBUILD );
        $this->lookup_query( $args );
        $this->assertStringNotContainsString( 'directorist_listing_index dli', $this->captured_sql );

        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_READY );
        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, 'stale' );
        $this->lookup_query( $args );
        $this->assertStringNotContainsString( 'directorist_listing_index dli', $this->captured_sql );
        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, Listing_Index_Schema::DATA_VERSION );
    }

    /**
     * @dataProvider missing_lookup_table_provider
     */
    public function test_missing_lookup_table_falls_back_after_cached_schema_health( $table_method ) {
        $args   = [ 'meta_query' => [ $this->directory_clause() ] ];
        $legacy = $this->legacy_query( $args );

        $this->assertTrue( Listing_Index_Schema::is_ready() );

        $table      = call_user_func( [ Listing_Index_Schema::class, $table_method ] );
        $hide_table = static function( $sql ) use ( $table ) {
            if ( false !== strpos( $sql, 'information_schema.tables' ) ) {
                return str_replace( $table, $table . '_missing', $sql );
            }

            return $sql;
        };

        add_filter( 'query', $hide_table );
        Listing_Index_Schema::reset_request_cache();

        $lookup = $this->lookup_query( $args );
        remove_filter( 'query', $hide_table );

        $this->assert_results_same( $legacy, $lookup );
        $this->assertStringNotContainsString( 'directorist_listing_index dli', $this->captured_sql );
        $this->assertSame( 'disabled', Listing_Index_Query::get_last_decision()['status'] );
        $this->assertSame( Listing_Index_Schema::STATUS_NEEDS_REBUILD, Listing_Index_Schema::status() );
        $this->assertTrue( Listing_Index_Schema::repair_required() );
        $this->assertTrue( Listing_Index_Maintenance::background_process()->has_queued_work() );
    }

    public function missing_lookup_table_provider() {
        return [
            'listing table'      => [ 'listing_table' ],
            'exact field table'  => [ 'field_table' ],
            'number field table' => [ 'number_field_table' ],
            'date field table'   => [ 'date_field_table' ],
            'text field table'   => [ 'text_field_table' ],
            'state table'        => [ 'state_table' ],
        ];
    }

    public function test_extension_can_opt_out_of_lookup_reads() {
        $args = [
            'meta_query' => [ $this->directory_clause() ],
        ];

        add_filter( 'directorist_use_listing_index', '__return_false', 999 );
        $results = $this->lookup_query( $args );
        remove_filter( 'directorist_use_listing_index', '__return_false', 999 );

        $this->assert_results_same( $this->legacy_query( $args ), $results );
        $this->assertStringNotContainsString( 'directorist_listing_index dli', $this->captured_sql );
        $this->assertSame( 'index_not_ready_or_disabled', Listing_Index_Query::get_last_decision()['reason'] );
    }

    public function test_custom_field_ordering_falls_back_until_it_has_a_typed_order_contract() {
        $args = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'select'         => [
                    'key'   => '_custom-select-1',
                    'value' => 'gold',
                ],
            ],
            'orderby'    => [ 'select' => 'ASC' ],
        ];

        $this->assert_results_same( $this->legacy_query( $args ), $this->lookup_query( $args ) );
        $this->assertStringNotContainsString( 'directorist_listing_index dli', $this->captured_sql );
        $this->assertSame( 'unsupported_meta_order', Listing_Index_Query::get_last_decision()['reason'] );
    }

    public function test_presence_flags_preserve_zero_value_and_missing_meta_semantics() {
        $missing_id = $this->create_listing( 'Missing Featured', $this->directory, [] );
        $zero_id    = $this->create_listing( 'Zero Featured', $this->directory, [ '_featured' => '' ] );

        Listing_Index::sync_listing( $missing_id );
        Listing_Index::sync_listing( $zero_id );

        $exists_args = [
            'meta_query' => [
                $this->directory_clause(),
                [
                    'key'     => '_featured',
                    'compare' => 'EXISTS',
                ],
            ],
            'orderby'    => 'ID',
            'order'      => 'ASC',
        ];
        $zero_args   = [
            'meta_query' => [
                $this->directory_clause(),
                [
                    'key'     => '_featured',
                    'value'   => 0,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ],
            'orderby'    => 'ID',
            'order'      => 'ASC',
        ];

        $exists_lookup = $this->lookup_query( $exists_args );
        $zero_lookup   = $this->lookup_query( $zero_args );

        $this->assert_results_same( $this->legacy_query( $exists_args ), $exists_lookup );
        $this->assert_results_same( $this->legacy_query( $zero_args ), $zero_lookup );
        $this->assertNotContains( $missing_id, $exists_lookup->ids );
        $this->assertContains( $zero_id, $exists_lookup->ids );
        $this->assertNotContains( $missing_id, $zero_lookup->ids );
        $this->assertContains( $zero_id, $zero_lookup->ids );
    }

    public function test_native_query_cache_is_reused_and_generation_safe() {
        $args    = [
            'meta_query' => [
                'relation'       => 'AND',
                'directory_type' => $this->directory_clause(),
                'select'         => [
                    'key'   => '_custom-select-1',
                    'value' => 'gold',
                ],
            ],
            'orderby'    => 'ID',
            'order'      => 'ASC',
        ];
        $queries = [];
        $capture = static function( $sql ) use ( &$queries ) {
            if ( false !== strpos( $sql, 'directorist_listing_index dli' ) ) {
                $queries[] = $sql;
            }

            return $sql;
        };

        add_filter( 'query', $capture );
        $first       = $this->lookup_query( $args );
        $first_count = count( $queries );
        $queries     = [];
        $second      = $this->lookup_query( $args );

        $this->assert_results_same( $first, $second );
        $this->assertGreaterThan( 0, $first_count );
        $this->assertSame( 0, count( $queries ) );

        $search_config                       = get_term_meta( $this->directory, 'search_form_fields', true );
        $form_config                         = get_term_meta( $this->directory, 'submission_form_fields', true );
        $search_config['fields']['switch_2'] = [
            'widget_name'         => 'switch',
            'widget_key'          => 'switch_2',
            'original_widget_key' => 'switch_2',
        ];
        $form_config['fields']['switch_2']   = [
            'widget_name' => 'switch',
            'widget_key'  => 'switch_2',
            'field_key'   => 'custom-switch-2',
        ];
        $before_generation                   = Listing_Index_Directory_State::query_generation( $this->directory );

        update_term_meta( $this->directory, 'search_form_fields', $search_config );
        update_term_meta( $this->directory, 'submission_form_fields', $form_config );
        Listing_Index_Maintenance::process_next_batch();

        $queries = [];
        $third   = $this->lookup_query( $args );
        remove_filter( 'query', $capture );

        $this->assert_results_same( $first, $third );
        $this->assertGreaterThan( $before_generation, Listing_Index_Directory_State::query_generation( $this->directory ) );
        $this->assertGreaterThan( 0, count( $queries ) );
    }

    public function test_price_availability_lookup_matches_legacy_meta_existence_semantics() {
        foreach ( $this->listing_ids as $listing_id ) {
            delete_post_meta( $listing_id, '_price' );
        }

        $draft_id = $this->create_listing(
            'Draft With Price',
            $this->directory,
            [ '_price' => 50 ],
            'draft'
        );
        Listing_Index::sync_listing( $draft_id );

        add_filter( 'directorist_use_listing_index', '__return_false' );
        $legacy_without_published_price = directorist_have_listings_with_price();
        remove_filter( 'directorist_use_listing_index', '__return_false' );

        $this->assertFalse( $legacy_without_published_price );
        $this->assertFalse( directorist_have_listings_with_price() );

        update_post_meta( $this->listing_ids['alpha'], '_price', '' );

        add_filter( 'directorist_use_listing_index', '__return_false' );
        $legacy_with_empty_price = directorist_have_listings_with_price();
        remove_filter( 'directorist_use_listing_index', '__return_false' );

        $this->assertTrue( $legacy_with_empty_price );
        $this->assertTrue( directorist_have_listings_with_price() );
    }

    public function test_price_availability_lookup_uses_index_cache_and_mutation_invalidation() {
        wp_cache_flush();

        $queries = [];
        $capture = static function( $sql ) use ( &$queries ) {
            if ( false !== strpos( $sql, Listing_Index_Schema::listing_table() ) ) {
                $queries[] = $sql;
            }

            return $sql;
        };

        add_filter( 'query', $capture );
        $this->assertTrue( directorist_have_listings_with_price() );
        $first_queries = $queries;
        $queries       = [];

        $this->assertTrue( directorist_have_listings_with_price() );
        $this->assertSame( [], $queries );

        foreach ( $this->listing_ids as $listing_id ) {
            delete_post_meta( $listing_id, '_price' );
        }

        $this->assertFalse( directorist_have_listings_with_price() );
        remove_filter( 'query', $capture );

        $this->assertNotEmpty( $first_queries );
        $this->assertStringContainsString( "dli.post_status = 'publish'", end( $first_queries ) );
        $this->assertStringContainsString( 'price_set = 1', end( $first_queries ) );
        $this->assertStringNotContainsString( 'postmeta', end( $first_queries ) );
        $this->assertStringNotContainsString( 'ORDER BY', end( $first_queries ) );
    }

    public function capture_lookup_sql( $sql, $query ) {
        if ( $query->get( 'directorist_listing_index_plan' ) ) {
            $this->captured_sql = $sql;
        } elseif ( empty( $this->captured_sql ) ) {
            $this->captured_sql = $sql;
        }

        return $sql;
    }

    private function create_listing( $title, $directory_id, array $meta, $post_status = 'publish' ) {
        $listing_id = self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => $post_status,
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

    private function directory_clause() {
        return [
            'key'     => '_directory_type',
            'value'   => $this->directory,
            'compare' => '=',
        ];
    }

    private function base_args( array $args ) {
        return wp_parse_args(
            $args,
            [
                'post_type'      => ATBDP_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 20,
            ]
        );
    }

    private function legacy_query( array $args ) {
        add_filter( 'directorist_use_listing_index', '__return_false', 999 );
        $results = DB::get_listings_data( $this->base_args( $args ) );
        remove_filter( 'directorist_use_listing_index', '__return_false', 999 );

        return $results;
    }

    private function lookup_query( array $args ) {
        $this->captured_sql = '';
        return DB::get_listings_data( $this->base_args( $args ) );
    }

    private function committed_lookup_query( array $args ) {
        global $wpdb;

        self::commit_transaction();

        try {
            return $this->lookup_query( $args );
        } finally {
            foreach ( $this->listing_ids as $listing_id ) {
                wp_delete_post( $listing_id, true );
            }

            wp_delete_term( $this->category, ATBDP_CATEGORY );
            wp_delete_term( $this->directory, ATBDP_DIRECTORY_TYPE );
            wp_delete_term( $this->other_directory, ATBDP_DIRECTORY_TYPE );

            // Ensure no derived fixture survives if deletion hooks are changed.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            foreach ( Listing_Index_Schema::field_tables() as $field_table ) {
                $wpdb->query( "DELETE FROM {$field_table}" );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::listing_table() );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::state_table() );
            self::commit_transaction();
            $this->start_transaction();
        }
    }

    private function assert_results_same( $legacy, $lookup ) {
        $this->assertSame( $legacy->ids, $lookup->ids );
        $this->assertSame( $legacy->total, $lookup->total );
        $this->assertSame( $legacy->total_pages, $lookup->total_pages );
        $this->assertSame( $legacy->current_page, $lookup->current_page );
    }
}
