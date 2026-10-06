<?php

use Directorist\Cache\Built_In\Cache_Engine;

require_once __DIR__ . '/fixtures/PerformanceReviewFixture.php';

/**
 * Cache bypass constants and request-static hook guards cannot be reset in PHP.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Directorist_Performance_Author_Review_Regression_Test extends Directorist_Performance_Review_Fixture {
    public function test_author_reassignment_invalidates_both_authors_in_request_cache() {
        $class = '\\Directorist\\database\\Listing_Author_Statistics';
        $class::reset_request_cache();
        add_filter( 'directorist_use_author_statistics_index', '__return_false', 999 );
        try {
            $old_author = self::factory()->user->create();
            $new_author = self::factory()->user->create();
            $listing = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'post_author' => $old_author ] );
            $this->assertSame( 1, $class::get( $old_author )['listing_count'] );
            wp_update_post( [ 'ID' => $listing, 'post_author' => $new_author ] );
            $this->assertSame( 1, $class::get( $new_author )['listing_count'] );
            $this->assertSame( 0, $class::get( $old_author )['listing_count'], 'The old author still has a stale request-cache entry.' );
        } finally {
            remove_filter( 'directorist_use_author_statistics_index', '__return_false', 999 );
            $class::reset_request_cache();
        }
    }
}
