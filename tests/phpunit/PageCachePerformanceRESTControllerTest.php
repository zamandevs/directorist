<?php
/**
 * Performance dashboard REST permission and contract tests.
 */

use Directorist\Cache\Cache_Provider;
use Directorist\Cache\Performance_Event_Log;
use Directorist\Cache\Performance_Job_Manager;
use Directorist\Cache\Performance_Listing_Index_Service;
use Directorist\Cache\Performance_Operations;
use Directorist\Cache\Performance_Resource_Catalog;
use Directorist\Cache\Performance_REST_Controller;
use Directorist\Cache\Performance_Settings;
use Directorist\Cache\Performance_Status;

final class Directorist_Performance_REST_Test_Provider implements Cache_Provider {
    public function get_id() {
        return 'directorist-cache';
    }

    public function is_available() {
        return true;
    }

    public function get_capabilities() {
        return [ 'purge_site', 'purge_url', 'purge_urls', 'warm_urls' ];
    }

    public function supports( $capability ) {
        return in_array( $capability, $this->get_capabilities(), true );
    }

    public function invalidate( array $request ) {
        return [ 'success' => true, 'code' => 'invalidated', 'request' => $request ];
    }

    public function warm( array $urls ) {
        return [ 'success' => true, 'code' => 'queued', 'queued' => count( $urls ) ];
    }

    public function get_status() {
        return [ 'available' => true ];
    }
}

final class Directorist_Page_Cache_Performance_REST_Controller_Test extends WP_UnitTestCase {
    private $administrator;

    protected function setUp(): void {
        parent::setUp();
        $this->administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
        delete_option( Performance_Settings::OPTION_NAME );
    }

    protected function tearDown(): void {
        delete_option( Performance_Settings::OPTION_NAME );
        parent::tearDown();
    }

