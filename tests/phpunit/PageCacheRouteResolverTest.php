<?php
/**
 * Behavior locks for Directorist-owned frontend route resolution.
 */

use Directorist\Cache\Route_Resolver;

class Directorist_Page_Cache_Route_Resolver_Test extends WP_UnitTestCase {
    /**
     * @dataProvider core_route_provider
     */
    public function test_core_frontend_routes_are_positively_classified( $state, $expected_type, $expected_id ) {
        $identity = ( new Route_Resolver() )->resolve( $this->state( $state ) );

        $this->assertNotNull( $identity );
        $this->assertSame( $expected_type, $identity->get_route_type() );
        $this->assertSame( $expected_id, $identity->get_object_id() );
    }

    public function core_route_provider() {
        return [
            'single listing'           => [
                [ 'is_singular_listing' => true, 'object_id' => 71 ],
                'listing',
                71,
            ],
            'all listings page'        => [
                [ 'page_id' => 10, 'configured_pages' => [ 'listings' => 10 ] ],
                'listings',
                10,
            ],
            'search results page'      => [
                [ 'page_id' => 11, 'configured_pages' => [ 'results' => 11 ] ],
                'search',
                11,
            ],
            'category archive'         => [
                [ 'taxonomy' => ATBDP_CATEGORY, 'term_id' => 31 ],
                'category',
                31,
            ],
            'location archive'         => [
                [ 'taxonomy' => ATBDP_LOCATION, 'term_id' => 32 ],
                'location',
                32,
            ],
            'tag archive'              => [
                [ 'taxonomy' => ATBDP_TAGS, 'term_id' => 33 ],
                'tag',
                33,
            ],
            'configured category page' => [
                [ 'page_id' => 12, 'term_id' => 41, 'configured_pages' => [ 'category' => 12 ] ],
                'category',
                41,
            ],
            'configured location page' => [
                [ 'page_id' => 13, 'term_id' => 42, 'configured_pages' => [ 'location' => 13 ] ],
                'location',
                42,
            ],
            'configured tag page'      => [
                [ 'page_id' => 14, 'term_id' => 43, 'configured_pages' => [ 'tag' => 14 ] ],
                'tag',
                43,
            ],
            'author page'              => [
                [ 'page_id' => 15, 'author_id' => 8, 'configured_pages' => [ 'author' => 15 ] ],
                'author',
                8,
            ],
            'all categories page'      => [
                [ 'page_id' => 16, 'configured_pages' => [ 'categories' => 16 ] ],
                'categories',
                16,
            ],
            'all locations page'       => [
                [ 'page_id' => 17, 'configured_pages' => [ 'locations' => 17 ] ],
                'locations',
                17,
            ],
            'search form page'         => [
                [ 'page_id' => 18, 'configured_pages' => [ 'search' => 18 ] ],
                'search-form',
                18,
            ],
        ];
    }

    public function test_private_configured_pages_are_never_claimed() {
        $resolver = new Route_Resolver();

        foreach ( [ 'form', 'dashboard', 'checkout', 'receipt', 'failed', 'registration', 'login' ] as $type ) {
            $identity = $resolver->resolve(
                $this->state(
                    [
                        'page_id'          => 99,
                        'configured_pages' => [ $type => 99 ],
                    ]
                )
            );

            $this->assertNull( $identity, $type );
        }
    }

    public function test_private_request_probe_distinguishes_private_directorist_content_from_unknown_pages() {
        $resolver = new Route_Resolver();

        $this->assertTrue(
            $resolver->is_private_request(
                $this->state(
                    [
                        'page_id'      => 98,
                        'post_content' => '[directorist_user_dashboard]',
                    ]
                )
            )
        );
        $this->assertTrue(
            $resolver->is_private_request(
                $this->state(
                    [
                        'page_id'          => 99,
                        'configured_pages' => [ 'checkout' => 99 ],
                    ]
                )
            )
        );
        $this->assertFalse( $resolver->is_private_request( $this->state( [ 'page_id' => 100 ] ) ) );
    }

    /**
     * @dataProvider embedded_public_content_provider
     */
    public function test_public_shortcode_and_block_pages_are_claimed_as_embedded_routes( $content ) {
        $identity = ( new Route_Resolver() )->resolve(
            $this->state(
                [
                    'page_id'      => 91,
                    'post_content' => $content,
                ]
            )
        );

        $this->assertNotNull( $identity );
        $this->assertSame( 'embedded', $identity->get_route_type() );
        $this->assertSame( 91, $identity->get_object_id() );
    }

