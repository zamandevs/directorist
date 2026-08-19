<?php
/**
 * Behavior tests for page-cache invalidation dispatch boundaries.
 */

use Directorist\Cache\Cache_Provider;
use Directorist\Cache\Change_Set;
use Directorist\Cache\Change_Type;
use Directorist\Cache\Invalidation_Dispatcher;
use Directorist\Cache\Invalidation_Planner;

class Directorist_Page_Cache_Test_Provider implements Cache_Provider {
    public $requests = [];

    public $available = true;

    public $result;

    public $throw = false;

    public function get_id() {
        return 'test';
    }

    public function is_available() {
        return $this->available;
    }

    public function get_capabilities() {
        return [ 'invalidate' ];
    }

    public function supports( $capability ) {
        return 'invalidate' === $capability;
    }

    public function invalidate( array $request ) {
        if ( $this->throw ) {
            throw new RuntimeException( 'Provider failure' );
        }

        $this->requests[] = $request;

        return null !== $this->result ? $this->result : [ 'success' => true, 'code' => 'invalidated' ];
    }

    public function warm( array $urls ) {
        unset( $urls );

        return [ 'success' => true ];
    }

    public function get_status() {
        return [ 'id' => 'test', 'available' => true ];
    }
}

class Directorist_Page_Cache_Invalidation_Dispatcher_Test extends WP_UnitTestCase {
    public function test_no_provider_call_occurs_before_explicit_safe_boundary_dispatch() {
        $changes  = new Change_Set( 1 );
        $provider = new Directorist_Page_Cache_Test_Provider();
        $dispatch = new Invalidation_Dispatcher( $changes, new Invalidation_Planner(), $provider );

        $changes->record( Change_Type::LISTING, 15, [ 'collection' => true ] );
        $changes->record( Change_Type::LISTING, 15, [ 'reasons' => [ 'meta' ] ] );

        $this->assertSame( [], $provider->requests );

        $result = $dispatch->dispatch();

        $this->assertTrue( $result['success'] );
        $this->assertCount( 1, $provider->requests );
        $this->assertContains( 'directorist:1:listing:15', $provider->requests[0]['dependencies'] );
        $this->assertTrue( $changes->is_empty() );
        $this->assertSame( [ 'success' => true, 'code' => 'no_changes' ], $dispatch->dispatch() );
        $this->assertCount( 1, $provider->requests );
    }

    public function test_empty_change_set_does_not_call_provider() {
        $changes  = new Change_Set( 1 );
        $provider = new Directorist_Page_Cache_Test_Provider();
        $dispatch = new Invalidation_Dispatcher( $changes, new Invalidation_Planner(), $provider );

        $this->assertSame( [ 'success' => true, 'code' => 'no_changes' ], $dispatch->dispatch() );
        $this->assertSame( [], $provider->requests );
    }

    public function test_provider_exception_is_reported_without_escaping_the_mutation_request() {
        $changes         = new Change_Set( 1 );
        $provider        = new Directorist_Page_Cache_Test_Provider();
        $provider->throw = true;
        $reported        = [];
        $callback        = static function ( $result ) use ( &$reported ) {
            $reported[] = $result;
        };

        add_action( 'directorist_page_cache_invalidation_failed', $callback );
        $changes->record( Change_Type::SETTINGS, 'global' );

        $result = ( new Invalidation_Dispatcher( $changes, new Invalidation_Planner(), $provider ) )->dispatch();

        remove_action( 'directorist_page_cache_invalidation_failed', $callback );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'provider_exception', $result['code'] );
        $this->assertSame( 'test', $result['provider'] );
        $this->assertCount( 1, $reported );
        $this->assertTrue( $changes->is_empty() );
    }

    public function test_malformed_provider_result_becomes_a_reported_failure() {
        $changes          = new Change_Set( 1 );
        $provider         = new Directorist_Page_Cache_Test_Provider();
        $provider->result = 'not-an-array';
        $reported         = [];
        $callback         = static function ( $result ) use ( &$reported ) {
            $reported[] = $result;
        };

        add_action( 'directorist_page_cache_invalidation_failed', $callback );
        $changes->record( Change_Type::SETTINGS, 'global' );
        $result = ( new Invalidation_Dispatcher( $changes, new Invalidation_Planner(), $provider ) )->dispatch();
        remove_action( 'directorist_page_cache_invalidation_failed', $callback );

        $this->assertSame( 'invalid_provider_result', $result['code'] );
        $this->assertSame( 'test', $result['provider'] );
        $this->assertCount( 1, $reported );
    }

    public function test_explicit_provider_failure_is_reported_without_rewriting_its_result() {
        $changes          = new Change_Set( 1 );
        $provider         = new Directorist_Page_Cache_Test_Provider();
        $provider->result = [ 'success' => false, 'code' => 'provider_rejected' ];
        $reported         = [];
        $callback         = static function ( $result ) use ( &$reported ) {
            $reported[] = $result;
        };

        add_action( 'directorist_page_cache_invalidation_failed', $callback );
        $changes->record( Change_Type::SETTINGS, 'global' );
        $result = ( new Invalidation_Dispatcher( $changes, new Invalidation_Planner(), $provider ) )->dispatch();
        remove_action( 'directorist_page_cache_invalidation_failed', $callback );

        $this->assertSame( $provider->result, $result );
        $this->assertCount( 1, $reported );
    }
}
