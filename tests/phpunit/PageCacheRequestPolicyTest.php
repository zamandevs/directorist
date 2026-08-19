<?php
/**
 * Behavior and contract tests for Directorist page-cache request safety.
 */

use Directorist\Cache\Eligibility_Result;
use Directorist\Cache\Request_Context;
use Directorist\Cache\Request_Policy;

class Directorist_Page_Cache_Request_Policy_Test extends WP_UnitTestCase {
    public function test_cache_is_disabled_by_default() {
        $result = ( new Request_Policy() )->evaluate( $this->context( [ 'route_owned' => true ] ) );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::CACHE_DISABLED, $result->get_reason() );
    }

    /**
     * @dataProvider safe_method_provider
     */
    public function test_public_directorist_get_and_head_requests_can_pass_the_base_policy( $method ) {
        $result = ( new Request_Policy( true ) )->evaluate(
            $this->context(
                [
                    'method'      => $method,
                    'route_owned' => true,
                ]
            )
        );

        $this->assertTrue( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::ELIGIBLE, $result->get_reason() );
    }

    public function safe_method_provider() {
        return [
            'GET'  => [ 'GET' ],
            'HEAD' => [ 'HEAD' ],
        ];
    }

    /**
     * @dataProvider unsafe_method_provider
     */
    public function test_mutating_and_unknown_methods_are_rejected( $method ) {
        $result = ( new Request_Policy( true ) )->evaluate(
            $this->context(
                [
                    'method'      => $method,
                    'route_owned' => true,
                ]
            )
        );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::UNSAFE_METHOD, $result->get_reason() );
    }

    public function unsafe_method_provider() {
        return [
            'POST'    => [ 'POST' ],
            'PUT'     => [ 'PUT' ],
            'PATCH'   => [ 'PATCH' ],
            'DELETE'  => [ 'DELETE' ],
            'OPTIONS' => [ 'OPTIONS' ],
            'empty'   => [ '' ],
        ];
    }

    public function test_unknown_route_is_rejected() {
        $result = ( new Request_Policy( true ) )->evaluate( $this->context() );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::UNKNOWN_ROUTE, $result->get_reason() );
    }

    public function test_logged_in_request_is_rejected() {
        $result = ( new Request_Policy( true ) )->evaluate(
            $this->context(
                [
                    'route_owned'    => true,
                    'user_logged_in' => true,
                ]
            )
        );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::AUTHENTICATED_USER, $result->get_reason() );
    }

    /**
     * @dataProvider private_context_provider
     */
    public function test_non_public_wordpress_contexts_are_rejected( $flag ) {
        $result = ( new Request_Policy( true ) )->evaluate(
            $this->context(
                [
                    'route_owned' => true,
                    'flags'       => [ $flag => true ],
                ]
            )
        );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::PRIVATE_CONTEXT, $result->get_reason() );
        $this->assertSame( $flag, $result->get_detail() );
    }

    public function private_context_provider() {
        return array_map(
            static function ( $flag ) {
                return [ $flag ];
            },
            [
                'admin',
                'ajax',
                'rest',
                'cron',
                'cli',
                'xmlrpc',
                'preview',
                'password_protected',
                'submission',
                'checkout',
                'payment',
                'dashboard',
                'account',
            ]
        );
    }

    public function test_authorization_header_is_rejected_case_insensitively() {
        $result = ( new Request_Policy( true ) )->evaluate(
            $this->context(
                [
                    'route_owned' => true,
                    'headers'     => [ 'Authorization' => 'Bearer private-token' ],
                ]
            )
        );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::AUTHORIZATION_HEADER, $result->get_reason() );
    }

    /**
     * @dataProvider bypass_header_provider
     */
    public function test_explicit_bypass_and_authenticated_request_headers_are_rejected( $name, $value ) {
        $result = ( new Request_Policy( true ) )->evaluate(
            $this->context(
                [
                    'route_owned' => true,
                    'headers'     => [ $name => $value ],
                ]
            )
        );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::BYPASS_HEADER, $result->get_reason() );
        $this->assertSame( strtolower( $name ), $result->get_detail() );
    }

    public function bypass_header_provider() {
        return [
            'cache-control no-cache' => [ 'Cache-Control', 'public, no-cache' ],
            'cache-control no-store' => [ 'Cache-Control', 'no-store' ],
            'cache-control max age'  => [ 'Cache-Control', 'max-age=0' ],
            'pragma'                 => [ 'Pragma', 'no-cache' ],
            'WordPress REST nonce'   => [ 'X-WP-Nonce', 'private-nonce' ],
            'XHR'                    => [ 'X-Requested-With', 'XMLHttpRequest' ],
        ];
    }

    /**
     * @dataProvider rejected_cookie_provider
     */
    public function test_personalization_and_session_cookies_are_rejected( $cookie_name ) {
        $result = ( new Request_Policy( true ) )->evaluate(
            $this->context(
                [
                    'route_owned' => true,
                    'cookies'     => [ $cookie_name => 'value' ],
                ]
            )
        );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::REJECTED_COOKIE, $result->get_reason() );
        $this->assertSame( $cookie_name, $result->get_detail() );
    }

    public function rejected_cookie_provider() {
        return [
            'logged in'           => [ 'wordpress_logged_in_hash' ],
            'secure auth'         => [ 'wordpress_sec_hash' ],
            'password protected'  => [ 'wp-postpass_hash' ],
            'comment author'      => [ 'comment_author_hash' ],
            'WooCommerce cart'    => [ 'woocommerce_items_in_cart' ],
            'WooCommerce session' => [ 'wp_woocommerce_session_hash' ],
            'Directorist compare' => [ 'atbdlc__selected_listings_id' ],
        ];
    }

    public function test_harmless_wordpress_test_cookie_does_not_block_an_eligible_request() {
        $result = ( new Request_Policy( true ) )->evaluate(
            $this->context(
                [
                    'route_owned' => true,
                    'cookies'     => [ 'wordpress_test_cookie' => 'WP Cookie check' ],
                ]
            )
        );

        $this->assertTrue( $result->is_eligible() );
    }

    public function test_extension_can_add_a_rejected_cookie_prefix_without_global_state() {
        $policy = new Request_Policy( true, [ 'private_extension_' ] );
        $result = $policy->evaluate(
            $this->context(
                [
                    'route_owned' => true,
                    'cookies'     => [ 'private_extension_session' => 'value' ],
                ]
            )
        );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::REJECTED_COOKIE, $result->get_reason() );
    }

    public function test_extension_can_register_a_rejected_cookie_prefix_through_the_public_filter() {
        $callback = static function ( $prefixes ) {
            $prefixes[] = 'filtered_private_';

            return $prefixes;
        };

        add_filter( 'directorist_page_cache_rejected_cookie_prefixes', $callback );
        $result = ( new Request_Policy( true ) )->evaluate(
            $this->context(
                [
                    'route_owned' => true,
                    'cookies'     => [ 'filtered_private_session' => 'value' ],
                ]
            )
        );
        remove_filter( 'directorist_page_cache_rejected_cookie_prefixes', $callback );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::REJECTED_COOKIE, $result->get_reason() );
    }

    public function test_extension_can_veto_an_otherwise_safe_request() {
        $callback = static function () {
            return false;
        };

        add_filter( 'directorist_page_cache_request_eligible', $callback );
        $result = ( new Request_Policy( true ) )->evaluate( $this->context( [ 'route_owned' => true ] ) );
        remove_filter( 'directorist_page_cache_request_eligible', $callback );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::INTEGRATION_VETO, $result->get_reason() );
    }

    public function test_eligibility_filter_cannot_override_an_unsafe_request() {
        $calls    = 0;
        $callback = static function ( $eligible ) use ( &$calls ) {
            ++$calls;

            return true;
        };

        add_filter( 'directorist_page_cache_request_eligible', $callback );
        $result = ( new Request_Policy( true ) )->evaluate(
            $this->context(
                [
                    'method'      => 'POST',
                    'route_owned' => true,
                ]
            )
        );
        remove_filter( 'directorist_page_cache_request_eligible', $callback );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( 0, $calls );
    }

    public function test_query_parameters_are_rejected_until_a_route_allowlist_accepts_them() {
        $result = ( new Request_Policy( true ) )->evaluate(
            $this->context(
                [
                    'route_owned' => true,
                    'query_args'  => [ 'q' => 'hotel' ],
                ]
            )
        );

        $this->assertFalse( $result->is_eligible() );
        $this->assertSame( Eligibility_Result::UNSUPPORTED_QUERY, $result->get_reason() );
    }

    public function test_request_context_normalizes_method_headers_cookie_names_and_flags() {
        $context = new Request_Context(
            [
                'method'  => 'get',
                'headers' => [ 'AUTHORIZATION' => 'Basic value' ],
                'cookies' => [ 'cookie_name' => 'cookie-value' ],
                'flags'   => [ 'ajax' => 1 ],
            ]
        );

        $this->assertSame( 'GET', $context->get_method() );
        $this->assertSame( 'Basic value', $context->get_header( 'authorization' ) );
        $this->assertSame( [ 'cookie_name' ], $context->get_cookie_names() );
        $this->assertTrue( $context->has_flag( 'ajax' ) );
        $this->assertFalse( $context->has_flag( 'rest' ) );
    }

    private function context( array $overrides = [] ) {
        return new Request_Context(
            array_merge(
                [
                    'method'         => 'GET',
                    'request_uri'    => '/directory/',
                    'query_args'     => [],
                    'cookies'        => [],
                    'headers'        => [],
                    'user_logged_in' => false,
                    'route_owned'    => false,
                    'route_type'     => '',
                    'flags'          => [],
                ],
                $overrides
            )
        );
    }
}