    public function embedded_public_content_provider() {
        return [
            'shortcode' => [ '[directorist_all_listing listings_per_page="6"]' ],
            'block'     => [ '<!-- wp:directorist/all-listing {"view":"grid"} /-->' ],
        ];
    }

    public function test_page_with_private_directorist_content_is_not_claimed_even_when_public_content_exists() {
        $identity = ( new Route_Resolver() )->resolve(
            $this->state(
                [
                    'page_id'      => 92,
                    'post_content' => '[directorist_all_listing][directorist_user_dashboard]',
                ]
            )
        );

        $this->assertNull( $identity );
    }

    public function test_elementor_public_and_private_surfaces_follow_the_same_route_guards() {
        $public_page  = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
        $private_page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
        $mixed_page   = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );

        update_post_meta( $public_page, '_elementor_data', '[{"widgetType":"directorist_all_listing"}]' );
        update_post_meta( $private_page, '_elementor_data', '[{"widgetType":"directorist_user_dashboard"}]' );
        update_post_meta( $mixed_page, '_elementor_data', '[{"elements":[{"widgetType":"directorist_all_listing"},{"widgetType":"directorist_user_login"}]}]' );

        $resolver = new Route_Resolver();
        $public   = $resolver->resolve( $this->state( [ 'page_id' => $public_page ] ) );
        $private  = $resolver->resolve( $this->state( [ 'page_id' => $private_page ] ) );
        $mixed    = $resolver->resolve( $this->state( [ 'page_id' => $mixed_page ] ) );

