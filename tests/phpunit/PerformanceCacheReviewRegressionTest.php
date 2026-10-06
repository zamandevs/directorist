<?php

use Directorist\Cache\Built_In\Cache_Engine;

require_once __DIR__ . '/fixtures/PerformanceReviewFixture.php';

/**
 * Cache bypass constants and request-static hook guards cannot be reset in PHP.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Directorist_Performance_Cache_Review_Regression_Test extends Directorist_Performance_Review_Fixture {
    public function test_invalidation_during_render_must_not_publish_old_content_as_fresh() {
        $descriptor = [
            'eligible' => true,
            'reason' => 'eligible',
            'site_id' => 1,
            'route_type' => 'listings',
            'cache_key' => 'directorist:page:v1:site:1:listings',
            'dependencies' => [ 'directorist:1:site', 'directorist:1:listing:91' ],
        ];
        $engine_factory = function () use ( $descriptor ) {
            return new Cache_Engine(
                [ 'cache_dir' => $this->root, 'ttl' => 300, 'stale_ttl' => 300 ],
                [
                    'clock' => static function () { return 1000; },
                    'core_begin' => static function () use ( $descriptor ) { return $descriptor; },
                    'core_finish' => static function () use ( $descriptor ) { return $descriptor; },
                ]
            );
        };
        $server = [
            'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test',
            'REQUEST_URI' => '/directory/', 'HTTPS' => 'on',
            'SERVER_PORT' => '443', 'HTTP_ACCEPT' => 'text/html',
        ];
        $writer = $engine_factory();
        $this->assertTrue( $writer->boot_early( $server, [] )['regeneration'] );
        $this->assertTrue( $writer->begin_capture()['eligible'] );
        $old_html = '<!doctype html><html><body>old listing content</body></html>';

        $this->assertTrue( $engine_factory()->invalidate( [
            'site_id' => 1, 'urls' => [],
            'dependencies' => [ 'directorist:1:listing:91' ],
            'generations' => [], 'conservative' => false,
        ] )['success'] );

        $stored = $writer->finalize_capture( $old_html, 200, [ 'Content-Type: text/html' ] );
        $next = $engine_factory()->boot_early( $server, [] );
        $this->assertFalse( $next['served'], 'Old HTML was accepted after invalidation: ' . wp_json_encode( [ 'store' => $stored, 'next' => $next ] ) );
    }

    public static function invalidation_plans() {
        $url = 'https://example.test/directory/review-listing/';
        return [
            [ [ 'dependencies' => [ 'directorist:1:listing:99' ] ] ],
            [ [ 'urls' => [ $url ] ] ],
            [ [ 'entry_hashes' => [ hash( 'sha256', $url ) ] ] ],
            [ [ 'generations' => [ 'directorist:0:lifecycle' ] ] ],
        ];
    }

    public function test_invalidation_before_template_capture_does_not_revalidate_preloaded_content() {
        $writer = $this->nonce_engine();
        $writer->boot_early( $this->nonce_server(), [] );
        $writer->invalidate( [ 'site_id' => 1, 'dependencies' => [ 'directorist:1:listing:91' ] ] );
        $writer->begin_capture();
        $result = $writer->finalize_capture( '<html><body>post loaded before invalidation</body></html>', 200, [ 'Content-Type: text/html' ] );
        $this->assertFalse( $result['success'] );
        $this->assertSame( 'render_invalidated', $result['code'] );
    }

    /** @dataProvider invalidation_plans */
    public function test_render_fence_covers_late_dependencies_urls_entries_and_lifecycle( $plan ) {
        $writer = $this->nonce_engine();
        $this->assertTrue( $writer->boot_early( $this->nonce_server(), [] )['regeneration'] );
        $this->assertTrue( $writer->begin_capture()['eligible'] );
        $this->assertTrue( $writer->invalidate( array_merge( [ 'site_id' => 1 ], $plan ) )['success'] );
        $stored = $writer->finalize_capture( '<html><body>previous content</body></html>', 200, [ 'Content-Type: text/html' ] );
        $this->assertFalse( $stored['success'] );
        $this->assertSame( 'render_invalidated', $stored['code'] );
        $this->assertFalse( $this->nonce_engine()->boot_early( $this->nonce_server(), [] )['served'] );
    }

    public function test_current_render_can_purge_itself_without_reacquiring_its_lock() {
        $file = DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/cache/built-in/class-cache-engine.php';
        $code = 'require ' . var_export( $file, true ) . ';'
            . '$d=["eligible"=>true,"site_id"=>1,"dependencies"=>["directorist:1:site"]];'
            . '$e=new Directorist\\Cache\\Built_In\\Cache_Engine(["cache_dir"=>' . var_export( $this->root, true ) . '],["core_begin"=>function()use($d){return $d;},"core_finish"=>function()use($d){return $d;}]);'
            . '$e->boot_early(["REQUEST_METHOD"=>"GET","HTTP_HOST"=>"example.test","REQUEST_URI"=>"/directory/","HTTPS"=>"on"]);'
            . '$e->begin_capture();$r=$e->invalidate(["site_id"=>1,"urls"=>["https://example.test/directory/"]]);echo json_encode($r);';
        $process = proc_open( [ PHP_BINARY, '-r', $code ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes );
        $this->assertIsResource( $process );
        fclose( $pipes[0] );
        stream_set_blocking( $pipes[1], false );
        stream_set_blocking( $pipes[2], false );
        $output = '';
        $error = '';
        $deadline = microtime( true ) + 3;
        do {
            $output .= stream_get_contents( $pipes[1] );
            $error .= stream_get_contents( $pipes[2] );
            $status = proc_get_status( $process );
            if ( ! $status['running'] ) { break; }
            usleep( 10000 );
        } while ( microtime( true ) < $deadline );
        if ( $status['running'] ) { proc_terminate( $process ); }
        $output .= stream_get_contents( $pipes[1] );
        $error .= stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        proc_close( $process );
        $this->assertFalse( $status['running'], 'Current render deadlocked while purging its own URL: ' . $error );
        $this->assertTrue( json_decode( $output, true )['success'] ?? false, $error . $output );
    }

    public function test_unsupported_response_vary_does_not_reuse_desktop_html_for_mobile() {
        $descriptor = [
            'eligible' => true, 'site_id' => 1, 'route_type' => 'listings',
            'dependencies' => [ 'directorist:1:site' ],
        ];
        $factory = function () use ( $descriptor ) {
            return new Cache_Engine( [ 'cache_dir' => $this->root, 'ttl' => 300 ], [
                'core_begin' => static function () use ( $descriptor ) { return $descriptor; },
                'core_finish' => static function () use ( $descriptor ) { return $descriptor; },
            ] );
        };
        $server = [ 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/directory/', 'HTTPS' => 'on', 'HTTP_USER_AGENT' => 'Desktop review client' ];
        $writer = $factory();
        $this->assertTrue( $writer->boot_early( $server, [] )['regeneration'] );
        $this->assertTrue( $writer->begin_capture()['eligible'] );
        $writer->finalize_capture( '<html><body>desktop-only output</body></html>', 200, [ 'Content-Type: text/html', 'Vary: User-Agent' ] );
        $server['HTTP_USER_AGENT'] = 'Mobile review client';
        $result = $factory()->boot_early( $server, [] );
        $this->assertFalse( $result['served'], 'A mobile request received desktop HTML despite Vary: User-Agent: ' . wp_json_encode( $result ) );
    }

    public function test_repeated_cache_control_cannot_hide_a_private_response_directive() {
        $writer = $this->nonce_engine();
        $this->assertTrue( $writer->boot_early( $this->nonce_server(), [] )['regeneration'] );
        $this->assertTrue( $writer->begin_capture()['eligible'] );
        $result = $writer->finalize_capture( '<html><body>private response output</body></html>', 200, [
            'Content-Type: text/html',
            'Cache-Control: private, no-store',
            'Cache-Control: public, max-age=3600',
        ] );
        $this->assertFalse( $result['success'], 'The later Cache-Control line hid an earlier private/no-store directive: ' . wp_json_encode( $result ) );
    }

    public static function rejected_headers() {
        return [
            [ [ 'Cache-Control: public', 'Cache-Control: no-store' ], 'private_cache_control' ],
            [ [ 'cache-control: no-cache', 'Cache-Control: public' ], 'private_cache_control' ],
            [ [ 'Vary: *' ], 'unsupported_vary' ],
            [ [ 'Vary: Cookie' ], 'unsupported_vary' ],
            [ [ 'Vary: User-Agent', 'Vary: Accept-Encoding' ], 'unsupported_vary' ],
            [ [ 'Vary: Accept-Encoding, User-Agent' ], 'unsupported_vary' ],
        ];
    }

    /** @dataProvider rejected_headers */
    public function test_response_restrictions_are_not_lost( $headers, $expected ) {
        $validator = new \Directorist\Cache\Built_In\Response_Validator();
        $result = $validator->validate( '<html><body>public route</body></html>', 200, array_merge( [ 'Content-Type: text/html' ], $headers ), [
            'eligible' => true, 'dependencies' => [ 'directorist:1:site' ],
        ] );
        $this->assertFalse( $result['accepted'] );
        $this->assertSame( $expected, $result['code'] );
    }

    public function test_supported_encoding_variation_and_public_directives_still_cache() {
        $writer = $this->nonce_engine();
        $writer->boot_early( $this->nonce_server(), [] );
        $writer->begin_capture();
        $stored = $writer->finalize_capture( '<html><body>public output</body></html>', 200, [
            'Content-Type: text/html', 'Cache-Control: public', 'Cache-Control: max-age=3600', 'Vary: Accept-Encoding',
        ] );
        $this->assertTrue( $stored['success'] );
        $this->assertTrue( $this->nonce_engine()->boot_early( $this->nonce_server(), [] )['served'] );
    }

    public function test_entries_from_the_previous_acceptance_policy_are_not_served() {
        $writer = $this->nonce_engine();
        $writer->boot_early( $this->nonce_server(), [] );
        $writer->begin_capture();
        $stored = $writer->finalize_capture( '<html><body>old policy entry</body></html>', 200, [ 'Content-Type: text/html' ] );
        $metadata = json_decode( file_get_contents( $stored['paths']['metadata'] ), true );
        $metadata['schema'] = 1;
        file_put_contents( $stored['paths']['metadata'], wp_json_encode( $metadata ) );
        $this->assertFalse( $this->nonce_engine()->boot_early( $this->nonce_server(), [] )['served'] );
    }
}
