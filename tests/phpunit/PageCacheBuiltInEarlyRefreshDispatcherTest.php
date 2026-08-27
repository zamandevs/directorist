<?php
/**
 * Early soft-expiry refresh handoff validation.
 */

use Directorist\Cache\Built_In\Early_Refresh_Dispatcher;
use PHPUnit\Framework\TestCase;

final class Directorist_Page_Cache_Built_In_Early_Refresh_Dispatcher_Test extends TestCase {
    public function test_valid_handoff_uses_a_short_lived_signature_without_transmitting_the_secret() {
        $sent       = [];
        $dispatcher = new Early_Refresh_Dispatcher(
            static function ( array $request, $body ) use ( &$sent ) {
                $sent = compact( 'request', 'body' );

                return true;
            },
            static function () {
                return 1000;
            }
        );
        $request    = [
            'endpoint'  => 'https://example.test/wp-admin/admin-ajax.php',
            'token'     => 'trusted-token',
            'url'       => 'https://example.test/directory/',
            'hash'      => hash( 'sha256', 'key' ),
            'variation' => [ 'wp-wpml_current_language' => 'sv' ],
        ];

        $this->assertTrue( $dispatcher->send( $request ) );
        parse_str( $sent['body'], $body );

        $this->assertSame( 'directorist_page_cache_refresh_due', $body['action'] );
        $this->assertSame( '1000', $body['timestamp'] );
        $this->assertSame(
            hash_hmac( 'sha256', "1000\n" . $request['hash'] . "\n" . $request['url'], $request['token'] ),
            $body['signature']
        );
        $this->assertArrayNotHasKey( 'token', $body );
        $this->assertSame( wp_json_encode( $request['variation'] ), $body['variation'] );
    }

    public function test_cross_origin_or_malformed_handoff_is_rejected_before_network_io() {
        $dispatcher = new Early_Refresh_Dispatcher();
        $base       = [
            'endpoint' => 'https://example.test/wp-admin/admin-ajax.php',
            'token'    => 'trusted-token',
            'url'      => 'https://example.test/directory/',
            'hash'     => hash( 'sha256', 'key' ),
        ];

        $this->assertFalse( $dispatcher->send( array_merge( $base, [ 'url' => 'https://foreign.test/directory/' ] ) ) );
        $this->assertFalse( $dispatcher->send( array_merge( $base, [ 'endpoint' => 'file:///tmp/receiver' ] ) ) );
        $this->assertFalse( $dispatcher->send( array_merge( $base, [ 'token' => 'short' ] ) ) );
        $this->assertFalse( $dispatcher->send( array_merge( $base, [ 'hash' => '../unsafe' ] ) ) );
    }
}