        $this->assertNotNull( $public );
        $this->assertSame( 'embedded', $public->get_route_type() );
        $this->assertNull( $private );
        $this->assertNull( $mixed );
    }

    public function test_unknown_page_is_not_claimed() {
        $identity = ( new Route_Resolver() )->resolve( $this->state( [ 'page_id' => 100 ] ) );

        $this->assertNull( $identity );
    }

    public function test_query_and_pretty_pagination_resolve_to_the_same_identity() {
        $resolver = new Route_Resolver();
        $base     = [
            'page_id'          => 10,
            'configured_pages' => [ 'listings' => 10 ],
        ];
        $pretty   = $resolver->resolve( $this->state( array_merge( $base, [ 'paged' => 3 ] ) ) );
        $query    = $resolver->resolve(
            $this->state(
                array_merge(
                    $base,
                    [
                        'query_args' => [ 'paged' => '3' ],
                        'raw_query'  => 'paged=3',
                    ]
                )
            )
        );

        $this->assertNotNull( $pretty );
        $this->assertNotNull( $query );
        $this->assertSame( 3, $pretty->get_page_number() );
        $this->assertSame( $pretty->get_cache_key(), $query->get_cache_key() );
    }

    public function test_configured_taxonomy_route_keeps_term_and_backing_page_identity() {
        $identity = ( new Route_Resolver() )->resolve(
            $this->state(
                [
                    'page_id'          => 12,
                    'term_id'          => 41,
                    'configured_pages' => [ 'category' => 12 ],
                ]
            )
        );

        $this->assertNotNull( $identity );
        $this->assertSame( 41, $identity->get_object_id() );
        $this->assertSame( 12, $identity->get_page_id() );
    }

    public function test_invalid_query_variation_makes_an_owned_route_uncacheable() {
        $identity = ( new Route_Resolver() )->resolve(
            $this->state(
                [
                    'page_id'          => 11,
                    'configured_pages' => [ 'results' => 11 ],
                    'query_args'       => [ '_wpnonce' => 'private' ],
                ]
            )
        );

        $this->assertNull( $identity );
    }

    public function test_raw_query_is_derived_from_request_uri_for_duplicate_detection() {
        $identity = ( new Route_Resolver() )->resolve(
            $this->state(
                [
                    'page_id'          => 11,
                    'configured_pages' => [ 'results' => 11 ],
                    'query_args'       => [ 'sort' => 'date-desc' ],
                    'request_uri'      => '/search-result/?sort=price-asc&sort=date-desc',
                ]
            )
        );

        $this->assertNull( $identity );
    }

    public function test_author_directory_pretty_route_varies_the_identity() {
        $resolver = new Route_Resolver();
        $base     = [
            'page_id'          => 15,
            'author_id'        => 8,
            'configured_pages' => [ 'author' => 15 ],
        ];
        $travel   = $resolver->resolve( $this->state( array_merge( $base, [ 'query_args' => [ 'directory-type' => 'travel' ] ] ) ) );
        $market   = $resolver->resolve( $this->state( array_merge( $base, [ 'query_args' => [ 'directory-type' => 'market' ] ] ) ) );

        $this->assertNotNull( $travel );
        $this->assertNotNull( $market );
        $this->assertNotSame( $travel->get_cache_key(), $market->get_cache_key() );
    }

    public function test_theme_or_builder_can_declare_an_unknown_public_route() {
        $callback = static function ( $identity, $state ) {
            if ( 120 !== $state['page_id'] ) {
                return $identity;
            }

            return [
                'route_type' => 'embedded',
                'object_id'  => 120,
            ];
        };

        add_filter( 'directorist_page_cache_route_identity', $callback, 10, 2 );
        $identity = ( new Route_Resolver() )->resolve( $this->state( [ 'page_id' => 120 ] ) );
        remove_filter( 'directorist_page_cache_route_identity', $callback, 10 );

        $this->assertNotNull( $identity );
        $this->assertSame( 'embedded', $identity->get_route_type() );
    }

    public function test_route_filter_cannot_override_canonical_site_query_or_language_material() {
        $directory_id = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Canonical Query Directory',
            ]
        );
        update_term_meta(
            $directory_id,
            'search_form_fields',
            [
                'fields' => [
                    'title' => [
                        'widget_name'         => 'title',
                        'original_widget_key' => 'title',
                    ],
                ],
                'groups' => [
                    [ 'fields' => [ 'title' ] ],
                    [ 'fields' => [] ],
                ],
            ]
        );
        $callback = static function () {
            return [
                'route_type' => 'embedded',
                'object_id'  => 120,
                'site_id'    => 999,
                'variation'  => [ 'q' => 'poisoned' ],
                'language'   => 'xx',
            ];
        };

        add_filter( 'directorist_page_cache_route_identity', $callback );
        $identity = ( new Route_Resolver() )->resolve(
            $this->state(
                [
                    'site_id'       => 4,
                    'page_id'       => 120,
                    'query_args'    => [ 'q' => 'canonical' ],
                    'directory_ids' => [ $directory_id ],
                    'language'      => 'en',
                ]
            )
        );
        remove_filter( 'directorist_page_cache_route_identity', $callback );
        wp_delete_term( $directory_id, ATBDP_DIRECTORY_TYPE );

        $this->assertNotNull( $identity );
        $this->assertSame( 4, $identity->get_site_id() );
        $this->assertSame( [ 'q' => 'canonical' ], $identity->get_variation() );
        $this->assertSame( 'en', $identity->get_language() );
    }

    public function test_taxonomy_filter_query_uses_the_term_directory_search_schema() {
        $directory_id = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Taxonomy Search Directory',
            ]
        );
        $category_id  = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_CATEGORY,
                'name'     => 'Taxonomy Search Category',
            ]
        );
        update_term_meta(
            $directory_id,
            'search_form_fields',
            [
                'fields' => [
                    'text_1' => [
                        'widget_name'         => 'text',
                        'original_widget_key' => 'text_1',
                    ],
                ],
                'groups' => [
                    [ 'fields' => [] ],
                    [ 'fields' => [ 'text_1' ] ],
                ],
            ]
        );
        update_term_meta(
            $directory_id,
            'submission_form_fields',
            [
                'fields' => [
                    'text_1' => [
                        'widget_name' => 'text',
                        'field_key'   => 'custom-taxonomy-text',
                    ],
                ],
            ]
        );
        update_term_meta( $category_id, '_directory_type', [ $directory_id ] );

        $identity = ( new Route_Resolver() )->resolve(
            $this->state(
                [
                    'taxonomy'   => ATBDP_CATEGORY,
                    'term_id'    => $category_id,
                    'query_args' => [
                        'custom_field' => [ 'custom-taxonomy-text' => 'coffee' ],
                    ],
                ]
            )
        );

        wp_delete_term( $category_id, ATBDP_CATEGORY );
        wp_delete_term( $directory_id, ATBDP_DIRECTORY_TYPE );

        $this->assertNotNull( $identity );
        $this->assertSame( [ 'custom-taxonomy-text' => 'coffee' ], $identity->get_variation()['custom_field'] );
    }

    private function state( array $overrides = [] ) {
        return array_merge(
            [
                'site_id'             => 1,
                'home_url'            => 'https://example.test/',
                'request_uri'         => '/directory/',
                'page_id'             => 0,
                'object_id'           => 0,
                'term_id'             => 0,
                'author_id'           => 0,
                'taxonomy'            => '',
                'paged'               => 1,
                'query_args'          => [],
                'raw_query'           => '',
                'post_content'        => '',
                'configured_pages'    => [],
                'is_singular_listing' => false,
                'directory_ids'       => [],
                'term_ids'            => [],
                'post_author'         => 0,
            ],
            $overrides
        );
    }
}
