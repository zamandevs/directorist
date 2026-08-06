<?php
/**
 * Behavior locks for core bootstrap contracts.
 */

class Directorist_Bootstrap_Behavior_Test extends WP_UnitTestCase {
    public function test_base_accessors_return_the_same_loaded_instance() {
        $this->assertSame( directorist(), ATBDP() );
        $this->assertSame( directorist(), Directorist_Base::instance() );
        $this->assertSame( 1, did_action( 'directorist_loaded' ) );
    }

    public function test_public_service_properties_remain_accessible() {
        $this->assertInstanceOf( ATBDP_Ajax_Handler::class, directorist()->ajax_handler );
        $this->assertInstanceOf( ATBDP_Metabox::class, directorist()->metabox );
        $this->assertInstanceOf( ATBDP_Tools::class, directorist()->tools );
        $this->assertInstanceOf( Directorist\Background_Image_Process::class, directorist()->background_image_process );
        $this->assertInstanceOf( ATBDP_Gateway::class, directorist()->gateway );
        $this->assertInstanceOf( ATBDP_Hooks::class, directorist()->hooks );
        $this->assertInstanceOf( ATBDP_Review_Rating::class, directorist()->review );
    }

    public function test_listing_child_service_properties_remain_accessible_and_stable() {
        $add_listing = directorist()->listing->add_listing;
        $db          = directorist()->listing->db;

        $this->assertInstanceOf( ATBDP_Add_Listing::class, $add_listing );
        $this->assertInstanceOf( ATBDP_Listing_DB::class, $db );
        $this->assertSame( $add_listing, directorist()->listing->add_listing );
        $this->assertSame( $db, directorist()->listing->db );
    }

    public function test_listing_child_services_stay_lazy_while_public_hooks_remain_registered() {
        $listing     = new ATBDP_Listing();
        $reflection  = new ReflectionClass( $listing );
        $add_listing = $reflection->getProperty( 'add_listing' );
        $db          = $reflection->getProperty( 'db' );

        $add_listing->setAccessible( true );
        $db->setAccessible( true );

        $this->assertNull( $add_listing->getValue( $listing ) );
        $this->assertNull( $db->getValue( $listing ) );
        $this->assertSame( 10, has_action( 'template_redirect', [ $listing, 'maybe_handle_listing_renewal' ] ) );
        $this->assertSame( 10, has_action( 'wp_ajax_add_listing_action', [ $listing, 'submit_listing' ] ) );
        $this->assertSame( 10, has_action( 'before_delete_post', [ $listing, 'delete_listing_attachments' ] ) );
    }

    public function test_core_content_types_and_status_are_registered() {
        $this->assertTrue( post_type_exists( ATBDP_POST_TYPE ) );
        $this->assertTrue( taxonomy_exists( ATBDP_CATEGORY ) );
        $this->assertTrue( taxonomy_exists( ATBDP_LOCATION ) );
        $this->assertTrue( taxonomy_exists( ATBDP_TAGS ) );
        $this->assertTrue( taxonomy_exists( ATBDP_TYPE ) );
        $this->assertNotNull( get_post_status_object( 'pending' ) );
    }

    public function test_core_shortcode_callbacks_remain_callable() {
        global $shortcode_tags;

        $shortcodes = [
            'directorist_all_listing',
            'directorist_search_listing',
            'directorist_add_listing',
            'directorist_checkout',
            'directorist_payment_receipt',
            'directorist_transaction_failure',
        ];

        foreach ( $shortcodes as $shortcode ) {
            $this->assertArrayHasKey( $shortcode, $shortcode_tags );
            $this->assertIsCallable( $shortcode_tags[ $shortcode ] );
        }
    }

    public function test_ajax_handler_registers_authenticated_and_public_contracts() {
        $handler = new ATBDP_Ajax_Handler();

        $this->assertSame( 10, has_action( 'wp_ajax_directorist_instant_search', [ $handler, 'instant_search' ] ) );
        $this->assertSame( 10, has_action( 'wp_ajax_nopriv_directorist_instant_search', [ $handler, 'instant_search' ] ) );
        $this->assertSame( 10, has_action( 'wp_ajax_directorist_taxonomy_pagination', [ $handler, 'directorist_taxonomy_pagination' ] ) );
        $this->assertSame( 10, has_action( 'wp_ajax_nopriv_directorist_taxonomy_pagination', [ $handler, 'directorist_taxonomy_pagination' ] ) );
    }

    public function test_geo_query_filters_preserve_sql_contract() {
        global $wpdb;

        $query = new WP_Query();
        $query->set(
            'atbdp_geo_query',
            [
                'latitude'     => 23.8103,
                'longitude'    => 90.4125,
                'lat_field'    => '_manual_lat',
                'lng_field'    => '_manual_lng',
                'min_distance' => 0,
                'max_distance' => 25,
            ]
        );
        $query->set( 'orderby', 'distance' );
        $query->set( 'order', 'ASC' );

        $fields  = apply_filters( 'posts_fields', "{$wpdb->posts}.*", $query );
        $join    = apply_filters( 'posts_join', '', $query );
        $where   = apply_filters( 'posts_where', '1=1', $query );
        $orderby = apply_filters( 'posts_orderby', '', $query );

        $this->assertStringContainsString( 'atbdp_geo_query_distance', $fields );
        $this->assertStringContainsString( 'atbdp_geo_query_lat', $join );
        $this->assertStringContainsString( '_manual_lat', $where );
        $this->assertSame( 'atbdp_geo_query_distance ASC', $orderby );
    }

    public function test_review_bootstrap_adds_comment_support_only_to_listings() {
        $listing_args = apply_filters(
            'register_post_type_args',
            [ 'supports' => [ 'title' ] ],
            ATBDP_POST_TYPE
        );
        $post_args    = apply_filters(
            'register_post_type_args',
            [ 'supports' => [ 'title' ] ],
            'post'
        );

        $this->assertContains( 'comments', $listing_args['supports'] );
        $this->assertNotContains( 'comments', $post_args['supports'] );
    }

    public function test_core_title_and_cron_hooks_are_registered_once() {
        new ATBDP_Hooks();
        new ATBDP_Cron();

        $this->assertSame( 10, has_filter( 'the_title', [ ATBDP_Title_Update::class, 'run' ] ) );
        $this->assertSame( 10, has_action( 'wp', [ ATBDP_Cron::class, 'atbdp_custom_schedule_cron' ] ) );
        $this->assertSame( 10, has_action( 'directorist_hourly_scheduled_events', [ ATBDP_Cron::class, 'run_scheduled_tasks' ] ) );
        $this->assertSame( 10, has_action( 'directorist_cleanup_temporary_uploads', [ ATBDP_Cron::class, 'cleanup_temporary_uploads' ] ) );

        $schedules = apply_filters( 'cron_schedules', [] );

        $this->assertSame( 1800, $schedules['atbdp_listing_manage']['interval'] );
    }

    public function test_checkout_service_registration_is_idempotent() {
        new Directorist\FeaturedListingCheckout();
        new Directorist\PaymentCheckoutService();
        new Directorist\PaymentService();
        new ATBDP_Checkout();

        $types = apply_filters( 'directorist_checkout_types', [] );

        $this->assertSame( [ 'featured_listing', 'payment' ], $types );
        $this->assertSame( 10, has_filter( 'directorist_payment_receipt_is_allowed_retry_payment', [ Directorist\PaymentService::class, 'ignore_retry_payment' ] ) );
        $this->assertSame( 10, has_action( 'init', [ ATBDP_Checkout::class, 'buffer_to_fix_redirection' ] ) );
    }
}
