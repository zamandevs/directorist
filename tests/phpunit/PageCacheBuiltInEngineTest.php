<?php
/**
 * Early hit, late capture, and invalidation behavior locks.
 */

use Directorist\Cache\Built_In\Cache_Engine;
use Directorist\Cache\Built_In\Cache_Storage;
use Directorist\Cache\Built_In\Request_Key;
use PHPUnit\Framework\TestCase;

final class Directorist_Page_Cache_Built_In_Cache_Engine_Test extends TestCase {
    private $root;

    private $now = 1000;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/directorist-pc06-engine-' . bin2hex( random_bytes( 6 ) );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_core_approved_complete_response_is_stored_and_served_on_next_request() {
        $engine = $this->engine();
        $miss   = $engine->boot_early( $this->server(), [] );

        $this->assertFalse( $miss['served'] );
        $this->assertTrue( $miss['regeneration'] );
        $this->assertSame( 'miss', $miss['code'] );
        $this->assertTrue( $engine->begin_capture()['eligible'] );

        $stored = $engine->finalize_capture(
            $this->html( 'fresh' ),
            200,
            [ 'Content-Type: text/html; charset=UTF-8', 'Content-Language: en-US' ]
        );

        $this->assertTrue( $stored['success'] );
        $this->assertSame( 'stored', $stored['code'] );

        $hit = $this->engine()->boot_early( $this->server(), [] );

        $this->assertTrue( $hit['served'] );
        $this->assertSame( 200, $hit['status'] );
        $this->assertSame( $this->html( 'fresh' ), $hit['body'] );
        $this->assertSame( 'text/html; charset=UTF-8', $hit['headers']['Content-Type'] );
        $this->assertSame( (string) strlen( $this->html( 'fresh' ) ), $hit['headers']['Content-Length'] );
        $this->assertArrayNotHasKey( 'X-Directorist-Cache', $hit['headers'] );

        $metadata_path = glob( $this->root . '/pages/*/*/*.json' )[0];
        $metadata      = json_decode( file_get_contents( $metadata_path ), true );

