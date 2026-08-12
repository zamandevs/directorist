<?php
/**
 * Behavior locks for Directorist REST collections and instant-search contracts.
 */

class Directorist_REST_AJAX_Behavior_Test extends WP_UnitTestCase {
    private $directory_id;

    private $category_id;

    private $tag_id;

    private $listing_ids = [];

    public function set_up() {
        parent::set_up();

        $this->directory_id = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'REST Collection Directory',
            ]
        );
        $this->category_id  = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_CATEGORY,
                'name'     => 'REST Collection Category',
            ]
        );
        $this->tag_id       = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_TAGS,
                'name'     => 'REST Collection Tag',
            ]
        );

        update_term_meta( $this->directory_id, '_default', 1 );
        update_term_meta(
            $this->directory_id,
            'general_config',
            [
                'similar_listings_number_of_listings_to_show' => 2,
                'listing_from_same_author'                    => false,
                'similar_listings_logics'                     => 'OR',
            ]
        );

        foreach ( [ 'Source', 'Related One', 'Related Two', 'Related Three' ] as $title ) {
            $listing_id = self::factory()->post->create(
                [
                    'post_type'   => ATBDP_POST_TYPE,
                    'post_status' => 'publish',
                    'post_title'  => $title,
                ]
            );

            wp_set_object_terms( $listing_id, [ $this->directory_id ], ATBDP_DIRECTORY_TYPE );
            wp_set_object_terms( $listing_id, [ $this->category_id ], ATBDP_CATEGORY );
            wp_set_object_terms( $listing_id, [ $this->tag_id ], ATBDP_TAGS );
            update_post_meta( $listing_id, '_directory_type', $this->directory_id );

            $this->listing_ids[] = $listing_id;
        }
    }

    public function tear_down() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test cleanup only.
        unset( $_POST['response_context'] );

        remove_all_filters( 'atbdp_related_listings_meta_queries' );
        remove_all_filters( 'directorist_related_listing_args' );
        remove_all_filters( 'directorist_optimize_rest_related_listings_query' );
        remove_all_filters( 'directorist_optimize_related_tax_query_terms' );
        remove_all_filters( 'atbdp_default_listing_orderby' );
        remove_all_filters( 'directorist_rest_listing_data' );
        remove_all_filters( 'directorist_rest_response' );
        remove_all_filters( 'directorist_instant_search_response_context' );

        parent::tear_down();
    }

    public function test_related_ids_preserve_public_filters_result_limit_and_exclusion() {
        $meta_filter_calls = 0;
        $args_filter_calls = 0;
        $filtered_args     = null;
        $query             = null;

        add_filter(
            'atbdp_related_listings_meta_queries',
            static function ( $queries ) use ( &$meta_filter_calls ) {
                ++$meta_filter_calls;

                return $queries;
            }
        );
        add_filter(
            'directorist_related_listing_args',
            static function ( $args ) use ( &$args_filter_calls, &$filtered_args ) {
                ++$args_filter_calls;
                $args['orderby'] = 'ID';
                $args['order']   = 'ASC';
                $filtered_args   = $args;

                return $args;
            }
        );
        $capture_query = static function ( $candidate ) use ( &$query ) {
            if ( 'related_ids' === $candidate->get( 'directorist_query_purpose' ) ) {
                $query = $candidate;
            }
        };
        add_action( 'pre_get_posts', $capture_query );

        $controller = $this->controller();
        $related    = $controller->related_ids( $this->listing_ids[0] );
        remove_action( 'pre_get_posts', $capture_query );

        $this->assertSame( 1, $meta_filter_calls );
        $this->assertSame( 1, $args_filter_calls );
        $this->assertSame( 2, $filtered_args['posts_per_page'] );
        $this->assertSame( [ $this->listing_ids[0] ], $filtered_args['post__not_in'] );
        $this->assertSame( array_slice( $this->listing_ids, 1, 2 ), $related );
        $this->assertInstanceOf( WP_Query::class, $query );
        $this->assertTrue( $query->get( 'no_found_rows' ) );
        $this->assertSame( 'ids', $query->get( 'fields' ) );
        $this->assertFalse( $query->get( 'update_post_meta_cache' ) );
        $this->assertFalse( $query->get( 'update_post_term_cache' ) );
        $this->assertSame( 'term_taxonomy_id', $query->get( 'tax_query' )[0]['field'] );
    }

    public function test_rest_collection_query_keeps_exact_totals() {
        $controller = $this->controller();
        $request    = new WP_REST_Request( 'GET', '/directorist/v2/listings' );
        $request->set_query_params(
            [
                'context'  => 'view',
                'page'     => 1,
                'per_page' => 2,
                'order'    => 'asc',
                'orderby'  => 'id',
                'include'  => [],
                'exclude'  => [],
                'slug'     => '',
                'search'   => '',
                'status'   => 'publish',
                'offset'   => 0,
            ]
        );

        $args = $controller->collection_query_args( $request );

        $this->assertSame( ATBDP_POST_TYPE, $args['post_type'] );
        $this->assertSame( 2, $args['posts_per_page'] );
        $this->assertSame( 'rest_collection', $args['directorist_query_purpose'] );
        $this->assertArrayNotHasKey( 'no_found_rows', $args );
    }

    public function test_rest_collection_preserves_schema_headers_links_fields_and_filters() {
        $controller = $this->controller();
        $request    = new WP_REST_Request( 'GET', '/directorist/v2/listings' );
        $request->set_query_params(
            [
                'context'  => 'view',
                'page'     => 1,
                'per_page' => 2,
                'order'    => 'asc',
                'orderby'  => 'id',
                'include'  => $this->listing_ids,
                'exclude'  => [],
                'slug'     => '',
                'search'   => '',
                'status'   => 'publish',
                'offset'   => 0,
                '_fields'  => 'id,slug',
            ]
        );

        $data_filter_calls     = 0;
        $response_filter_calls = 0;

        add_filter(
            'directorist_rest_listing_data',
            static function ( $data ) use ( &$data_filter_calls ) {
                ++$data_filter_calls;

                return $data;
            }
        );
        add_filter(
            'directorist_rest_response',
            static function ( $response ) use ( &$response_filter_calls ) {
                ++$response_filter_calls;

                return $response;
            }
        );

        $response = $controller->get_items( $request );
        $data     = $response->get_data();
        $headers  = $response->get_headers();

        $this->assertCount( 2, $data );
        $this->assertSame( [ 'id', 'slug', '_links' ], array_keys( $data[0] ) );
        $this->assertSame( 4, (int) $headers['X-WP-Total'] );
        $this->assertSame( 2, (int) $headers['X-WP-TotalPages'] );
        $this->assertStringContainsString( 'rel="next"', $headers['Link'] );
        $this->assertSame( 2, $data_filter_calls );
        $this->assertSame( 1, $response_filter_calls );
    }

    public function test_rest_collection_does_not_enqueue_frontend_assets() {
        $controller = $this->controller();
        $request    = new WP_REST_Request( 'GET', '/directorist/v2/listings' );
        $request->set_query_params(
            [
                'context'  => 'view',
                'page'     => 1,
                'per_page' => 1,
                'order'    => 'asc',
                'orderby'  => 'id',
                'include'  => $this->listing_ids,
                'exclude'  => [],
                'slug'     => '',
                'search'   => '',
                'status'   => 'publish',
                'offset'   => 0,
                '_fields'  => 'id',
            ]
        );

        $before_scripts = wp_scripts()->queue;
        $before_styles  = wp_styles()->queue;

        $controller->get_items( $request );

        $this->assertSame( $before_scripts, wp_scripts()->queue );
        $this->assertSame( $before_styles, wp_styles()->queue );
    }

    public function test_instant_search_response_context_defaults_to_legacy_and_accepts_known_shapes() {
        $method = new ReflectionMethod( ATBDP_Ajax_Handler::class, 'instant_search_response_context' );
        $method->setAccessible( true );
        $handler = directorist()->ajax_handler;

        $this->assertSame( 'legacy', $method->invoke( $handler ) );

        foreach ( [ 'filter', 'directory', 'append' ] as $context ) {
            $_POST['response_context'] = $context;
            $this->assertSame( $context, $method->invoke( $handler ) );
        }

        $_POST['response_context'] = 'unknown-shape';
        $this->assertSame( 'legacy', $method->invoke( $handler ) );
    }

    public function test_related_taxonomy_term_optimization_has_a_compatibility_opt_out() {
        $query = null;
        add_filter( 'directorist_optimize_related_tax_query_terms', '__return_false' );
        $capture_query = static function ( $candidate ) use ( &$query ) {
            if ( 'related_ids' === $candidate->get( 'directorist_query_purpose' ) ) {
                $query = $candidate;
            }
        };
        add_action( 'pre_get_posts', $capture_query );

        $this->controller()->related_ids( $this->listing_ids[0] );
        remove_action( 'pre_get_posts', $capture_query );

        $this->assertSame( 'term_id', $query->get( 'tax_query' )[0]['field'] );
    }

    public function test_related_query_fast_path_has_a_legacy_wrapper_compatibility_opt_out() {
        $wrapper_calls = 0;

        add_filter( 'directorist_optimize_rest_related_listings_query', '__return_false' );
        add_filter(
            'atbdp_default_listing_orderby',
            static function ( $orderby ) use ( &$wrapper_calls ) {
                ++$wrapper_calls;

                return $orderby;
            }
        );

        $related = $this->controller()->related_ids( $this->listing_ids[0] );

        $this->assertGreaterThan( 0, $wrapper_calls );
        $this->assertCount( 2, $related );
    }

    public function test_related_taxonomy_normalization_preserves_hierarchical_descendants() {
        $child_id = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_CATEGORY,
                'name'     => 'REST Collection Child Category',
                'parent'   => $this->category_id,
            ]
        );

        $args = $this->controller()->normalize_related_args(
            [
                'tax_query' => [
                    [
                        'taxonomy' => ATBDP_CATEGORY,
                        'field'    => 'term_id',
                        'terms'    => [ $this->category_id ],
                    ],
                ],
            ]
        );

        $parent = get_term( $this->category_id, ATBDP_CATEGORY );
        $child  = get_term( $child_id, ATBDP_CATEGORY );

        $this->assertSame( 'term_taxonomy_id', $args['tax_query'][0]['field'] );
        $this->assertFalse( $args['tax_query'][0]['include_children'] );
        $this->assertEqualsCanonicalizing(
            [ (int) $parent->term_taxonomy_id, (int) $child->term_taxonomy_id ],
            $args['tax_query'][0]['terms']
        );
    }

    private function controller() {
        if ( ! class_exists( '\\Directorist\\Rest_Api\\Controllers\\Version2\\Listings_Controller' ) ) {
            require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/rest-api/Version1/class-abstract-controller.php';
            require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/rest-api/Version1/class-abstract-posts-controller.php';
            require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/rest-api/Version1/class-listings-controller.php';
            require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/rest-api/Version2/class-listings-controller.php';
        }

        return new class() extends \Directorist\Rest_Api\Controllers\Version2\Listings_Controller {
            public function related_ids( $listing_id ) {
                return $this->get_related_listings_ids( $listing_id );
            }

            public function collection_query_args( WP_REST_Request $request ) {
                return $this->prepare_objects_query( $request );
            }

            public function normalize_related_args( array $args ) {
                return $this->normalize_related_tax_query_terms( $args );
            }
        };
    }
}
