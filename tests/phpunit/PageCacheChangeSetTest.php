<?php
/**
 * Behavior tests for request-local page-cache mutation coalescing.
 */

use Directorist\Cache\Change_Set;
use Directorist\Cache\Change_Type;

class Directorist_Page_Cache_Change_Set_Test extends WP_UnitTestCase {
    public function test_repeated_listing_changes_coalesce_and_preserve_first_before_and_latest_after_state() {
        $changes = new Change_Set( 1 );

        $this->assertTrue(
            $changes->record(
                Change_Type::LISTING,
                42,
                [
                    'before'  => [ 'url' => 'http://example.org/old-listing/' ],
                    'reasons' => [ 'post' ],
                ]
            )
        );
        $this->assertTrue(
            $changes->record(
                Change_Type::LISTING,
                42,
                [
                    'before'  => [ 'url' => 'http://example.org/ignored-before/' ],
                    'after'   => [ 'url' => 'http://example.org/new-listing/' ],
                    'reasons' => [ 'meta', 'post' ],
                ]
            )
        );

        $change = $changes->get( Change_Type::LISTING, 42 );

        $this->assertSame( 1, $changes->count() );
        $this->assertSame( 'http://example.org/old-listing/', $change['context']['before']['url'] );
        $this->assertSame( 'http://example.org/new-listing/', $change['context']['after']['url'] );
        $this->assertSame( [ 'meta', 'post' ], $change['context']['reasons'] );
    }

    public function test_list_context_values_are_deduplicated_and_sorted() {
        $changes = new Change_Set( 1 );

        $changes->record( Change_Type::TERM, 7, [ 'listing_ids' => [ 9, 3, 9 ] ] );
        $changes->record( Change_Type::TERM, 7, [ 'listing_ids' => [ 4, 3 ] ] );

        $change = $changes->get( Change_Type::TERM, 7 );

        $this->assertSame( [ 3, 4, 9 ], $change['context']['listing_ids'] );
    }

    public function test_invalid_change_types_and_identifiers_are_rejected() {
        $changes = new Change_Set( 1 );

        $this->assertFalse( $changes->record( 'unknown', 1 ) );
        $this->assertFalse( $changes->record( Change_Type::LISTING, 0 ) );
        $this->assertFalse( $changes->record( Change_Type::EXTENSION, '' ) );
        $this->assertTrue( $changes->is_empty() );
    }

    public function test_reset_starts_a_new_request_local_change_set() {
        $changes = new Change_Set( 7 );
        $changes->record( Change_Type::SETTINGS, 'global' );

        $this->assertSame( 7, $changes->get_site_id() );
        $this->assertFalse( $changes->is_empty() );

        $changes->reset();

        $this->assertTrue( $changes->is_empty() );
        $this->assertSame( [], $changes->all() );
    }
}