        $this->assertArrayHasKey( 'directorist:0:lifecycle', $metadata['generations'] );
    }

    public function test_head_and_if_modified_since_are_served_without_a_body() {
        $this->seed( 'conditional' );
        $head_server                                  = $this->server( [ 'REQUEST_METHOD' => 'HEAD' ] );
        $head                                         = $this->engine()->boot_early( $head_server, [] );
        $conditional_server                           = $this->server();
        $conditional_server['HTTP_IF_MODIFIED_SINCE'] = gmdate( 'D, d M Y H:i:s', $this->now ) . ' GMT';
        $conditional                                  = $this->engine()->boot_early( $conditional_server, [] );

        $this->assertTrue( $head['served'] );
        $this->assertSame( 200, $head['status'] );
        $this->assertSame( '', $head['body'] );
        $this->assertSame( (string) strlen( $this->html( 'conditional' ) ), $head['headers']['Content-Length'] );
        $this->assertTrue( $conditional['served'] );
        $this->assertSame( 304, $conditional['status'] );
        $this->assertSame( '', $conditional['body'] );
        $this->assertArrayNotHasKey( 'Content-Length', $conditional['headers'] );
    }

    public function test_logged_in_request_can_read_an_existing_public_entry() {
        $this->seed( 'public-for-authenticated-reader' );

        $hit = $this->engine()->boot_early( $this->server(), [ 'wordpress_logged_in_hash' => 'private' ] );

        $this->assertTrue( $hit['served'] );
        $this->assertFalse( $hit['regeneration'] );
        $this->assertSame( $this->html( 'public-for-authenticated-reader' ), $hit['body'] );
    }

    public function test_logged_in_cache_miss_never_regenerates_or_starts_capture() {
        $engine = $this->engine();
        $miss   = $engine->boot_early( $this->server(), [ 'wordpress_logged_in_hash' => 'private' ] );

        $this->assertFalse( $miss['served'] );
        $this->assertFalse( $miss['regeneration'] );
        $this->assertSame( 'read_only_miss', $miss['code'] );
        $this->assertSame( [ 'eligible' => false, 'reason' => 'request_not_prepared' ], $engine->begin_capture() );

        $anonymous = $this->engine()->boot_early( $this->server(), [] );

        $this->assertTrue( $anonymous['regeneration'], 'The authenticated miss must not retain the regeneration lock.' );
        $this->engine()->release_request();
    }

    public function test_bypass_and_core_rejection_never_capture_and_release_any_lock() {
        $bypass = $this->engine()->boot_early( $this->server( [ 'HTTP_AUTHORIZATION' => 'Bearer private' ] ), [] );

        $this->assertFalse( $bypass['served'] );
        $this->assertFalse( $bypass['regeneration'] );
        $this->assertSame( 'authorization_header', $bypass['code'] );

        $engine = $this->engine(
            [
                'core_begin' => static function () {
                    return [ 'eligible' => false, 'reason' => 'unknown_route' ];
                },
            ]
        );

        $this->assertTrue( $engine->boot_early( $this->server(), [] )['regeneration'] );
        $this->assertFalse( $engine->begin_capture()['eligible'] );

        $replacement_engine = $this->engine();
        $replacement        = $replacement_engine->boot_early( $this->server(), [] );

        $this->assertTrue( $replacement['regeneration'], 'A rejected core route must release its regeneration lock.' );
        $replacement_engine->release_request();
    }

    public function test_private_or_incomplete_response_is_not_stored_and_lock_is_released() {
        $engine = $this->engine(
            [
                'core_finish' => static function () {
                    return [
                        'eligible'     => false,
                        'reason'       => 'private_render',
                        'dependencies' => [],
                    ];
                },
            ]
        );

        $engine->boot_early( $this->server(), [] );
        $engine->begin_capture();
        $rejected = $engine->finalize_capture( '<html>partial', 200, [ 'Content-Type: text/html' ] );

        $this->assertFalse( $rejected['success'] );
        $this->assertSame( 'core_ineligible', $rejected['code'] );

        $replacement_engine = $this->engine();
        $replacement        = $replacement_engine->boot_early( $this->server(), [] );

        $this->assertTrue( $replacement['regeneration'] );
        $replacement_engine->release_request();
    }

    public function test_only_one_regenerator_owns_a_cold_key() {
        $writer = $this->engine();
        $reader = $this->engine();

        $this->assertTrue( $writer->boot_early( $this->server(), [] )['regeneration'] );
        $contended = $reader->boot_early( $this->server(), [] );

        $this->assertFalse( $contended['served'] );
        $this->assertFalse( $contended['regeneration'] );
        $this->assertSame( 'lock_contended', $contended['code'] );
        $writer->release_request();
    }

    public function test_soft_expired_visitor_receives_stale_html_and_dispatches_only_one_refresh() {
        $this->seed( 'soft-stale' );
        $this->now  = 1011;
        $dispatches = [];
        $options    = [
            'config'             => [
                'refresh_endpoint' => 'https://example.test/wp-admin/admin-ajax.php',
                'refresh_token'    => 'trusted-token',
            ],
            'refresh_dispatcher' => static function ( array $request ) use ( &$dispatches ) {
                $dispatches[] = $request;

                return true;
            },
        ];

        $first  = $this->engine( $options )->boot_early( $this->server(), [] );
        $second = $this->engine( $options )->boot_early( $this->server(), [] );

        $this->assertTrue( $first['served'] );
        $this->assertTrue( $first['stale'] );
        $this->assertSame( 'refresh_queued', $first['code'] );
        $this->assertTrue( $second['served'] );
        $this->assertSame( 'refresh_due', $second['code'] );
        $this->assertCount( 1, $dispatches );
        $this->assertSame( 'https://example.test/directory/', $dispatches[0]['url'] );
    }

    public function test_same_url_language_variants_receive_independent_refresh_handoffs() {
        $storage = new Cache_Storage( $this->root, function () { return $this->now; } );

        foreach ( [ 'en', 'sv' ] as $language ) {
            $key = ( new Request_Key() )->from_url( 'https://example.test/directory/', [ 'wp-wpml_current_language' => $language ] );
            $this->assertTrue( $storage->store( $key, $this->html( $language ), array_merge( $this->descriptor(), [ 'dependencies' => array_merge( $this->descriptor()['dependencies'], [ 'directorist:0:lifecycle' ] ) ] ), [ 'content-type' => 'text/html' ], 10, 30 )['success'] );
        }

        $this->now  = 1011;
        $dispatches = [];
        $options    = [
            'config'             => [ 'refresh_endpoint' => 'https://example.test/wp-admin/admin-ajax.php', 'refresh_token' => 'trusted-token' ],
            'refresh_dispatcher' => static function ( array $request ) use ( &$dispatches ) {
                $dispatches[] = $request;

                return true;
            },
        ];

        foreach ( [ 'en', 'sv' ] as $language ) {
            $result = $this->engine( $options )->boot_early( $this->server(), [ 'wp-wpml_current_language' => $language ] );
            $this->assertTrue( $result['served'] );
        }

        $this->assertCount( 2, $dispatches );
        $this->assertNotSame( $dispatches[0]['hash'], $dispatches[1]['hash'] );
        $this->assertSame( [ 'wp-wpml_current_language' => 'en' ], $dispatches[0]['variation'] );
        $this->assertSame( [ 'wp-wpml_current_language' => 'sv' ], $dispatches[1]['variation'] );
    }

    public function test_signed_preload_forces_regeneration_but_a_forged_header_does_not() {
        $this->seed( 'signed-refresh' );
        $config    = [ 'config' => [ 'refresh_token' => 'trusted-token' ] ];
        $key       = ( new Request_Key() )->from_url( 'https://example.test/directory/' );
        $signature = hash_hmac( 'sha256', $this->now . "\nhttps://example.test/directory/\n" . $key['hash'], 'trusted-token' );

        $forged = $this->engine( $config )->boot_early( $this->server( [ 'HTTP_X_DIRECTORIST_CACHE_REFRESH' => 'forged' ] ), [] );
        $this->assertTrue( $forged['served'] );
        $this->assertSame( 'hit', $forged['code'] );

        $trusted_engine = $this->engine( $config );
        $trusted        = $trusted_engine->boot_early( $this->server( [ 'HTTP_X_DIRECTORIST_CACHE_REFRESH' => $signature, 'HTTP_X_DIRECTORIST_CACHE_REFRESH_TIME' => (string) $this->now, 'HTTP_X_DIRECTORIST_CACHE_REFRESH_KEY' => $key['hash'] ] ), [] );
        $this->assertFalse( $trusted['served'] );
        $this->assertTrue( $trusted['regeneration'] );
        $this->assertSame( 'refresh_forced', $trusted['code'] );
        $contender = $this->engine( $config );
        $contended = $contender->boot_early( $this->server( [ 'HTTP_X_DIRECTORIST_CACHE_REFRESH' => $signature, 'HTTP_X_DIRECTORIST_CACHE_REFRESH_TIME' => (string) $this->now, 'HTTP_X_DIRECTORIST_CACHE_REFRESH_KEY' => $key['hash'] ] ), [] );
        $this->assertTrue( $contended['served'] );
        $this->assertSame( 'refresh_in_progress', $contended['code'] );
        $trusted_engine->release_request();
    }

    public function test_hard_expired_entry_is_not_served_and_regenerates_synchronously() {
        $this->seed( 'hard-expired' );
        $this->now = 1041;

        $result = $this->engine()->boot_early( $this->server(), [] );

        $this->assertFalse( $result['served'] );
        $this->assertTrue( $result['regeneration'] );
        $this->assertSame( 'stale_expired', $result['code'] );
    }

    public function test_soft_expiry_without_a_safe_dispatcher_regenerates_synchronously() {
        $this->seed( 'synchronous-soft-expiry' );
        $this->now = 1011;

        $result = $this->engine()->boot_early( $this->server(), [] );

        $this->assertFalse( $result['served'] );
        $this->assertTrue( $result['regeneration'] );
        $this->assertSame( 'refresh_sync', $result['code'] );
    }

    public function test_failed_stale_refresh_dispatch_releases_its_claim_and_regenerates_synchronously() {
        $this->seed( 'failed-dispatch' );
        $this->now = 1011;
        $engine    = $this->engine(
            [
                'config'             => [
                    'refresh_endpoint' => 'https://example.test/wp-admin/admin-ajax.php',
                    'refresh_token'    => 'trusted-token',
                ],
                'refresh_dispatcher' => '__return_false',
            ]
        );

        $result = $engine->boot_early( $this->server(), [] );

        $this->assertFalse( $result['served'] );
        $this->assertTrue( $result['regeneration'] );
        $this->assertSame( 'refresh_sync', $result['code'] );
        $engine->release_request();

        $storage = new Cache_Storage( $this->root, function () { return $this->now; } );
        $key     = ( new Request_Key() )->from_url( 'https://example.test/directory/' );

        $this->assertTrue( $storage->claim_refresh( $key, 300 )['success'] );
        $storage->release_refresh_claim( $key );
    }

    public function test_logged_in_soft_stale_reader_never_becomes_the_regenerator() {
        $this->seed( 'authenticated-stale-reader' );
        $this->now = 1011;

        $result = $this->engine()->boot_early( $this->server(), [ 'wordpress_logged_in_hash' => 'private' ] );

        $this->assertTrue( $result['served'] );
        $this->assertTrue( $result['stale'] );
        $this->assertFalse( $result['regeneration'] );
        $this->assertSame( 'refresh_due', $result['code'] );
    }

    public function test_route_policy_is_applied_when_a_response_is_stored() {
        $engine = $this->engine(
            [
                'config' => [
                    'refresh_policy' => [
                        'default' => [ 'soft_ttl' => 60, 'hard_ttl' => 120, 'jitter' => 0 ],
                        'routes'  => [ 'listings' => [ 'soft_ttl' => 300, 'hard_ttl' => 900, 'jitter' => 0 ] ],
                    ],
                ],
            ]
        );
        $engine->boot_early( $this->server(), [] );
        $engine->begin_capture();
        $engine->finalize_capture( $this->html( 'policy' ), 200, [ 'Content-Type: text/html' ] );

        $metadata = json_decode( file_get_contents( glob( $this->root . '/pages/*/*/*.json' )[0] ), true );

        $this->assertSame( 1300, $metadata['expires_at'] );
        $this->assertSame( 1900, $metadata['stale_until'] );
    }

    public function test_legacy_entry_without_lifecycle_generation_is_never_served() {
        $this->seed( 'legacy', 'https://example.test/directory/', false );
        $result = $this->engine()->boot_early( $this->server(), [] );

        $this->assertFalse( $result['served'] );
        $this->assertTrue( $result['regeneration'] );
        $this->assertSame( 'legacy_entry', $result['code'] );
    }

    public function test_dependency_generation_and_exact_url_invalidation_are_composed() {
        $this->seed( 'first', 'https://example.test/directory/' );
        $this->seed( 'second', 'https://example.test/location/dhaka/' );
        $engine = $this->engine();
        $result = $engine->invalidate(
            [
                'site_id'      => 1,
                'urls'         => [ 'https://example.test/directory/' ],
                'dependencies' => [ 'directorist:1:listing:91' ],
                'generations'  => [ 'directorist:1:collection:listings' ],
                'conservative' => false,
            ]
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['purged_urls'] );
        $this->assertSame( 3, $result['bumped_generations'] );
        $this->assertFalse( $this->engine()->boot_early( $this->server(), [] )['served'] );
        $this->assertFalse( $this->engine()->boot_early( $this->server( [ 'REQUEST_URI' => '/location/dhaka/' ] ), [] )['served'] );
    }

    public function test_url_invalidation_expires_every_cookie_variant_of_the_page() {
        foreach ( [ 'en', 'sv' ] as $language ) {
            $engine = $this->engine();
            $this->assertTrue( $engine->boot_early( $this->server(), [ 'wp-wpml_current_language' => $language ] )['regeneration'] );
            $this->assertTrue( $engine->begin_capture()['eligible'] );
            $this->assertTrue( $engine->finalize_capture( $this->html( $language ), 200, [ 'Content-Type: text/html' ] )['success'] );
        }

        $metadata_paths = glob( $this->root . '/pages/*/*/*.json' );
        $this->assertCount( 2, $metadata_paths );

        foreach ( $metadata_paths as $metadata_path ) {
            $metadata = json_decode( file_get_contents( $metadata_path ), true );
            $this->assertArrayHasKey( 'directorist:1:url:' . hash( 'sha256', 'https://example.test/directory/' ), $metadata['generations'] );
        }

        $result = $this->engine()->invalidate(
            [
                'site_id'      => 1,
                'urls'         => [ 'https://example.test/directory/' ],
                'dependencies' => [],
                'generations'  => [],
                'conservative' => false,
            ]
        );

        $this->assertTrue( $result['success'] );

        foreach ( [ 'en', 'sv' ] as $language ) {
            $miss = $this->engine()->boot_early( $this->server(), [ 'wp-wpml_current_language' => $language ] );
            $this->assertFalse( $miss['served'] );
            $this->assertTrue( $miss['regeneration'] );
            $this->engine()->release_request();
        }
    }

    public function test_entry_hash_invalidation_purges_only_the_selected_variant() {
        foreach ( [ 'en', 'sv' ] as $language ) {
            $engine = $this->engine();
            $engine->boot_early( $this->server(), [ 'wp-wpml_current_language' => $language ] );
            $engine->begin_capture();
            $engine->finalize_capture( $this->html( $language ), 200, [ 'Content-Type: text/html' ] );
        }

        $english_key = ( new Request_Key() )->from_server( $this->server(), [ 'wp-wpml_current_language' => 'en' ] );
        $result      = $this->engine()->invalidate(
            [
                'site_id'      => 1,
                'entry_hashes' => [ $english_key['hash'] ],
                'urls'         => [],
                'dependencies' => [],
                'generations'  => [],
                'conservative' => false,
            ]
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['purged_entries'] );
        $this->assertFalse( $this->engine()->boot_early( $this->server(), [ 'wp-wpml_current_language' => 'en' ] )['served'] );
        $this->engine()->release_request();
        $this->assertTrue( $this->engine()->boot_early( $this->server(), [ 'wp-wpml_current_language' => 'sv' ] )['served'] );
    }

    public function test_conservative_plan_bumps_the_site_generation() {
        $this->seed( 'site' );
        $result = $this->engine()->invalidate(
            [
                'site_id'      => 1,
                'urls'         => [],
                'dependencies' => [],
                'generations'  => [],
                'conservative' => true,
            ]
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['bumped_generations'] );
        $this->assertFalse( $this->engine()->boot_early( $this->server(), [] )['served'] );
    }

    public function test_lifecycle_generation_invalidates_entries_without_recursive_file_deletion() {
        $engine = $this->engine();
        $engine->boot_early( $this->server(), [] );
        $engine->begin_capture();
        $engine->finalize_capture( $this->html( 'lifecycle' ), 200, [ 'Content-Type: text/html' ] );

        $this->assertTrue( $this->engine()->boot_early( $this->server(), [] )['served'] );
        $this->assertTrue(
            $this->engine()->invalidate(
                [
                    'site_id'      => 1,
                    'urls'         => [],
                    'dependencies' => [ 'directorist:0:lifecycle' ],
                    'generations'  => [],
                    'conservative' => false,
                ]
            )['success']
        );
        $this->assertFalse( $this->engine()->boot_early( $this->server(), [] )['served'] );
    }

    public function test_debug_header_is_opt_in_and_warming_is_not_advertised_in_pc06() {
        $this->seed( 'debug' );
        $engine = $this->engine( [ 'config' => [ 'debug' => true ] ] );
        $hit    = $engine->boot_early( $this->server(), [] );

        $this->assertSame( 'HIT', $hit['headers']['X-Directorist-Cache'] );
        $this->assertFalse( $engine->supports_warm() );
        $this->assertSame( 'warming_unavailable', $engine->warm( [ 'https://example.test/directory/' ] )['code'] );
    }

    public function test_warming_is_advertised_only_after_a_handler_is_attached_and_failures_are_contained() {
        $engine = $this->engine();
        $calls  = [];

        $this->assertFalse( $engine->set_warm_handler( 'not-callable' ) );
        $this->assertTrue(
            $engine->set_warm_handler(
                static function ( array $urls ) use ( &$calls ) {
                    $calls[] = $urls;

                    return [ 'success' => true, 'code' => 'queued' ];
                }
            )
        );
        $this->assertTrue( $engine->supports_warm() );
        $this->assertTrue( $engine->get_status()['warm_ready'] );
        $this->assertSame( 'queued', $engine->warm( [ 'https://example.test/directory/' ] )['code'] );
        $this->assertSame( [ [ 'https://example.test/directory/' ] ], $calls );

        $throwing = $this->engine();
        $throwing->set_warm_handler(
            static function () {
                throw new RuntimeException( 'queue failed' );
            }
        );
        $this->assertSame( 'warming_exception', $throwing->warm( [] )['code'] );
    }

    private function engine( array $overrides = [] ) {
        $config = array_merge(
            [
                'cache_dir' => $this->root,
                'ttl'       => 10,
                'stale_ttl' => 30,
                'debug'     => false,
            ],
            isset( $overrides['config'] ) ? $overrides['config'] : []
        );
        $clock  = function () {
            return $this->now;
        };

        return new Cache_Engine(
            $config,
            [
                'clock'              => $clock,
                'refresh_dispatcher' => isset( $overrides['refresh_dispatcher'] ) ? $overrides['refresh_dispatcher'] : null,
                'core_begin'         => isset( $overrides['core_begin'] ) ? $overrides['core_begin'] : function () {
                    return $this->descriptor();
                },
                'core_finish'        => isset( $overrides['core_finish'] ) ? $overrides['core_finish'] : function () {
                    return $this->descriptor();
                },
            ]
        );
    }

    private function seed( $marker, $url = 'https://example.test/directory/', $lifecycle = true ) {
        $key     = ( new Request_Key() )->from_url( $url );
        $storage = new Cache_Storage(
            $this->root,
            function () {
                return $this->now;
            }
        );

        $descriptor = $this->descriptor();

        if ( $lifecycle ) {
            $descriptor['dependencies'][] = 'directorist:0:lifecycle';
        }

        $this->assertTrue(
            $storage->store(
                $key,
                $this->html( $marker ),
                $descriptor,
                [ 'content-type' => 'text/html; charset=UTF-8' ],
                10,
                30
            )['success']
        );
    }

    private function descriptor() {
        return [
            'eligible'     => true,
            'reason'       => 'eligible',
            'site_id'      => 1,
            'route_type'   => 'listings',
            'cache_key'    => 'directorist:page:v1:site:1:listings',
            'dependencies' => [
                'directorist:1:site',
                'directorist:1:collection:listings',
                'directorist:1:listing:91',
            ],
        ];
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

    private function html( $marker ) {
        return '<!doctype html><html><body>' . $marker . '</body></html>';
    }

    private function remove_tree( $path ) {
        if ( is_file( $path ) || is_link( $path ) ) {
            unlink( $path );

            return;
        }

        if ( ! is_dir( $path ) ) {
            return;
        }

        foreach ( array_diff( scandir( $path ), [ '.', '..' ] ) as $item ) {
            $this->remove_tree( $path . '/' . $item );
        }

        rmdir( $path );
    }
}
