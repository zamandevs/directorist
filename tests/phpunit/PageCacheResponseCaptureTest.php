<?php
/**
 * Behavior locks for the pre-render/final-response page-cache handshake.
 */

use Directorist\Cache\Cache_Manager;
use Directorist\Cache\Response_Capture;

class Directorist_Page_Cache_Response_Capture_Test extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        Cache_Manager::instance()->end_request();
    }

    protected function tearDown(): void {
        Cache_Manager::instance()->end_request();
        parent::tearDown();
    }

    public function test_public_contract_functions_are_available_without_starting_collection() {
        $this->assertTrue( function_exists( 'directorist_page_cache_begin_response_capture' ) );
        $this->assertTrue( function_exists( 'directorist_page_cache_finish_response_capture' ) );
        $this->assertFalse( Cache_Manager::instance()->is_collecting_dependencies() );
    }

    public function test_eligible_route_starts_before_render_and_finalizes_collected_dependencies() {
        $capture = new Response_Capture( Cache_Manager::instance() );
        $begin   = $capture->begin( $this->state(), $this->request() );

        $this->assertTrue( $begin['eligible'] );
        $this->assertSame( 'eligible', $begin['reason'] );
        $this->assertSame( 'listings', $begin['route_type'] );
        $this->assertStringStartsWith( 'directorist:page:v1:site:1:', $begin['cache_key'] );
        $this->assertTrue( Cache_Manager::instance()->is_collecting_dependencies() );

        $this->assertTrue( directorist_page_cache_add_listing_dependencies( 91, 7, [ 4 ], [ 11, 12 ] ) );
        $final = $capture->finish();

        $this->assertTrue( $final['eligible'] );
        $this->assertContains( 'directorist:1:site', $final['dependencies'] );
        $this->assertContains( 'directorist:1:collection:listings', $final['dependencies'] );
        $this->assertContains( 'directorist:1:listing:91', $final['dependencies'] );
        $this->assertContains( 'directorist:1:author:7', $final['dependencies'] );
        $this->assertContains( 'directorist:1:directory:4', $final['dependencies'] );
        $this->assertContains( 'directorist:1:term:11', $final['dependencies'] );
        $this->assertFalse( Cache_Manager::instance()->is_collecting_dependencies() );
    }

    public function test_render_time_private_veto_prevents_final_eligibility() {
        $capture = new Response_Capture( Cache_Manager::instance() );

        $this->assertTrue( $capture->begin( $this->state(), $this->request() )['eligible'] );
        directorist_page_cache_mark_private( 'extension_session' );
        $final = $capture->finish();

        $this->assertFalse( $final['eligible'] );
        $this->assertSame( 'private_render', $final['reason'] );
        $this->assertSame( 'extension_session', $final['detail'] );
        $this->assertSame( [], $final['dependencies'] );
    }

    /**
     * @dataProvider rejected_request_provider
     */
    public function test_request_policy_rejections_never_start_dependency_collection( $overrides, $reason ) {
        $capture = new Response_Capture( Cache_Manager::instance() );
        $result  = $capture->begin( $this->state(), $this->request( $overrides ) );

        $this->assertFalse( $result['eligible'] );
        $this->assertSame( $reason, $result['reason'] );
        $this->assertFalse( Cache_Manager::instance()->is_collecting_dependencies() );
    }

    public function rejected_request_provider() {
        return [
            'unsafe method'  => [ [ 'method' => 'POST' ], 'unsafe_method' ],
            'logged in'      => [ [ 'user_logged_in' => true ], 'authenticated_user' ],
            'authorization'  => [ [ 'headers' => [ 'Authorization' => 'Bearer private' ] ], 'authorization_header' ],
            'private cookie' => [ [ 'cookies' => [ 'wordpress_logged_in_hash' => 'private' ] ], 'rejected_cookie' ],
            'REST request'   => [ [ 'flags' => [ 'rest' => true ] ], 'private_context' ],
        ];
    }

    public function test_unknown_or_invalid_query_route_is_never_started() {
        $capture = new Response_Capture( Cache_Manager::instance() );
        $unknown = $capture->begin( $this->state( [ 'configured_pages' => [], 'page_id' => 999 ] ), $this->request() );

        $this->assertFalse( $unknown['eligible'] );
        $this->assertSame( 'unknown_route', $unknown['reason'] );

        $capture = new Response_Capture( Cache_Manager::instance() );
        $invalid = $capture->begin(
            $this->state( [ 'query_args' => [ '_wpnonce' => 'private' ] ] ),
            $this->request( [ 'query_args' => [ '_wpnonce' => 'private' ] ] )
        );

        $this->assertFalse( $invalid['eligible'] );
        $this->assertSame( 'unknown_route', $invalid['reason'] );
        $this->assertFalse( Cache_Manager::instance()->is_collecting_dependencies() );
    }

    public function test_begin_and_finish_are_idempotent_within_one_capture() {
        $capture = new Response_Capture( Cache_Manager::instance() );
        $first   = $capture->begin( $this->state(), $this->request() );
        $second  = $capture->begin( $this->state( [ 'page_id' => 999 ] ), $this->request() );

        $this->assertSame( $first, $second );
        $this->assertSame( $capture->finish(), $capture->finish() );
    }

    private function state( array $overrides = [] ) {
        return array_merge(
            [
                'site_id'             => 1,
                'home_url'            => 'https://example.test/',
                'request_uri'         => '/directory/',
                'page_id'             => 10,
                'object_id'           => 0,
                'term_id'             => 0,
                'author_id'           => 0,
                'taxonomy'            => '',
                'paged'               => 1,
                'query_args'          => [],
                'raw_query'           => '',
                'post_content'        => '',
                'configured_pages'    => [ 'listings' => 10 ],
                'is_singular_listing' => false,
                'directory_ids'       => [],
                'term_ids'            => [],
                'post_author'         => 0,
                'language'            => '',
            ],
            $overrides
        );
    }

    private function request( array $overrides = [] ) {
        return array_merge(
            [
                'method'         => 'GET',
                'request_uri'    => '/directory/',
                'query_args'     => [],
                'cookies'        => [],
                'headers'        => [],
                'user_logged_in' => false,
                'flags'          => [],
            ],
            $overrides
        );
    }
}
