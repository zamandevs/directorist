<?php
/**
 * Performance resource catalog behavior locks.
 */

use Directorist\Cache\Cache_Provider;
use Directorist\Cache\Performance_Resource_Catalog;
use Directorist\Cache\Performance_Resource_Store;
use Directorist\Cache\Built_In\Cache_Storage;
use Directorist\Cache\Built_In\Request_Key;

class Directorist_Page_Cache_Performance_Resource_Catalog_Test extends WP_UnitTestCase {
    private $original_options;

    private $original_permalink_structure;

    protected function setUp(): void {
        parent::setUp();
        $this->original_options             = get_option( 'atbdp_option', false );
        $this->original_permalink_structure = get_option( 'permalink_structure', false );
    }

    protected function tearDown(): void {
        if ( false === $this->original_options ) {
            delete_option( 'atbdp_option' );
        } else {
            update_option( 'atbdp_option', $this->original_options );
        }

        if ( false === $this->original_permalink_structure ) {
            delete_option( 'permalink_structure' );
        } else {
            update_option( 'permalink_structure', $this->original_permalink_structure );
        }

        parent::tearDown();
    }

    public function test_catalog_normalizes_one_table_contract_and_provider_actions() {
        $provider = $this->provider( 'directorist-cache', [ 'warm_urls', 'purge_url' ] );
        $catalog  = new Performance_Resource_Catalog(
            $provider,
            static function () {
                return [
                    'items' => [
                        [
                            'id'         => 'listing:91',
                            'title'      => 'Directory Item',
                            'url'        => home_url( '/directory/item/' ),
                            'type'       => 'listing',
                            'route_type' => 'listing',
                            'object_id'  => 91,
                        ],
                    ],
                    'total' => 1,
                ];
            },
            static function () {
                return [
                    'state'      => 'current',
                    'created_at' => 100,
                    'expires_at' => 200,
                    'variants'   => 1,
                ];
            }
        );

        $result = $catalog->get_items( [ 'page' => 1, 'per_page' => 20 ] );

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 1, $result['pages'] );
        $this->assertSame( 'Directory Item', $result['items'][0]['title'] );
        $this->assertSame( 'listing91', $result['items'][0]['id'] );
        $this->assertSame( 'current', $result['items'][0]['cache']['state'] );
        $this->assertTrue( $result['items'][0]['actions']['warm'] );
        $this->assertTrue( $result['items'][0]['actions']['purge'] );
    }

    public function test_external_provider_state_is_managed_without_claiming_an_exact_cache_hit() {
        $catalog = new Performance_Resource_Catalog(
            $this->provider( 'wp-super-cache', [ 'warm_urls', 'purge_url' ] ),
            static function () {
                return [
                    'items' => [
                        [
                            'id'         => 'page:10',
                            'title'      => 'All Listings',
                            'url'        => home_url( '/listings/' ),
                            'type'       => 'page',
                            'route_type' => 'listings',
                            'object_id'  => 10,
                        ],
                    ],
                    'total' => 1,
                ];
            }
        );

        $item = $catalog->get_items( [] )['items'][0];

        $this->assertSame( 'managed', $item['cache']['state'] );
        $this->assertFalse( $item['cache']['exact'] );
        $this->assertSame( 'wp-super-cache', $item['cache']['provider'] );
    }

    public function test_catalog_discards_invalid_or_private_resources() {
        $catalog = new Performance_Resource_Catalog(
            $this->provider( 'none', [] ),
            static function () {
                return [
                    'items' => [
                        [ 'id' => 'bad', 'title' => 'Admin', 'url' => admin_url(), 'type' => 'page', 'route_type' => 'page' ],
                        [ 'id' => 'also-bad', 'title' => 'Foreign', 'url' => 'https://foreign.example/page/', 'type' => 'page', 'route_type' => 'page' ],
                    ],
                    'total' => 2,
                ];
            }
        );

        $this->assertSame( [], $catalog->get_items( [] )['items'] );
    }

    public function test_catalog_keeps_a_valid_untitled_resource_with_a_predictable_label() {
        $url     = home_url( '/untitled-directory-page/' );
        $catalog = new Performance_Resource_Catalog(
            $this->provider( 'directorist-cache', [ 'warm_urls' ] ),
            static function () use ( $url ) {
                return [
                    'items' => [
                        [
                            'id'         => 'page-10',
                            'title'      => '',
                            'url'        => $url,
                            'type'       => 'page',
                            'route_type' => 'embedded',
                            'object_id'  => 10,
                        ],
                    ],
                    'total' => 1,
                ];
            }
        );

        $items = $catalog->get_items( [] );
        $urls  = $catalog->get_urls( [] );

        $this->assertSame( 1, $items['total'] );
        $this->assertSame( '(no title)', $items['items'][0]['title'] );
        $this->assertSame( 1, $urls['resources'] );
        $this->assertSame( [ $url ], $urls['urls'] );
        $this->assertSame(
            [ 'title' => '(no title)', 'type' => 'page' ],
            $urls['contexts'][ hash( 'sha256', $url ) ]
        );
    }

    public function test_catalog_decodes_html_entities_before_sanitizing_resource_titles() {
        $catalog = new Performance_Resource_Catalog(
            $this->provider( 'directorist-cache', [ 'warm_urls' ] ),
            static function () {
                return [
                    'items' => [
                        [
                            'id'         => 'listing-91',
                            'title'      => 'Agios Nikolaos &#8211; Gialos &lt;script&gt;',
                            'url'        => home_url( '/directory/agios-nikolaos-gialos/' ),
                            'type'       => 'listing',
                            'route_type' => 'listing',
                            'object_id'  => 91,
                        ],
                    ],
                    'total' => 1,
                ];
            }
        );

        $expected = html_entity_decode( 'Agios Nikolaos &#8211; Gialos', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $result   = $catalog->get_items( [ 'type' => 'listing' ] );

        $this->assertSame( $expected, $result['items'][0]['title'] );
    }

    public function test_variant_details_are_explicit_bounded_and_body_free() {
        $catalog = new Performance_Resource_Catalog(
            $this->provider( 'directorist-cache', [ 'purge_url' ] ),
            null,
            null,
            static function () {
                return [
                    [
                        'canonical_url' => home_url( '/directory/item/?q=hotel' ),
                        'state'         => 'current',
                        'created_at'    => 100,
                        'expires_at'    => 200,
                        'body_size'     => 512,
                        'language'      => 'en',
                        'has_query'     => true,
                    ],
                ];
            }
        );

        $result = $catalog->get_variants( home_url( '/directory/item/' ), 'listing' );

        $this->assertTrue( $result['exact'] );
        $this->assertSame( 'current', $result['items'][0]['state'] );
        $this->assertArrayNotHasKey( 'body', $result['items'][0] );
    }

    public function test_built_in_variant_details_inspect_every_stored_language_url_directly() {
        global $wpdb;

        $store       = new Performance_Resource_Store();
        $storage     = new Cache_Storage( WP_CONTENT_DIR . '/cache/directorist-page-cache' );
        $english_url = home_url( '/catalog-variant-en/' );
        $swedish_url = home_url( '/catalog-variant-sv/' );
        $english_key = ( new Request_Key() )->from_url( $english_url );
        $swedish_key = ( new Request_Key() )->from_url( $swedish_url );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query( "DROP TABLE IF EXISTS {$store->table_name()}" );
        delete_option( Performance_Resource_Store::VERSION_OPTION );
        delete_option( Performance_Resource_Store::STATUS_OPTION );
        $this->assertTrue( $store->create() );
        $store->begin_generation( 100 );
        $store->upsert(
            [
                [ 'logical_key' => 'wpml-catalog-variant', 'title' => 'English hotel', 'url' => $english_url, 'type' => 'listing', 'route_type' => 'listing', 'language' => 'en' ],
                [ 'logical_key' => 'wpml-catalog-variant', 'title' => 'Swedish hotel', 'url' => $swedish_url, 'type' => 'listing', 'route_type' => 'listing', 'language' => 'sv' ],
            ],
            100
        );
        $store->complete_generation( 100 );

        try {
            $headers = [ 'content-type' => 'text/html; charset=UTF-8' ];
            $this->assertTrue( $storage->store( $english_key, '<html>English</html>', [ 'cache_key' => 'en', 'site_id' => 1, 'route_type' => 'listing', 'language' => 'en', 'dependencies' => [] ], $headers, HOUR_IN_SECONDS, MINUTE_IN_SECONDS )['success'] );
            $this->assertTrue( $storage->store( $swedish_key, '<html>Swedish</html>', [ 'cache_key' => 'sv', 'site_id' => 1, 'route_type' => 'listing', 'language' => 'sv', 'dependencies' => [] ], $headers, HOUR_IN_SECONDS, MINUTE_IN_SECONDS )['success'] );
            $this->assertCount( 2, $store->get_variants( $english_url, 'listing' ) );
            $english_state = $storage->inspect( $english_key );
            $swedish_state = $storage->inspect( $swedish_key );
            $this->assertSame( 'current', $english_state['state'], $english_state['code'] );
            $this->assertSame( 'current', $swedish_state['state'], $swedish_state['code'] );

            $catalog = new Performance_Resource_Catalog( $this->provider( 'directorist-cache', [ 'purge_url' ] ), null, null, null, $store );
            $result  = $catalog->get_variants( $english_url, 'listing' );

            $this->assertSame( 'ready', $result['code'] );
            $this->assertSame( [ $english_url, $swedish_url ], wp_list_pluck( $result['items'], 'url' ) );
            $this->assertSame( [ 'en', 'sv' ], wp_list_pluck( $result['items'], 'language' ) );
        } finally {
            $storage->purge( $english_key );
            $storage->purge( $swedish_key );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query( "DROP TABLE IF EXISTS {$store->table_name()}" );
            delete_option( Performance_Resource_Store::VERSION_OPTION );
            delete_option( Performance_Resource_Store::STATUS_OPTION );
        }
    }

    public function test_extension_resource_filter_applies_to_typed_catalog_requests() {
        $callback = static function ( $result, $args ) {
            if ( 'listing' === $args['type'] ) {
                $result['items'][] = [
                    'id'         => 'extension-listing',
                    'title'      => 'Extension listing route',
                    'url'        => home_url( '/extension-listing/' ),
                    'type'       => 'listing',
                    'route_type' => 'listing',
                ];
                ++$result['total'];
            }

            return $result;
        };
        add_filter( 'directorist_performance_cache_resources', $callback, 10, 2 );
        $catalog = new Performance_Resource_Catalog(
            $this->provider( 'none', [] ),
            static function ( array $args ) {
                unset( $args );

                return [ 'items' => [], 'total' => 0 ];
            }
        );
        $result  = $catalog->get_items( [ 'type' => 'listing' ] );
        remove_filter( 'directorist_performance_cache_resources', $callback, 10 );

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 'extension-listing', $result['items'][0]['id'] );
    }

    public function test_listing_catalog_filters_directory_category_and_location_before_pagination() {
        $directory_one   = self::factory()->term->create( [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'name' => 'Hotels' ] );
        $directory_two   = self::factory()->term->create( [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'name' => 'Tours' ] );
        $category        = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'Beach stays' ] );
        $location        = self::factory()->term->create( [ 'taxonomy' => ATBDP_LOCATION, 'name' => 'Dhaka' ] );
        $matching        = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Matching hotel' ] );
        $wrong_location  = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Wrong location' ] );
        $wrong_directory = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Wrong directory' ] );

        wp_set_object_terms( $matching, [ $directory_one ], ATBDP_DIRECTORY_TYPE );
        wp_set_object_terms( $matching, [ $category ], ATBDP_CATEGORY );
        wp_set_object_terms( $matching, [ $location ], ATBDP_LOCATION );
        wp_set_object_terms( $wrong_location, [ $directory_one ], ATBDP_DIRECTORY_TYPE );
        wp_set_object_terms( $wrong_location, [ $category ], ATBDP_CATEGORY );
        wp_set_object_terms( $wrong_directory, [ $directory_two ], ATBDP_DIRECTORY_TYPE );
        wp_set_object_terms( $wrong_directory, [ $category ], ATBDP_CATEGORY );
        wp_set_object_terms( $wrong_directory, [ $location ], ATBDP_LOCATION );

        $catalog = new Performance_Resource_Catalog( $this->provider( 'none', [] ) );
        $result  = $catalog->get_items(
            [
                'type'         => 'all',
                'search'       => 'Matching',
                'directory_id' => $directory_one,
                'category_id'  => $category,
                'location_id'  => $location,
            ]
        );

        $this->assertSame( 'listing', $result['type'] );
        $this->assertSame( 1, $result['total'] );
        $this->assertSame( $matching, $result['items'][0]['object_id'] );
    }

    public function test_listing_filter_options_are_searchable_bounded_and_exclude_unused_terms() {
        $used    = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'Boutique Hotels' ] );
        $unused  = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'Unused Hotels' ] );
        $listing = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ] );
        wp_set_object_terms( $listing, [ $used ], ATBDP_CATEGORY );

        $catalog = new Performance_Resource_Catalog( $this->provider( 'none', [] ) );
        $result  = $catalog->get_filter_options( [ 'kind' => 'category', 'search' => 'Hotel' ] );

        $this->assertSame( 'category', $result['kind'] );
        $this->assertSame( 1, $result['total'] );
        $this->assertSame( (string) $used, $result['items'][0]['value'] );
        $this->assertSame( 'Boutique Hotels', $result['items'][0]['label'] );
        $this->assertNotContains( (string) $unused, wp_list_pluck( $result['items'], 'value' ) );

        $selected = $catalog->get_filter_options( [ 'kind' => 'category', 'search' => 'No match', 'selected' => $used ] );
        $this->assertSame( (string) $used, $selected['items'][0]['value'] );
        $this->assertLessThanOrEqual( Performance_Resource_Catalog::MAX_FILTER_OPTIONS, count( $selected['items'] ) );
    }

    public function test_listing_catalog_exposes_modified_time_and_sorts_it_in_both_directions() {
        global $wpdb;

        $older      = self::factory()->post->create(
            [
                'post_type'     => ATBDP_POST_TYPE,
                'post_status'   => 'publish',
                'post_title'    => 'Older listing',
                'post_modified' => '2025-01-01 10:00:00',
            ]
        );
        $newer      = self::factory()->post->create(
            [
                'post_type'     => ATBDP_POST_TYPE,
                'post_status'   => 'publish',
                'post_title'    => 'Newer listing',
                'post_modified' => '2025-02-01 10:00:00',
            ]
        );
        $newest_tie = self::factory()->post->create(
            [
                'post_type'     => ATBDP_POST_TYPE,
                'post_status'   => 'publish',
                'post_title'    => 'Newest listing with tied time',
                'post_modified' => '2025-02-01 10:00:00',
            ]
        );
        $wpdb->update( $wpdb->posts, [ 'post_modified' => '2025-01-01 10:00:00', 'post_modified_gmt' => '2025-01-01 10:00:00' ], [ 'ID' => $older ] );
        $wpdb->update( $wpdb->posts, [ 'post_modified' => '2025-02-01 10:00:00', 'post_modified_gmt' => '2025-02-01 10:00:00' ], [ 'ID' => $newer ] );
        $wpdb->update( $wpdb->posts, [ 'post_modified' => '2025-02-01 10:00:00', 'post_modified_gmt' => '2025-02-01 10:00:00' ], [ 'ID' => $newest_tie ] );
        clean_post_cache( $older );
        clean_post_cache( $newer );
        clean_post_cache( $newest_tie );
        $catalog = new Performance_Resource_Catalog( $this->provider( 'none', [] ) );

        $descending = $catalog->get_items( [ 'type' => 'listing', 'orderby' => 'modified', 'order' => 'DESC' ] );
        $ascending  = $catalog->get_items( [ 'type' => 'listing', 'orderby' => 'modified', 'order' => 'ASC' ] );

        $this->assertSame( 'modified', $descending['orderby'] );
        $this->assertSame( 'DESC', $descending['order'] );
        $this->assertSame( [ $newest_tie, $newer, $older ], wp_list_pluck( $descending['items'], 'object_id' ) );
        $this->assertSame( [ $older, $newer, $newest_tie ], wp_list_pluck( $ascending['items'], 'object_id' ) );
        $this->assertSame( strtotime( '2025-02-01 10:00:00 UTC' ), $descending['items'][0]['modified_at'] );
    }

    public function test_modified_sort_is_listing_only_and_non_post_resources_have_no_modified_time() {
        $catalog = new Performance_Resource_Catalog(
            $this->provider( 'none', [] ),
            static function ( array $args ) {
                return [
                    'items' => [
                        [
                            'id'         => 'term-12',
                            'title'      => 'Hotels',
                            'url'        => home_url( '/listing-category/hotels/' ),
                            'type'       => 'archive',
                            'route_type' => 'category',
                        ],
                    ],
                    'total' => 1,
                    'args'  => $args,
                ];
            }
        );

        $result = $catalog->get_items( [ 'type' => 'all', 'orderby' => 'modified', 'order' => 'DESC' ] );

        $this->assertSame( 'listing', $result['type'] );
        $this->assertSame( 0, $result['items'][0]['modified_at'] );
    }

    public function test_provider_failures_degrade_resource_state_and_actions_without_breaking_the_table() {
        $provider = new class() implements Cache_Provider {
            public function get_id() {
                throw new RuntimeException( 'provider failed' ); }

            public function is_available() {
                throw new RuntimeException( 'provider failed' ); }

            public function get_capabilities() {
                throw new RuntimeException( 'provider failed' ); }

            public function supports( $capability ) {
                unset( $capability ); throw new RuntimeException( 'provider failed' ); }

            public function invalidate( array $request ) {
                unset( $request ); throw new RuntimeException( 'provider failed' ); }

            public function warm( array $urls ) {
                unset( $urls ); throw new RuntimeException( 'provider failed' ); }

            public function get_status() {
                throw new RuntimeException( 'provider failed' ); }
        };
        $catalog  = new Performance_Resource_Catalog(
            $provider,
            static function () {
                return [
                    'items' => [
                        [
                            'id'         => 'listing-91',
                            'title'      => 'Directory Item',
                            'url'        => home_url( '/directory/item/' ),
                            'type'       => 'listing',
                            'route_type' => 'listing',
                        ],
                    ],
                    'total' => 1,
                ];
            },
            static function () {
                throw new RuntimeException( 'state failed' );
            }
        );

        $result = $catalog->get_items();

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 'unavailable', $result['items'][0]['cache']['state'] );
        $this->assertSame( 'unknown', $result['items'][0]['cache']['provider'] );
        $this->assertFalse( $result['items'][0]['actions']['warm'] );
        $this->assertFalse( $result['items'][0]['actions']['purge'] );
        $this->assertSame( 'provider-unavailable', $catalog->get_variants( home_url( '/directory/item/' ), 'listing' )['code'] );
    }

    public function test_catalog_rejects_a_matching_host_with_a_different_origin_port() {
        $catalog = new Performance_Resource_Catalog(
            $this->provider( 'none', [] ),
            static function () {
                $parts = wp_parse_url( home_url( '/' ) );
                $url   = $parts['scheme'] . '://' . $parts['host'] . ':6553/directory/item/';

                return [
                    'items' => [ [ 'id' => 'wrong-port', 'title' => 'Wrong port', 'url' => $url, 'type' => 'listing', 'route_type' => 'listing' ] ],
                    'total' => 1,
                ];
            }
        );

        $this->assertSame( [], $catalog->get_items()['items'] );
    }

    public function test_background_urls_include_only_bounded_real_archive_pages() {
        update_option( 'permalink_structure', '/%postname%/' );
        update_option( 'atbdp_option', array_merge( (array) get_option( 'atbdp_option', [] ), [ 'all_listing_page_items' => 2 ] ) );
        $term_id = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'Hotels' ] );

        for ( $index = 0; $index < 12; ++$index ) {
            $listing_id = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ] );
            wp_set_object_terms( $listing_id, [ $term_id ], ATBDP_CATEGORY );
        }

        clean_term_cache( $term_id, ATBDP_CATEGORY );
        $term    = get_term( $term_id, ATBDP_CATEGORY );
        $catalog = new Performance_Resource_Catalog(
            $this->provider( 'directorist-cache', [ 'warm_urls' ] ),
            static function () use ( $term ) {
                return [
                    'items' => [
                        [
                            'id'         => 'term-' . $term->term_id,
                            'title'      => $term->name,
                            'url'        => home_url( '/listing-category/hotels/' ),
                            'type'       => 'archive',
                            'route_type' => 'category',
                            'object_id'  => $term->term_id,
                        ],
                    ],
                    'total' => 1,
                ];
            }
        );

        $result = $catalog->get_urls( [ 'type' => 'archive' ] );

        $this->assertSame( 1, $result['resources'] );
        $this->assertCount( Performance_Resource_Catalog::MAX_PAGINATED_PAGES, $result['urls'] );
        $this->assertStringContainsString( '/page/2', $result['urls'][1] );
        $this->assertStringContainsString( '/page/5', $result['urls'][4] );
    }

    public function test_invalid_archive_routes_are_removed_before_totals_are_reported() {
        update_option( 'permalink_structure', '/%postname%/' );
        self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'No public route' ] );

        $invalid_link = static function () {
            return false;
        };
        add_filter( 'term_link', $invalid_link );

        try {
            $result = ( new Performance_Resource_Catalog( $this->provider( 'none', [] ) ) )->get_items( [ 'type' => 'archive' ] );
        } finally {
            remove_filter( 'term_link', $invalid_link );
        }

        $this->assertSame( 0, $result['total'] );
        $this->assertSame( [], $result['items'] );
    }

    public function test_published_directorist_shortcode_pages_are_discovered_without_configured_page_ids() {
        update_option( 'atbdp_option', [] );
        $page_id = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Directory landing',
                'post_content' => '[directorist_all_listing]',
            ]
        );

        $result = ( new Performance_Resource_Catalog( $this->provider( 'none', [] ) ) )->get_items( [ 'type' => 'page' ] );

        $this->assertContains( $page_id, wp_list_pluck( $result['items'], 'object_id' ) );
    }

    public function test_multilingual_listing_translations_are_one_logical_resource_with_url_variants() {
        $english = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'English hotel' ] );
        $swedish = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Swedish hotel' ] );
        $trid    = static function ( $value, $object_id ) use ( $english, $swedish ) {
            return in_array( (int) $object_id, [ $english, $swedish ], true ) ? 77 : $value;
        };
        $details = static function ( $value, array $args ) use ( $english, $swedish ) {
            if ( ! in_array( (int) $args['element_id'], [ $english, $swedish ], true ) ) {
                return $value;
            }

            return (object) [ 'language_code' => (int) $args['element_id'] === $english ? 'en' : 'sv' ];
        };
        add_filter( 'wpml_element_trid', $trid, 10, 2 );
        add_filter( 'wpml_element_language_details', $details, 10, 2 );

        try {
            $catalog = new Performance_Resource_Catalog( $this->provider( 'none', [] ) );
            $result  = $catalog->get_items( [ 'type' => 'listing' ] );
            $urls    = $catalog->get_urls( [ 'type' => 'listing' ] );
        } finally {
            remove_filter( 'wpml_element_trid', $trid, 10 );
            remove_filter( 'wpml_element_language_details', $details, 10 );
        }

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 2, $result['items'][0]['variants'] );
        $this->assertCount( 2, $urls['urls'] );
        $this->assertSame(
            [ 'title' => 'English hotel', 'type' => 'listing' ],
            $urls['contexts'][ hash( 'sha256', get_permalink( $english ) ) ]
        );
        $this->assertSame(
            [ 'title' => 'English hotel', 'type' => 'listing' ],
            $urls['contexts'][ hash( 'sha256', get_permalink( $swedish ) ) ]
        );
    }

    public function test_built_in_cache_state_can_be_filtered_and_sorted_before_pagination() {
        $states  = [
            'listing-1' => 'current',
            'listing-2' => 'uncached',
            'listing-3' => 'expired',
        ];
        $catalog = new Performance_Resource_Catalog(
            $this->provider( 'directorist-cache', [ 'warm_urls' ] ),
            static function () {
                $items = [];

                for ( $id = 1; $id <= 3; ++$id ) {
                    $items[] = [ 'id' => 'listing-' . $id, 'title' => 'Listing ' . $id, 'url' => home_url( '/listing-' . $id . '/' ), 'type' => 'listing', 'route_type' => 'listing' ];
                }

                return [ 'items' => $items, 'total' => 3 ];
            },
            static function ( array $resource ) use ( $states ) {
                return [ 'state' => $states[ $resource['id'] ], 'exact' => true, 'provider' => 'directorist-cache' ];
            }
        );

        $filtered = $catalog->get_items( [ 'cache_state' => 'uncached' ] );
        $sorted   = $catalog->get_items( [ 'orderby' => 'cache_state', 'order' => 'ASC' ] );

        $this->assertSame( 1, $filtered['total'] );
        $this->assertSame( 'listing-2', $filtered['items'][0]['id'] );
        $this->assertSame( [ 'current', 'expired', 'uncached' ], wp_list_pluck( wp_list_pluck( $sorted['items'], 'cache' ), 'state' ) );
    }

    public function test_persistent_state_filter_and_response_use_one_stable_snapshot() {
        global $wpdb;

        $store = new Performance_Resource_Store();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query( "DROP TABLE IF EXISTS {$store->table_name()}" );
        delete_option( Performance_Resource_Store::VERSION_OPTION );
        delete_option( Performance_Resource_Store::STATUS_OPTION );
        $this->assertTrue( $store->create() );
        $store->begin_generation( 100 );
        $store->upsert(
            [
                [
                    'logical_key' => 'outdated-listing',
                    'title'       => 'Outdated listing',
                    'url'         => home_url( '/directory/outdated-listing/' ),
                    'type'        => 'listing',
                    'route_type'  => 'listing',
                ],
            ],
            100
        );
        $store->complete_generation( 100 );
        $store->update_cache_state( home_url( '/directory/outdated-listing/' ), [ 'state' => 'invalidated' ] );

        try {
            $catalog = new Performance_Resource_Catalog( $this->provider( 'directorist-cache', [ 'warm_urls' ] ), null, null, null, $store );
            $result  = $catalog->get_items( [ 'type' => 'listing', 'cache_state' => 'invalidated' ] );

            $this->assertSame( 1, $result['total'] );
            $this->assertSame( 'invalidated', $result['items'][0]['cache']['state'] );
            $this->assertSame( 0, $store->query( [ 'type' => 'listing', 'cache_state' => 'uncached' ] )['total'] );
            $this->assertSame( 1, $store->query( [ 'type' => 'listing', 'cache_state' => 'invalidated' ] )['total'] );
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query( "DROP TABLE IF EXISTS {$store->table_name()}" );
            delete_option( Performance_Resource_Store::VERSION_OPTION );
            delete_option( Performance_Resource_Store::STATUS_OPTION );
        }
    }

    public function test_provider_identity_and_capabilities_are_resolved_once_per_table_request() {
        $provider = new class() implements Cache_Provider {
            public $id_calls = 0;

            public $availability_calls = 0;

            public $support_calls = 0;

            public function get_id() {
                ++$this->id_calls; return 'external-test'; }

            public function is_available() {
                ++$this->availability_calls; return true; }

            public function get_capabilities() {
                return [ 'warm_urls', 'purge_url' ]; }

            public function supports( $capability ) {
                ++$this->support_calls; return in_array( $capability, $this->get_capabilities(), true ); }

            public function invalidate( array $request ) {
                unset( $request ); return [ 'success' => true, 'code' => 'purged' ]; }

            public function warm( array $urls ) {
                unset( $urls ); return [ 'success' => true, 'code' => 'queued' ]; }

            public function get_status() {
                return [ 'available' => true ]; }
        };
        $catalog  = new Performance_Resource_Catalog(
            $provider,
            static function () {
                $items = [];

                for ( $id = 1; $id <= 3; ++$id ) {
                    $items[] = [ 'id' => 'listing-' . $id, 'title' => 'Listing ' . $id, 'url' => home_url( '/listing-' . $id . '/' ), 'type' => 'listing', 'route_type' => 'listing' ];
                }

                return [ 'items' => $items, 'total' => 3 ];
            }
        );

        $catalog->get_items();

        $this->assertSame( 1, $provider->id_calls );
        $this->assertSame( 1, $provider->availability_calls );
        $this->assertSame( 2, $provider->support_calls );
    }

    private function provider( $id, array $capabilities ) {
        return new class( $id, $capabilities ) implements Cache_Provider {
            private $id;

            private $capabilities;

            public function __construct( $id, $capabilities ) {
                $this->id           = $id;
                $this->capabilities = $capabilities;
            }

            public function get_id() {
                return $this->id; }

            public function is_available() {
                return 'none' !== $this->id; }

            public function get_capabilities() {
                return $this->capabilities; }

            public function supports( $capability ) {
                return in_array( $capability, $this->capabilities, true ); }

            public function invalidate( array $request ) {
                unset( $request ); return [ 'success' => true, 'code' => 'purged' ]; }

            public function warm( array $urls ) {
                unset( $urls ); return [ 'success' => true, 'code' => 'queued' ]; }

            public function get_status() {
                return [ 'available' => $this->is_available() ]; }
        };
    }
}
