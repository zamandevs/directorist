<?php
/**
 * Proactive route refresh coordinator behavior locks.
 */

use Directorist\Cache\Automatic_Refresh_Coordinator;
use Directorist\Cache\Cache_Provider;

final class Directorist_Page_Cache_Automatic_Refresh_Test_Provider implements Cache_Provider {
    public $calls = [];

    private $id;

    public function __construct( $id = 'directorist-cache' ) {
        $this->id = $id;
    }

    public function get_id() {
        return $this->id;
    }

    public function is_available() {
        return true;
    }

    public function get_capabilities() {
        return [ 'warm_urls' ];
    }

    public function supports( $capability ) {
        return 'warm_urls' === $capability;
    }

    public function invalidate( array $request ) {
        unset( $request );

        return [ 'success' => true, 'code' => 'invalidated' ];
    }

    public function warm( array $urls ) {
        $this->calls[] = $urls;

        return [ 'success' => true, 'code' => 'queued', 'queued' => count( $urls ), 'accepted_urls' => $urls ];
    }

    public function get_status() {
        return [ 'available' => true ];
    }
}

final class Directorist_Page_Cache_Automatic_Refresh_Coordinator_Test extends WP_UnitTestCase {
    public function test_builtin_provider_queues_one_bounded_due_batch_and_marks_accepted_urls() {
        $provider    = new Directorist_Page_Cache_Automatic_Refresh_Test_Provider();
        $marked      = [];
        $source      = new class( $marked ) {
            public $marked;

            public function __construct( &$marked ) {
                $this->marked =& $marked;
            }

            public function due_refresh_urls( $before, $limit, $claim_before ) {
                unset( $before, $claim_before );

                return array_slice( [ home_url( '/one/' ), home_url( '/two/' ), home_url( '/three/' ) ], 0, $limit );
            }

            public function mark_refresh_requested( array $urls, $time ) {
                $this->marked = compact( 'urls', 'time' );

                return true;
            }
        };
        $coordinator = new Automatic_Refresh_Coordinator( $provider, $source, static function () { return 1000; } );

        $result = $coordinator->run( 300, 2 );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'refresh_queued', $result['code'] );
        $this->assertTrue( $result['more_due'] );
        $this->assertSame( [ [ home_url( '/one/' ), home_url( '/two/' ) ] ], $provider->calls );
        $this->assertSame( [ home_url( '/one/' ), home_url( '/two/' ) ], $marked['urls'] );
        $this->assertSame( 1000, $marked['time'] );
    }

    public function test_external_provider_is_not_given_built_in_ttl_refresh_work() {
        $provider = new Directorist_Page_Cache_Automatic_Refresh_Test_Provider( 'wp-super-cache' );
        $source   = new class() {
            public function due_refresh_urls() {
                throw new RuntimeException( 'External providers must own expiry.' );
            }
        };

        $result = ( new Automatic_Refresh_Coordinator( $provider, $source ) )->run();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'provider_managed', $result['code'] );
        $this->assertSame( [], $provider->calls );
    }
}