    public function test_routes_require_manage_options_and_expose_only_normalized_contracts() {
        $controller = $this->controller();
        add_action( 'rest_api_init', [ $controller, 'register_routes' ] );
        do_action( 'rest_api_init' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $forbidden = $controller->get_summary( new WP_REST_Request( 'GET' ) );
        $this->assertWPError( $forbidden );
        $this->assertSame( 'directorist_performance_forbidden', $forbidden->get_error_code() );

        wp_set_current_user( $this->administrator );
        $response = $controller->get_summary( new WP_REST_Request( 'GET' ) );
        $data     = rest_get_server()->response_to_data( rest_ensure_response( $response ), false );

        $this->assertSame( 'optimized', $data['page_cache']['state'] );
        $this->assertSame( 'directorist-cache', $data['page_cache']['provider']['id'] );
        $this->assertFalse( $data['page_cache']['coverage']['exact'] );
        $this->assertFalse( $data['page_cache']['coverage']['ready'] );
        $this->assertSame( [ 'listing', 'archive', 'page', 'search' ], array_keys( $data['page_cache']['coverage']['types'] ) );
        $this->assertArrayHasKey( 'listing_index', $data );
        $this->assertArrayNotHasKey( 'inventory', $data['page_cache'] );
    }

    public function test_resource_endpoint_is_paginated_and_never_returns_cached_bodies() {
        wp_set_current_user( $this->administrator );
        $request = new WP_REST_Request( 'GET' );
        $request->set_param( 'type', 'listing' );
        $result = $this->controller()->get_resources( $request );
        $data   = rest_get_server()->response_to_data( rest_ensure_response( $result ), false );

        $this->assertSame( 1, $data['total'] );
        $this->assertSame( 'listing', $data['items'][0]['type'] );
        $this->assertArrayNotHasKey( 'body', $data['items'][0]['cache'] );
    }

    public function test_settings_endpoint_normalizes_values_and_cache_actions_validate_resources() {
        wp_set_current_user( $this->administrator );
        $settings_request = new WP_REST_Request( 'POST' );
        $settings_request->set_body_params(
            [
                'enabled'                => false,
                'cache_duration'         => '21600',
                'cache_filtered_results' => false,
            ]
        );
        $settings = rest_get_server()->response_to_data(
            rest_ensure_response( $this->controller()->update_settings( $settings_request ) ),
            false
        );

        $this->assertFalse( $settings['enabled'] );
        $this->assertSame( '21600', $settings['cache_duration'] );
        $this->assertFalse( $settings['cache_filtered_results'] );

        $invalid = new WP_REST_Request( 'POST' );
        $invalid->set_body_params( [ 'action' => 'purge-selected', 'urls' => [ 'https://foreign.test/private/' ] ] );
        $result = $this->controller()->cache_action( $invalid );

        $this->assertWPError( $result );
        $this->assertSame( 'directorist_performance_invalid_urls', $result->get_error_code() );
    }

    public function test_empty_filtered_cache_action_returns_an_actionable_no_results_error() {
        wp_set_current_user( $this->administrator );
        $request = new WP_REST_Request( 'POST' );
        $request->set_body_params(
            [
                'action' => 'warm-filtered',
                'search' => 'no-matching-resource',
            ]
        );

        $result = $this->controller( true, 0 )->cache_action( $request );

        $this->assertWPError( $result );
        $this->assertSame( 'directorist_performance_no-resources', $result->get_error_code() );
        $this->assertSame( 'No matching Directorist pages were found.', $result->get_error_message() );
    }

    public function test_filtered_cache_action_preserves_listing_taxonomy_scope_for_background_batches() {
        wp_set_current_user( $this->administrator );
        $request = new WP_REST_Request( 'POST' );
        $request->set_body_params(
            [
                'action'       => 'warm-filtered',
                'type'         => 'listing',
                'directory_id' => 21,
                'category_id'  => 34,
                'location_id'  => 55,
                'cache_state'  => 'needs-refresh',
            ]
        );

        $result = $this->controller( true )->cache_action( $request );
        $data   = rest_get_server()->response_to_data( rest_ensure_response( $result ), false );

        $this->assertSame( 21, $data['job']['scope']['directory_id'] );
        $this->assertSame( 34, $data['job']['scope']['category_id'] );
        $this->assertSame( 55, $data['job']['scope']['location_id'] );
        $this->assertSame( 'needs-refresh', $data['job']['scope']['cache_state'] );
    }

    public function test_filter_option_route_is_authenticated_and_returns_a_bounded_contract() {
        $controller = $this->controller();
        add_action( 'rest_api_init', [ $controller, 'register_routes' ] );
        do_action( 'rest_api_init' );

        $category = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'Hotels' ] );
        $listing  = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ] );
        wp_set_object_terms( $listing, [ $category ], ATBDP_CATEGORY );

        wp_set_current_user( $this->administrator );
        $request = new WP_REST_Request( 'GET', '/directorist/v1/admin/performance/resources/filter-options' );
        $request->set_query_params( [ 'kind' => 'category', 'search' => 'Hotel' ] );
        $response = rest_do_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'category', $data['kind'] );
        $this->assertSame( (string) $category, $data['items'][0]['value'] );
        $this->assertArrayNotHasKey( 'taxonomy', $data['items'][0] );
    }

    public function test_resource_route_validates_and_returns_listing_modified_sort() {
        $controller = $this->controller();
        add_action( 'rest_api_init', [ $controller, 'register_routes' ] );
        do_action( 'rest_api_init' );
        wp_set_current_user( $this->administrator );

        $request = new WP_REST_Request( 'GET', '/directorist/v1/admin/performance/resources' );
        $request->set_query_params( [ 'type' => 'all', 'orderby' => 'modified', 'order' => 'DESC' ] );
        $response = rest_do_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'listing', $data['type'] );
        $this->assertSame( 'modified', $data['orderby'] );
        $this->assertSame( 'DESC', $data['order'] );

        $status_request = new WP_REST_Request( 'GET', '/directorist/v1/admin/performance/resources' );
        $status_request->set_query_params( [ 'orderby' => 'cache_state', 'order' => 'ASC', 'cache_state' => 'needs-refresh' ] );
        $status_response = rest_do_request( $status_request );
        $status_data     = $status_response->get_data();

        $this->assertSame( 200, $status_response->get_status() );
        $this->assertSame( 'cache_state', $status_data['orderby'] );
        $this->assertSame( 'needs-refresh', $status_data['cache_state'] );

        $invalid = new WP_REST_Request( 'GET', '/directorist/v1/admin/performance/resources' );
        $invalid->set_query_params( [ 'orderby' => 'post_title' ] );
        $this->assertSame( 400, rest_do_request( $invalid )->get_status() );
    }

    public function test_registered_routes_reject_unauthorized_and_malformed_mutations_before_callbacks() {
        $controller = $this->controller();
        add_action( 'rest_api_init', [ $controller, 'register_routes' ] );
        do_action( 'rest_api_init' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $forbidden = rest_do_request( new WP_REST_Request( 'GET', '/directorist/v1/admin/performance/summary' ) );
        $this->assertSame( 403, $forbidden->get_status() );
        $this->assertSame( 'directorist_performance_forbidden', $forbidden->get_data()['code'] );

        $forbidden_filters = new WP_REST_Request( 'GET', '/directorist/v1/admin/performance/resources/filter-options' );
        $forbidden_filters->set_query_params( [ 'kind' => 'category' ] );
        $this->assertSame( 403, rest_do_request( $forbidden_filters )->get_status() );

        wp_set_current_user( $this->administrator );
        $bad_action = new WP_REST_Request( 'POST', '/directorist/v1/admin/performance/cache/actions' );
        $bad_action->set_body_params( [ 'action' => 'delete-files' ] );
        $invalid_action = rest_do_request( $bad_action );
        $this->assertSame( 400, $invalid_action->get_status() );
        $this->assertSame( 'rest_invalid_param', $invalid_action->get_data()['code'] );

        $bad_settings = new WP_REST_Request( 'POST', '/directorist/v1/admin/performance/settings' );
        $bad_settings->set_body_params( [ 'cache_duration' => 'one-week' ] );
        $invalid_settings = rest_do_request( $bad_settings );
        $this->assertSame( 400, $invalid_settings->get_status() );
        $this->assertSame( 'rest_invalid_param', $invalid_settings->get_data()['code'] );
    }

    private function controller( $with_jobs = false, $resource_total = 1 ) {
        $provider   = new Directorist_Performance_REST_Test_Provider();
        $settings   = new Performance_Settings();
        $events     = new Performance_Event_Log( $settings );
        $status     = new Performance_Status(
            $provider,
            $settings,
            $events,
            static function () {
                return [ 'state' => 'built_in', 'provider' => 'directorist-cache' ];
            },
            static function () {
                return [];
            },
            static function () {
                return [];
            }
        );
        $catalog    = new Performance_Resource_Catalog(
            $provider,
            static function () use ( $resource_total ) {
                if ( 1 > $resource_total ) {
                    return [ 'items' => [], 'total' => 0 ];
                }

                return [
                    'items' => [
                        [
                            'id'         => 'listing-91',
                            'title'      => 'Sample listing',
                            'url'        => home_url( '/directory/sample/' ),
                            'type'       => 'listing',
                            'route_type' => 'listing',
                            'object_id'  => 91,
                        ],
                    ],
                    'total' => $resource_total,
                ];
            },
            static function () {
                return [ 'state' => 'current', 'exact' => true, 'created_at' => time() ];
            }
        );
        $operations = new Performance_Operations( $provider, $settings, $events );
        $jobs       = $with_jobs
            ? new Performance_Job_Manager(
                $catalog,
                $operations,
                static function () {
                    return true;
                }
            )
            : null;

        return new Performance_REST_Controller(
            $status,
            $catalog,
            $settings,
            $operations,
            new Performance_Listing_Index_Service(),
            $jobs
        );
    }
}
