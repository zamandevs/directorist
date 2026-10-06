<?php
/**
 * Behavior locks for bounded Directorist route warm discovery.
 */

use Directorist\Cache\Warm_URL_Discovery;

class Directorist_Page_Cache_Warm_URL_Discovery_Test extends WP_UnitTestCase {
    private $original_options;

    protected function setUp(): void {
        parent::setUp();
        $this->original_options = get_option( 'atbdp_option', [] );
        directorist_page_cache_warm_url_registry()->reset();
    }

    protected function tearDown(): void {
        update_option( 'atbdp_option', $this->original_options );
        directorist_page_cache_warm_url_registry()->reset();
        remove_all_filters( 'directorist_page_cache_discovered_warm_urls' );
        parent::tearDown();
    }

    public function test_discovery_covers_configured_pages_latest_listings_terms_and_bounded_pagination() {
        $listings_page = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Directory',
                'post_content' => '[directorist_all_listing]',
            ]
        );
        $results_page  = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Results',
                'post_content' => '[directorist_search_result]',
            ]
        );
        $private_page  = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
        $options       = $this->original_options;

        $options['all_listing_page']   = $listings_page;
        $options['search_result_page'] = $results_page;
        $options['add_listing_page']   = $private_page;
        update_option( 'atbdp_option', $options );

        $listing_ids = [];

        for ( $index = 1; $index <= 4; ++$index ) {
            $listing_ids[] = self::factory()->post->create(
                [
                    'post_type'   => ATBDP_POST_TYPE,
                    'post_status' => 'publish',
                    'post_title'  => 'Warm Listing ' . $index,
                ]
            );
        }

        $term_ids = [
            self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'Warm Category' ] ),
            self::factory()->term->create( [ 'taxonomy' => ATBDP_LOCATION, 'name' => 'Warm Location' ] ),
            self::factory()->term->create( [ 'taxonomy' => ATBDP_TAGS, 'name' => 'Warm Tag' ] ),
        ];
        wp_set_object_terms( $listing_ids[0], [ $term_ids[0] ], ATBDP_CATEGORY );
        wp_set_object_terms( $listing_ids[0], [ $term_ids[1] ], ATBDP_LOCATION );
        wp_set_object_terms( $listing_ids[0], [ $term_ids[2] ], ATBDP_TAGS );

        $urls = ( new Warm_URL_Discovery() )->discover(
            [
                'listing_limit' => 2,
                'term_limit'    => 2,
                'page_limit'    => 3,
            ]
        );

        $this->assertContains( get_permalink( $listings_page ), $urls );
        $this->assertContains( get_permalink( $results_page ), $urls );
        $this->assertNotContains( get_permalink( $private_page ), $urls );
        $this->assertContains( add_query_arg( 'paged', 2, get_permalink( $listings_page ) ), $urls );
        $this->assertContains( add_query_arg( 'paged', 3, get_permalink( $results_page ) ), $urls );

        $discovered_listings = array_values( array_intersect( array_map( 'get_permalink', $listing_ids ), $urls ) );
        $this->assertCount( 2, $discovered_listings );

        $term_urls        = array_map(
            static function ( $url ) {
                if ( 0 === strpos( $url, '?' ) ) {
                    return home_url( '/' ) . $url;
                }

                return 0 === strpos( $url, '/' ) ? home_url( $url ) : $url;
            },
            array_filter( array_map( 'get_term_link', array_map( 'get_term', $term_ids ) ), 'is_string' )
        );
        $discovered_terms = array_values( array_intersect( $term_urls, $urls ) );
        $this->assertCount( 2, $discovered_terms, wp_json_encode( [ 'terms' => $term_urls, 'urls' => $urls ] ) );
    }

    public function test_discovery_caps_inputs_and_revalidates_extension_urls() {
        $extension_url = home_url( '/extension-public/' );
        $callback      = static function ( $urls, $args ) use ( $extension_url ) {
            $urls[] = $extension_url;
            $urls[] = 'https://foreign.example/private/';

            return $urls;
        };
        add_filter( 'directorist_page_cache_discovered_warm_urls', $callback, 10, 2 );

        $discovery = new Warm_URL_Discovery();
        $urls      = $discovery->discover(
            [
                'listing_limit' => PHP_INT_MAX,
                'term_limit'    => PHP_INT_MAX,
                'page_limit'    => PHP_INT_MAX,
            ]
        );
        $limits    = $discovery->get_last_limits();

        $this->assertSame( 50, $limits['listing_limit'] );
        $this->assertSame( 100, $limits['term_limit'] );
        $this->assertSame( 10, $limits['page_limit'] );
        $this->assertContains( $extension_url, $urls );
        $this->assertNotContains( 'https://foreign.example/private/', $urls );
        $this->assertLessThanOrEqual( 500, count( $urls ) );
    }

    public function test_public_discovery_and_dispatch_apis_remain_inert_without_provider() {
        $urls = directorist_page_cache_discover_warm_urls( [ 'listing_limit' => 0, 'term_limit' => 0, 'page_limit' => 1 ] );

        $this->assertIsArray( $urls );
        $this->assertTrue( class_exists( Warm_URL_Discovery::class, false ) );
        $this->assertSame( 'no_provider', directorist_page_cache_warm_urls( [ home_url( '/directory/' ) ] )['code'] );
    }
}
