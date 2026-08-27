<?php
/**
 * Resource discovery and reconciliation behavior locks.
 */

use Directorist\Cache\Performance_Resource_Discovery;
use Directorist\Cache\Performance_Resource_Reconciler;
use Directorist\Cache\Performance_Resource_Store;

final class Directorist_Page_Cache_Performance_Resource_Reconciler_Test extends WP_UnitTestCase {
    /** @var Performance_Resource_Store */
    private $store;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->store = new Performance_Resource_Store();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query( 'DROP TABLE IF EXISTS ' . $this->store->table_name() );
        delete_option( Performance_Resource_Store::VERSION_OPTION );
        delete_option( Performance_Resource_Store::STATUS_OPTION );
        wp_clear_scheduled_hook( 'directorist_page_cache_reconcile_resources' );
        update_option( 'permalink_structure', '/%postname%/' );
        update_option( 'atbdp_option', array_merge( (array) get_option( 'atbdp_option', [] ), [ 'enable_archive_template' => true, 'search_result_page' => 0 ] ) );
    }

    protected function tearDown(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query( 'DROP TABLE IF EXISTS ' . $this->store->table_name() );
        delete_option( Performance_Resource_Store::VERSION_OPTION );
        delete_option( Performance_Resource_Store::STATUS_OPTION );
        wp_clear_scheduled_hook( 'directorist_page_cache_reconcile_resources' );
        parent::tearDown();
    }

    public function test_reconciliation_discovers_listings_shortcode_pages_valid_archives_and_search() {
        $listing = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Hotel' ] );
        $page    = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Directory', 'post_content' => '[directorist_all_listing]' ] );
        $term    = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'Hotels' ] );
        wp_set_object_terms( $listing, [ $term ], ATBDP_CATEGORY );
        $reconciler = new Performance_Resource_Reconciler(
            $this->store,
            new Performance_Resource_Discovery(),
            '__return_true',
            static function () {
                return 1700000000;
            }
        );
        $started    = $reconciler->start();
        $result     = [];

        for ( $step = 0; $step < 20; ++$step ) {
            $result = $reconciler->process( $started['status']['generation'] );

            if ( 'completed' === $result['code'] ) {
                break;
            }
        }

        $this->assertSame( 'completed', $result['code'] );
        $this->assertSame( 'ready', $this->store->status()['state'] );
        $this->assertSame( 'completed', $this->store->status()['code'] );
        $this->assertSame( 1, $this->store->query( [ 'type' => 'listing' ] )['total'] );
        $this->assertContains( $page, wp_list_pluck( $this->store->query( [ 'type' => 'page' ] )['items'], 'object_id' ) );
        $this->assertGreaterThanOrEqual( 1, $this->store->query( [ 'type' => 'archive' ] )['total'] );
        $this->assertGreaterThanOrEqual( 1, $this->store->query( [ 'type' => 'search' ] )['total'] );
    }

    public function test_listing_and_page_discovery_excludes_every_non_public_post_status() {
        $published_listing = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Published listing' ] );
        $published_page    = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Published directory', 'post_content' => '[directorist_all_listing]' ] );

        foreach ( [ 'draft', 'pending', 'private', 'future', 'trash' ] as $status ) {
            $future = 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : '';
            self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => $status, 'post_title' => ucfirst( $status ) . ' listing', 'post_date' => $future ] );
            self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => $status, 'post_title' => ucfirst( $status ) . ' directory', 'post_content' => '[directorist_all_listing]', 'post_date' => $future ] );
        }

        $discovery = new Performance_Resource_Discovery();
        $listings  = $discovery->batch( 'listing', 0, 100 );
        $pages     = $discovery->batch( 'page', 0, 100 );

        $this->assertSame( [ $published_listing ], wp_list_pluck( $listings['items'], 'object_id' ) );
        $this->assertContains( $published_page, wp_list_pluck( $pages['items'], 'object_id' ) );
        $this->assertCount( 1, array_filter( $pages['items'], static function ( array $item ) use ( $published_page ) { return $published_page === $item['object_id']; } ) );
    }

    public function test_wpml_post_permalink_is_generated_inside_the_resource_language_context() {
        $listing          = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'English listing' ] );
        $language         = 'sv';
        $current_language = static function () use ( &$language ) {
            return $language;
        };
        $switch_language  = static function ( $next ) use ( &$language ) {
            $language = sanitize_key( (string) $next );
        };
        $trid             = static function () {
            return 77;
        };
        $details          = static function () {
            return (object) [ 'language_code' => 'en' ];
        };
        $permalink        = static function ( $url, $post ) use ( &$language, $listing ) {
            return $listing === $post->ID ? home_url( '/' . $language . '/directory/english-listing/' ) : $url;
        };
        $wpml_permalink   = static function ( $url ) {
            return $url;
        };
        add_filter( 'wpml_current_language', $current_language );
        add_action( 'wpml_switch_language', $switch_language );
        add_filter( 'wpml_element_trid', $trid );
        add_filter( 'wpml_element_language_details', $details );
        add_filter( 'post_type_link', $permalink, 10, 2 );
        add_filter( 'wpml_permalink', $wpml_permalink );

        try {
            $batch = ( new Performance_Resource_Discovery() )->batch( 'listing', 0, 100 );
            $item  = current( array_filter( $batch['items'], static function ( array $candidate ) use ( $listing ) { return $listing === $candidate['object_id']; } ) );

            $this->assertIsArray( $item );
            $this->assertSame( home_url( '/en/directory/english-listing/' ), $item['url'] );
            $this->assertSame( 'sv', $language );
        } finally {
            remove_filter( 'wpml_current_language', $current_language );
            remove_action( 'wpml_switch_language', $switch_language );
            remove_filter( 'wpml_element_trid', $trid );
            remove_filter( 'wpml_element_language_details', $details );
            remove_filter( 'post_type_link', $permalink, 10 );
            remove_filter( 'wpml_permalink', $wpml_permalink );
        }
    }

    public function test_post_visibility_transition_removes_stale_catalog_rows_immediately() {
        $listing = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Temporary listing' ] );
        $url     = get_permalink( $listing );
        $this->store->create();
        $this->store->begin_generation( 100 );
        $this->store->upsert( [ [ 'logical_key' => 'listing-' . $listing, 'title' => 'Temporary listing', 'url' => $url, 'type' => 'listing', 'route_type' => 'listing', 'object_id' => $listing ] ], 100 );
        $this->store->complete_generation( 100 );

        $draft              = clone get_post( $listing );
        $draft->post_status = 'draft';
        add_filter( 'directorist_page_cache_allow_runtime_mutation', '__return_true', PHP_INT_MAX );
        $result = directorist_page_cache_sync_resource_post_visibility( 'draft', 'publish', $draft, $this->store );
        remove_filter( 'directorist_page_cache_allow_runtime_mutation', '__return_true', PHP_INT_MAX );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'removed', $result['code'] );
        $this->assertSame( [ $url ], $result['urls'] );
        $this->assertSame( 0, $this->store->query( [ 'type' => 'listing' ] )['total'] );
        $this->assertNotFalse( wp_next_scheduled( 'directorist_page_cache_reconcile_resources' ) );
    }

    public function test_active_catalog_remains_available_until_replacement_generation_completes() {
        $this->store->create();
        $this->store->begin_generation( 100 );
        $this->store->upsert( [ [ 'logical_key' => 'old', 'title' => 'Old', 'url' => home_url( '/old/' ), 'type' => 'page', 'route_type' => 'page' ] ], 100 );
        $this->store->complete_generation( 100 );
        $this->store->begin_generation( 200 );
        $this->store->upsert( [ [ 'logical_key' => 'new', 'title' => 'New', 'url' => home_url( '/new/' ), 'type' => 'page', 'route_type' => 'page' ] ], 200 );

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->assertSame( 2, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->store->table_name() ) );
        $this->assertSame( 'old', $this->store->query( [ 'type' => 'page' ] )['items'][0]['logical_key'] );

        $this->store->complete_generation( 200 );
        $this->assertSame( 'new', $this->store->query( [ 'type' => 'page' ] )['items'][0]['logical_key'] );
    }

    public function test_page_discovery_includes_gutenberg_and_elementor_directorist_surfaces_only() {
        $block_page             = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Block directory',
                'post_content' => '<!-- wp:directorist/all-listing /-->',
            ]
        );
        $elementor_page         = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Elementor directory',
                'post_content' => '',
            ]
        );
        $ordinary_page          = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Ordinary page',
                'post_content' => 'No directory output here.',
            ]
        );
        $private_shortcode_page = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Add listing',
                'post_content' => '[directorist_add_listing]',
            ]
        );
        $private_elementor_page = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'User dashboard',
                'post_content' => '',
            ]
        );
        update_post_meta( $elementor_page, '_elementor_data', '[{"widgetType":"directorist_all_listing"}]' );
        update_post_meta( $private_elementor_page, '_elementor_data', '[{"widgetType":"directorist_user_dashboard"}]' );

        $batch = ( new Performance_Resource_Discovery() )->batch( 'page', 0, 100 );
        $ids   = wp_list_pluck( $batch['items'], 'object_id' );

        $this->assertContains( $block_page, $ids );
        $this->assertContains( $elementor_page, $ids );
        $this->assertNotContains( $ordinary_page, $ids );
        $this->assertNotContains( $private_shortcode_page, $ids );
        $this->assertNotContains( $private_elementor_page, $ids );
    }

    public function test_search_discovery_falls_back_to_the_public_home_url_when_no_search_page_exists() {
        update_option( 'atbdp_option', array_merge( (array) get_option( 'atbdp_option', [] ), [ 'search_result_page' => 999999 ] ) );

        $batch = ( new Performance_Resource_Discovery() )->batch( 'search', 0, 100 );

        $this->assertNotEmpty( $batch['items'] );
        $this->assertSame( home_url( '/' ), trailingslashit( $batch['items'][0]['url'] ) );
        $this->assertSame( 'search', $batch['items'][0]['type'] );
    }

    public function test_wpml_language_scope_is_temporarily_switched_to_all_and_restored() {
        $first   = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'English listing' ] );
        $second  = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Swedish listing' ] );
        $current = 'sv';
        $switch  = static function ( $language ) use ( &$current ) {
            $current = $language;
        };
        $scope   = static function ( $query ) use ( &$current, $second ) {
            if ( ATBDP_POST_TYPE === $query->get( 'post_type' ) && 'all' !== $current ) {
                $query->set( 'post__in', [ $second ] );
            }
        };
        $resolve = static function () use ( &$current ) {
            return $current;
        };
        add_action( 'wpml_switch_language', $switch );
        add_filter( 'wpml_current_language', $resolve );
        add_action( 'pre_get_posts', $scope );

        try {
            $batch = ( new Performance_Resource_Discovery() )->batch( 'listing', 0, 100 );
        } finally {
            remove_action( 'wpml_switch_language', $switch );
            remove_filter( 'wpml_current_language', $resolve );
            remove_action( 'pre_get_posts', $scope );
        }

        $this->assertEqualsCanonicalizing( [ $first, $second ], wp_list_pluck( $batch['items'], 'object_id' ) );
        $this->assertSame( 'sv', $current );
    }

    public function test_wpml_search_urls_are_discovered_in_each_language_context() {
        $current   = 'sv';
        $languages = static function () {
            return [
                'sv' => [ 'url' => home_url( '/' ) ],
                'en' => [ 'url' => home_url( '/en/' ) ],
                'no' => [ 'url' => home_url( '/no/' ) ],
            ];
        };
        $resolve   = static function () use ( &$current ) {
            return $current;
        };
        $switch    = static function ( $language ) use ( &$current ) {
            $current = $language;
        };
        add_filter( 'wpml_active_languages', $languages );
        add_filter( 'wpml_current_language', $resolve );
        add_action( 'wpml_switch_language', $switch );

        try {
            $batch = ( new Performance_Resource_Discovery() )->batch( 'search', 0, 100 );
        } finally {
            remove_filter( 'wpml_active_languages', $languages );
            remove_filter( 'wpml_current_language', $resolve );
            remove_action( 'wpml_switch_language', $switch );
        }

        $this->assertEqualsCanonicalizing( [ untrailingslashit( home_url( '/' ) ), untrailingslashit( home_url( '/en/' ) ), untrailingslashit( home_url( '/no/' ) ) ], array_map( 'untrailingslashit', wp_list_pluck( $batch['items'], 'url' ) ) );
        $this->assertSame( 'sv', $current );
    }
}
