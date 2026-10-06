<?php

use Directorist\Cache\Built_In\Cache_Engine;

require_once __DIR__ . '/fixtures/PerformanceReviewFixture.php';

/**
 * Cache bypass constants and request-static hook guards cannot be reset in PHP.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Directorist_Performance_Interactions_Review_Regression_Test extends Directorist_Performance_Review_Fixture {
    public function test_cached_interaction_endpoint_is_registered_for_both_session_states() {
        $action = 'directorist_cache_interaction_tokens';
        $this->assertTrue( ATBDP_Ajax_Handler::handles_ajax_action( $action ) );
        $this->assertTrue( ATBDP_Ajax_Handler::should_boot( new \Directorist\Request_Context( [ 'is_ajax' => true, 'ajax_action' => $action ] ) ) );
        $handler = new ATBDP_Ajax_Handler( $action );
        try {
            $this->assertNotFalse( has_action( 'wp_ajax_' . $action, [ $handler, 'handle_cache_interaction_tokens' ] ) );
            $this->assertNotFalse( has_action( 'wp_ajax_nopriv_' . $action, [ $handler, 'handle_cache_interaction_tokens' ] ) );
        } finally {
            remove_action( 'wp_ajax_' . $action, [ $handler, 'handle_cache_interaction_tokens' ] );
            remove_action( 'wp_ajax_nopriv_' . $action, [ $handler, 'handle_cache_interaction_tokens' ] );
        }
    }

    public static function token_endpoint_cases() {
        return [ [ false, false ], [ true, false ], [ true, true ] ];
    }

    /** @dataProvider token_endpoint_cases */
    public function test_token_endpoint_uses_current_session_and_rejects_other_origins( $authenticated, $foreign_origin ) {
        wp_set_current_user( $authenticated ? self::factory()->user->create( [ 'role' => 'administrator' ] ) : 0 );
        $_SERVER['HTTP_ORIGIN'] = $foreign_origin ? 'https://untrusted.invalid' : home_url();
        $die_filter = static function () {
            return static function () { throw new RuntimeException( 'token-response-complete' ); };
        };
        add_filter( 'wp_die_handler', $die_filter );
        add_filter( 'wp_die_ajax_handler', $die_filter );
        add_filter( 'wp_doing_ajax', '__return_true' );
        $handler = new ATBDP_Ajax_Handler( 'directorist_cache_interaction_tokens' );
        ob_start();
        try {
            $handler->handle_cache_interaction_tokens();
            $this->fail( 'JSON handler must terminate the request.' );
        } catch ( RuntimeException $exception ) {
            $this->assertSame( 'token-response-complete', $exception->getMessage() );
        } finally {
            $response = json_decode( ob_get_clean(), true );
            remove_filter( 'wp_die_handler', $die_filter );
            remove_filter( 'wp_die_ajax_handler', $die_filter );
            remove_filter( 'wp_doing_ajax', '__return_true' );
            unset( $_SERVER['HTTP_ORIGIN'] );
        }
        $this->assertSame( ! $foreign_origin, $response['success'] );
        if ( $foreign_origin ) {
            $this->assertSame( [ 'code' => 'invalid_origin' ], $response['data'] );
        } else {
            $this->assertSame( directorist_get_cache_interaction_tokens(), $response['data'] );
            $this->assertNotFalse( wp_verify_nonce( $response['data']['directorist_nonce'], directorist_get_nonce_key() ) );
            $this->assertNotFalse( wp_verify_nonce( $response['data']['login_nonce'], 'ajax-login-nonce' ) );
        }
    }

    public function test_frontend_interaction_bootstrap_is_only_a_consumer_dependency() {
        set_current_screen( 'front' );
        $scripts = \Directorist\Asset_Loader\Scripts::get_all_scripts();
        $this->assertArrayHasKey( 'directorist-cache-interactions', $scripts );
        $this->assertContains( 'directorist-cache-interactions', $scripts['directorist-single-listing']['dep'] ?? [] );
        $this->assertContains( 'directorist-cache-interactions', $scripts['directorist-all-listings']['dep'] ?? [] );
        $this->assertNotContains( 'directorist-cache-interactions', $scripts['directorist-swiper']['dep'] ?? [] );
        set_current_screen( 'dashboard' );
        $scripts = \Directorist\Asset_Loader\Scripts::get_all_scripts();
        $this->assertNotContains( 'directorist-cache-interactions', $scripts['directorist-single-listing']['dep'] ?? [] );
        set_current_screen( 'front' );
    }

    public function test_logged_in_public_cache_hit_keeps_directorist_action_nonce_usable() {
        $original_user = get_current_user_id();
        $original_request = $_REQUEST;
        wp_set_current_user( 0 );
        $nonce = wp_create_nonce( directorist_get_nonce_key() );
        $_REQUEST['directorist_nonce'] = $nonce;
        $this->assertNotFalse( directorist_verify_nonce() );
        $this->cache_nonce_html( $nonce );
        try {
            wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
            $hit = $this->nonce_engine()->boot_early( $this->nonce_server(), [ 'wordpress_logged_in_review' => 'authenticated-session' ] );
            $this->assertTrue( $hit['served'] );
            $this->assertStringContainsString( $nonce, $hit['body'] );
            $this->assertTrue( function_exists( 'directorist_get_cache_interaction_tokens' ) );
            $tokens = directorist_get_cache_interaction_tokens();
            $_REQUEST['directorist_nonce'] = $tokens['directorist_nonce'];
            $this->assertNotFalse( directorist_verify_nonce(), 'Current session tokens must be available to cached public interactions.' );
            $this->assertNotFalse( wp_verify_nonce( $tokens['rest_nonce'], 'wp_rest' ) );
        } finally {
            wp_set_current_user( $original_user );
            $_REQUEST = $original_request;
        }
    }

    public function test_fresh_cache_entry_does_not_deliver_an_expired_action_nonce() {
        $original_user = get_current_user_id();
        $original_request = $_REQUEST;
        $short_nonce_life = static function () { return 2; };
        add_filter( 'nonce_life', $short_nonce_life );
        wp_set_current_user( 0 );
        try {
            $nonce = wp_create_nonce( directorist_get_nonce_key() );
            $_REQUEST['directorist_nonce'] = $nonce;
            $this->assertNotFalse( directorist_verify_nonce() );
            $this->cache_nonce_html( $nonce );
            sleep( 3 );
            $hit = $this->nonce_engine()->boot_early( $this->nonce_server(), [] );
            $this->assertTrue( $hit['served'] );
            $this->assertSame( 'hit', $hit['code'] );
            $this->assertTrue( function_exists( 'directorist_get_cache_interaction_tokens' ) );
            $tokens = directorist_get_cache_interaction_tokens();
            $_REQUEST['directorist_nonce'] = $tokens['directorist_nonce'];
            $this->assertNotFalse( directorist_verify_nonce(), 'Fresh tokens must remain available independently of cached HTML expiry.' );
        } finally {
            remove_filter( 'nonce_life', $short_nonce_life );
            wp_set_current_user( $original_user );
            $_REQUEST = $original_request;
        }
    }
}
