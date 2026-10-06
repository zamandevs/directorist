<?php

use Directorist\Cache\External_Response_Guard;
use Directorist\Cache\Cache_Enabler_Compatibility;
use Directorist\Cache\WP_Fastest_Cache_Compatibility;

class Directorist_Page_Cache_External_Response_Guard_Test extends WP_UnitTestCase {
    /** @dataProvider denied_routes */
    public function test_private_and_rejected_routes_never_start_capture( $route ) {
        $begins = 0;
        $denied = [];
        $guard = $this->guard( $route, $begins, $denied );
        $guard->guard_request();
        $this->assertFalse( $guard->allows_storage() );
        $this->assertSame( 0, $begins );
        $this->assertSame( [ $route . '_route' ], $denied );
    }

    public static function denied_routes() {
        return [ [ 'private' ], [ 'rejected' ] ];
    }

    public function test_unrelated_wordpress_output_is_left_to_native_provider() {
        $begins = 0;
        $denied = [];
        $guard = $this->guard( 'unrelated', $begins, $denied );
        $guard->guard_request();
        $this->assertTrue( $guard->allows_storage() );
        $this->assertSame( 0, $begins );
        $this->assertSame( [], $denied );
    }

    public function test_public_capture_is_idempotent_and_late_render_veto_is_retained() {
        $begins = 0;
        $denied = [];
        $finishes = 0;
        $guard = $this->guard( 'public', $begins, $denied, static function () use ( &$finishes ) {
            ++$finishes;
            return [ 'eligible' => false, 'reason' => 'private_output' ];
        } );
        $guard->guard_request();
        $guard->guard_request();
        $this->assertFalse( $guard->allows_storage() );
        $this->assertFalse( $guard->allows_storage() );
        $this->assertSame( 1, $begins );
        $this->assertSame( 1, $finishes );
        $this->assertSame( [ 'private_output' ], $denied );
    }

    public function test_route_exception_fails_closed_without_swallowing_response() {
        $denied = [];
        $guard = new External_Response_Guard( [
            'route_probe' => static function () { throw new RuntimeException( 'probe failed' ); },
            'deny_cache' => static function ( $reason ) use ( &$denied ) { $denied[] = $reason; },
        ] );
        $guard->guard_request();
        $this->assertFalse( $guard->allows_storage() );
        $this->assertSame( [ 'route_exception' ], $denied );
    }

    public function test_explicit_private_veto_after_footer_still_blocks_native_storage() {
        $private = false;
        $guard = new External_Response_Guard( [
            'route_probe' => static function () { return 'public'; },
            'begin_capture' => static function () { return [ 'eligible' => true ]; },
            'finish_capture' => static function () { return [ 'eligible' => true ]; },
            'private_probe' => static function () use ( &$private ) { return $private; },
            'deny_cache' => static function () {},
        ] );
        $this->assertTrue( $guard->allows_storage() );
        $private = true;
        $this->assertFalse( $guard->allows_storage() );
    }

    /** @dataProvider failed_captures */
    public function test_capture_failure_never_allows_native_storage( $stage ) {
        $runtime = [
            'route_probe' => static function () { return 'public'; },
            'begin_capture' => static function () { return [ 'eligible' => true ]; },
            'finish_capture' => static function () { return [ 'eligible' => true ]; },
            'private_probe' => static function () { return false; },
            'deny_cache' => static function () {},
        ];
        $runtime[ $stage ] = static function () { throw new RuntimeException( 'boundary failed' ); };
        $this->assertFalse( ( new External_Response_Guard( $runtime ) )->allows_storage() );
    }

    public static function failed_captures() {
        return [ [ 'begin_capture' ], [ 'finish_capture' ], [ 'private_probe' ] ];
    }

    public function test_provider_filters_preserve_native_veto_and_original_output() {
        $runtime = [ 'route_probe' => static function () { return 'private'; }, 'deny_cache' => static function () {} ];
        $ce = new Cache_Enabler_Compatibility( $runtime );
        $wpfc = new WP_Fastest_Cache_Compatibility( $runtime );
        $this->assertTrue( $ce->bypass_cache( true ) );
        $wpfc->register();
        $this->assertFalse( $wpfc->register() );
        $this->assertSame( '', $wpfc->filter_buffer( '<p>private</p>', 'html' ) );
        $this->assertSame( '', $wpfc->filter_buffer( '<p>private</p>', 'cache' ) );
        $this->assertSame( '<xml>data</xml>', $wpfc->filter_buffer( '<xml>data</xml>', 'xml' ) );
        $wpfc->deactivate();
        $this->assertFalse( has_filter( 'wpfc_buffer_callback_filter', [ $wpfc, 'filter_buffer' ] ) );
    }

    public function test_private_path_pattern_is_bounded_and_handles_root_and_encoded_paths() {
        $pattern = '/' . External_Response_Guard::path_pattern( [ '/account/', '/', '/%C3%B8konomi/', "/bad\npath/" ] ) . '/i';
        foreach ( [ '/', '/?a=b', '/account', '/account/edit/', '/account?x=y', '/%C3%B8konomi/', "/\xC3\xB8konomi/" ] as $path ) {
            $this->assertSame( 1, preg_match( $pattern, $path ), $path );
        }
        $this->assertSame( 0, preg_match( $pattern, '/all-listings/' ) );
        $this->assertSame( 0, preg_match( $pattern, '/accounting/' ) );
        $this->assertStringNotContainsString( "\n", $pattern );
    }

    private function guard( $route, &$begins, array &$denied, $finish = null ) {
        return new External_Response_Guard( [
            'route_probe' => static function () use ( $route ) { return $route; },
            'begin_capture' => static function () use ( &$begins ) { ++$begins; return [ 'eligible' => true ]; },
            'finish_capture' => $finish ?: static function () { return [ 'eligible' => true ]; },
            'private_probe' => static function () { return false; },
            'deny_cache' => static function ( $reason ) use ( &$denied ) { $denied[] = $reason; },
        ] );
    }
}
