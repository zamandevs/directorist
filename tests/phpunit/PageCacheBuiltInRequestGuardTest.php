<?php
/**
 * Early request guard and canonical cache-key behavior locks.
 */

use Directorist\Cache\Built_In\Request_Guard;
use Directorist\Cache\Built_In\Request_Key;
use PHPUnit\Framework\TestCase;

final class Directorist_Page_Cache_Built_In_Request_Guard_Test extends TestCase {
    public function test_anonymous_html_get_and_head_requests_are_early_candidates() {
        $guard = new Request_Guard();

        foreach ( [ 'GET', 'HEAD' ] as $method ) {
            $result = $guard->evaluate( $this->server( [ 'REQUEST_METHOD' => $method ] ), [] );

            $this->assertTrue( $result['eligible'], $method );
            $this->assertSame( 'candidate', $result['code'] );
            $this->assertSame( 'https://example.test/directory/', $result['request']['canonical_url'] );
            $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['request']['hash'] );
        }
    }

    /**
     * @dataProvider rejected_request_provider
     */
    public function test_unsafe_early_requests_are_rejected( $server, $cookies, $code ) {
        $result = ( new Request_Guard() )->evaluate( $this->server( $server ), $cookies );

        $this->assertFalse( $result['eligible'] );
        $this->assertSame( $code, $result['code'] );
    }

    public function rejected_request_provider() {
        return [
            'POST'                 => [ [ 'REQUEST_METHOD' => 'POST' ], [], 'unsafe_method' ],
            'authorization'        => [ [ 'HTTP_AUTHORIZATION' => 'Bearer private' ], [], 'authorization_header' ],
            'no-store'             => [ [ 'HTTP_CACHE_CONTROL' => 'no-store' ], [], 'bypass_header' ],
            'pragma'               => [ [ 'HTTP_PRAGMA' => 'no-cache' ], [], 'bypass_header' ],
            'REST nonce'           => [ [ 'HTTP_X_WP_NONCE' => 'private' ], [], 'bypass_header' ],
            'XHR'                  => [ [ 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest' ], [], 'bypass_header' ],
            'JSON accept'          => [ [ 'HTTP_ACCEPT' => 'application/json' ], [], 'non_html_accept' ],
            'WordPress auth'       => [ [], [ 'wordpress_hash' => 'private' ], 'sensitive_cookie' ],
            'post password'        => [ [], [ 'wp-postpass_hash' => 'private' ], 'sensitive_cookie' ],
            'comment author'       => [ [], [ 'comment_author_hash' => 'private' ], 'sensitive_cookie' ],
            'PHP session'          => [ [], [ 'PHPSESSID' => 'private' ], 'sensitive_cookie' ],
            'Woo session'          => [ [], [ 'wp_woocommerce_session_hash' => 'private' ], 'sensitive_cookie' ],
            'Woo cart'             => [ [], [ 'woocommerce_items_in_cart' => '1' ], 'sensitive_cookie' ],
            'Woo recently viewed'  => [ [], [ 'woocommerce_recently_viewed' => '1|2' ], 'sensitive_cookie' ],
            'EDD cart'             => [ [], [ 'edd_items_in_cart' => '1' ], 'sensitive_cookie' ],
            'Directorist compare'  => [ [], [ 'atbdlc__selected_listings_id' => '1,2' ], 'sensitive_cookie' ],
            'raw sensitive cookie' => [ [ 'HTTP_COOKIE' => 'wp-postpass_hash=private' ], [], 'sensitive_cookie' ],
            'invalid language'     => [ [], [ 'wp-wpml_current_language' => '../../private' ], 'invalid_cookie_variation' ],
            'long language'        => [ [], [ 'pll_language' => str_repeat( 'a', 33 ) ], 'invalid_cookie_variation' ],
            'admin'                => [ [ 'REQUEST_URI' => '/wp-admin/edit.php' ], [], 'private_path' ],
            'login'                => [ [ 'REQUEST_URI' => '/wp-login.php' ], [], 'private_path' ],
            'REST path'            => [ [ 'REQUEST_URI' => '/wp-json/wp/v2/posts' ], [], 'private_path' ],
            'nonce query'          => [ [ 'REQUEST_URI' => '/directory/?_wpnonce=private' ], [], 'private_query' ],
            'security query'       => [ [ 'REQUEST_URI' => '/directory/?security=private' ], [], 'private_query' ],
            'preview query'        => [ [ 'REQUEST_URI' => '/directory/?preview=true' ], [], 'private_query' ],
            'fragment'             => [ [ 'REQUEST_URI' => '/directory/#fragment' ], [], 'invalid_request_target' ],
            'control byte'         => [ [ 'REQUEST_URI' => "/directory/\r\nX-Test: injected" ], [], 'invalid_request_target' ],
            'encoded traversal'    => [ [ 'REQUEST_URI' => '/directory/%2e%2e/private/' ], [], 'invalid_request_target' ],
            'encoded slash'        => [ [ 'REQUEST_URI' => '/directory/%2fprivate/' ], [], 'invalid_request_target' ],
            'malformed encoding'   => [ [ 'REQUEST_URI' => '/directory/%zz/' ], [], 'invalid_request_target' ],
            'host injection'       => [ [ 'HTTP_HOST' => "example.test\r\nX-Test: injected" ], [], 'invalid_host' ],
            'host userinfo'        => [ [ 'HTTP_HOST' => 'user@example.test' ], [], 'invalid_host' ],
        ];
    }

    public function test_benign_and_unknown_cookies_share_the_anonymous_cache_key() {
        $guard     = new Request_Guard();
        $anonymous = $guard->evaluate( $this->server(), [] );

        $cookie_sets = [
            [ '_ga' => 'GA1.1.1.2' ],
            [ '_ga_STREAM' => 'GS1.1.1' ],
            [ '_gcl_au' => 'tracking' ],
            [ 'sbjs_session' => 'pgs=1' ],
            [ 'wordpress_test_cookie' => 'WP Cookie check' ],
            [ 'unknown_extension_probe' => 'non-rendering' ],
        ];

        foreach ( $cookie_sets as $cookies ) {
            $result = $guard->evaluate( $this->server(), $cookies );

            $this->assertTrue( $result['eligible'], key( $cookies ) );
            $this->assertSame( $anonymous['request']['hash'], $result['request']['hash'], key( $cookies ) );
            $this->assertSame( [], $result['request']['variation'], key( $cookies ) );
        }
    }

    public function test_wordpress_login_cookie_is_a_read_only_candidate_for_the_anonymous_cache_key() {
        $guard     = new Request_Guard();
        $anonymous = $guard->evaluate( $this->server(), [] );
        $parsed    = $guard->evaluate( $this->server(), [ 'wordpress_logged_in_hash' => 'private' ] );
        $raw       = $guard->evaluate( $this->server( [ 'HTTP_COOKIE' => 'wordpress_logged_in_hash=private' ] ), [] );

        foreach ( [ $parsed, $raw ] as $result ) {
            $this->assertTrue( $result['eligible'] );
            $this->assertTrue( $result['read_only'] );
            $this->assertSame( $anonymous['request']['hash'], $result['request']['hash'] );
            $this->assertSame( [], $result['request']['variation'] );
        }
    }

    public function test_only_a_current_hmac_refresh_signature_can_force_regeneration() {
        $clock = static function () {
            return 1000;
        };
        $guard = new Request_Guard( null, [], true, 'trusted-refresh-secret', $clock );
        $url   = 'https://example.test/directory/';
        $key   = ( new Request_Key() )->from_url( $url );
        $valid = hash_hmac( 'sha256', "1000\n{$url}\n" . $key['hash'], 'trusted-refresh-secret' );
        $base  = $this->server();

        $trusted = $guard->evaluate( array_merge( $base, [ 'HTTP_X_DIRECTORIST_CACHE_REFRESH' => $valid, 'HTTP_X_DIRECTORIST_CACHE_REFRESH_TIME' => '1000', 'HTTP_X_DIRECTORIST_CACHE_REFRESH_KEY' => $key['hash'] ] ), [] );
        $forged  = $guard->evaluate( array_merge( $base, [ 'HTTP_X_DIRECTORIST_CACHE_REFRESH' => str_repeat( 'a', 64 ), 'HTTP_X_DIRECTORIST_CACHE_REFRESH_TIME' => '1000' ] ), [] );
        $expired = $guard->evaluate( array_merge( $base, [ 'HTTP_X_DIRECTORIST_CACHE_REFRESH' => $valid, 'HTTP_X_DIRECTORIST_CACHE_REFRESH_TIME' => '900' ] ), [] );

        $this->assertTrue( $trusted['force_refresh'] );
        $this->assertFalse( $forged['force_refresh'] );
        $this->assertFalse( $expired['force_refresh'] );
        $this->assertTrue( $forged['eligible'], 'A forged internal header must degrade to a normal cache request.' );

        $other_variant = $guard->evaluate(
            array_merge( $base, [ 'HTTP_X_DIRECTORIST_CACHE_REFRESH' => $valid, 'HTTP_X_DIRECTORIST_CACHE_REFRESH_TIME' => '1000', 'HTTP_X_DIRECTORIST_CACHE_REFRESH_KEY' => $key['hash'] ] ),
            [ 'wp-wpml_current_language' => 'sv' ]
        );
        $this->assertFalse( $other_variant['force_refresh'], 'A signature for one cache key must not refresh another language variant.' );
    }

    public function test_read_only_login_cookie_does_not_override_another_private_cookie() {
        $result = ( new Request_Guard() )->evaluate(
            $this->server(),
            [
                'wordpress_logged_in_hash'        => 'private',
                'wp_woocommerce_session_customer' => 'private',
            ]
        );

        $this->assertFalse( $result['eligible'] );
        $this->assertFalse( $result['read_only'] );
        $this->assertSame( 'sensitive_cookie', $result['code'] );
    }

    public function test_raw_benign_and_unknown_cookies_do_not_force_a_bypass() {
        $result = ( new Request_Guard() )->evaluate(
            $this->server( [ 'HTTP_COOKIE' => '_ga=test; sbjs_session=source; unknown=value' ] ),
            []
        );

        $this->assertTrue( $result['eligible'] );
        $this->assertSame( 'candidate', $result['code'] );
    }

    public function test_early_guard_rejects_query_variants_when_filtered_result_caching_is_disabled() {
        $guard = new Request_Guard( null, [], false );
        $query = $guard->evaluate( $this->server( [ 'REQUEST_URI' => '/directory/?q=hotel' ] ), [] );
        $plain = $guard->evaluate( $this->server(), [] );

        $this->assertFalse( $query['eligible'] );
        $this->assertSame( 'filtered_results_disabled', $query['code'] );
        $this->assertTrue( $plain['eligible'] );
    }

    public function test_bounded_language_cookies_vary_the_cache_key_without_changing_the_public_url() {
        $guard    = new Request_Guard();
        $swedish  = $guard->evaluate( $this->server(), [ 'wp-wpml_current_language' => 'sv' ] );
        $english  = $guard->evaluate( $this->server(), [ 'wp-wpml_current_language' => 'en' ] );
        $polylang = $guard->evaluate( $this->server(), [ 'pll_language' => 'sv' ] );

        $this->assertTrue( $swedish['eligible'] );
        $this->assertTrue( $english['eligible'] );
        $this->assertTrue( $polylang['eligible'] );
        $this->assertSame( 'https://example.test/directory/', $swedish['request']['canonical_url'] );
        $this->assertNotSame( $swedish['request']['hash'], $english['request']['hash'] );
        $this->assertNotSame( $swedish['request']['hash'], $polylang['request']['hash'] );
        $this->assertSame( [ 'wp-wpml_current_language' => 'sv' ], $swedish['request']['variation'] );
    }

    public function test_integration_cookie_policy_can_add_sensitive_and_varying_cookies() {
        $guard = new Request_Guard(
            null,
            [
                'reject_prefixes' => [ 'private_extension_' ],
                'vary'            => [
                    'directory_currency' => [
                        'pattern'    => '^[A-Z]{3}$',
                        'max_length' => 3,
                    ],
                ],
            ]
        );

        $private = $guard->evaluate( $this->server(), [ 'private_extension_session' => 'secret' ] );
        $usd     = $guard->evaluate( $this->server(), [ 'directory_currency' => 'USD' ] );
        $eur     = $guard->evaluate( $this->server(), [ 'directory_currency' => 'EUR' ] );

        $this->assertFalse( $private['eligible'] );
        $this->assertSame( 'sensitive_cookie', $private['code'] );
        $this->assertTrue( $usd['eligible'] );
        $this->assertTrue( $eur['eligible'] );
        $this->assertNotSame( $usd['request']['hash'], $eur['request']['hash'] );
    }

    public function test_key_normalizes_scheme_host_default_port_and_unreserved_encoding() {
        $key    = new Request_Key();
        $first  = $key->from_server( $this->server( [ 'HTTP_HOST' => 'EXAMPLE.TEST:443', 'REQUEST_URI' => '/directory/%7eplace/' ] ) );
        $second = $key->from_server( $this->server( [ 'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/directory/~place/' ] ) );
        $http   = $key->from_server( $this->server( [ 'HTTPS' => 'off', 'SERVER_PORT' => '80' ] ) );

        $this->assertTrue( $first['success'] );
        $this->assertSame( 'https://example.test/directory/~place/', $first['canonical_url'] );
        $this->assertSame( $first['hash'], $second['hash'] );
        $this->assertNotSame( $second['hash'], $http['hash'] );
    }

    public function test_url_and_server_key_paths_share_one_canonicalizer() {
        $key     = new Request_Key();
        $server  = $key->from_server( $this->server( [ 'REQUEST_URI' => '/directory/?q=hotel' ] ) );
        $url     = $key->from_url( 'https://example.test/directory/?q=hotel' );
        $foreign = $key->from_url( 'ftp://example.test/directory/' );

        $this->assertSame( $server['hash'], $url['hash'] );
        $this->assertFalse( $foreign['success'] );
        $this->assertSame( 'invalid_scheme', $foreign['code'] );
    }

    private function server( array $overrides = [] ) {
        return array_merge(
            [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.test',
                'REQUEST_URI'    => '/directory/',
                'HTTPS'          => 'on',
                'SERVER_PORT'    => '443',
                'HTTP_ACCEPT'    => 'text/html,application/xhtml+xml',
            ],
            $overrides
        );
    }
}
