<?php

use Directorist\Cache\Built_In\Cache_Engine;

require_once __DIR__ . '/fixtures/PerformanceReviewFixture.php';

/**
 * Cache bypass constants and request-static hook guards cannot be reset in PHP.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Directorist_Performance_Collection_Review_Regression_Test extends Directorist_Performance_Review_Fixture {
    public function test_search_taxonomy_prefilter_preserves_existing_include_restriction() {
        $directory = self::factory()->term->create( [ 'taxonomy' => ATBDP_DIRECTORY_TYPE ] );
        $allowed = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'Allowed review category' ] );
        $excluded = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'Excluded review category' ] );
        update_term_meta( $allowed, '_directory_type', [ $directory ] );
        update_term_meta( $excluded, '_directory_type', [ $directory ] );
        $filter = static function ( $args ) use ( $allowed ) { $args['include'] = [ $allowed ]; return $args; };
        add_filter( 'atbdp_search_listing_category_argument', $filter );
        try {
            $html = search_category_location_filter( [
                'immediate_category' => false, 'term_id' => 0, 'parent' => 0, 'ancestors' => [],
                'orderby' => 'id', 'order' => 'ASC', 'hide_empty' => false,
                'show_count' => false, 'listing_type' => $directory,
            ], ATBDP_CATEGORY );
            $this->assertStringContainsString( 'Allowed review category', $html );
            $this->assertStringNotContainsString( 'Excluded review category', $html, 'Directory prefilter replaced the existing include restriction.' );
        } finally {
            remove_filter( 'atbdp_search_listing_category_argument', $filter );
        }
    }

    public static function search_include_cases() {
        return [ [ ATBDP_CATEGORY, true ], [ ATBDP_CATEGORY, false ], [ ATBDP_LOCATION, true ], [ ATBDP_LOCATION, false ] ];
    }

    /** @dataProvider search_include_cases */
    public function test_directory_dropdown_include_intersection_is_never_broadened( $taxonomy, $match ) {
        $directory = self::factory()->term->create( [ 'taxonomy' => ATBDP_DIRECTORY_TYPE ] );
        $allowed = self::factory()->term->create( [ 'taxonomy' => $taxonomy, 'name' => 'Allowed intersection term' ] );
        $other = self::factory()->term->create( [ 'taxonomy' => $taxonomy, 'name' => 'Other directory term' ] );
        update_term_meta( $allowed, '_directory_type', [ $directory ] );
        update_term_meta( $other, '_directory_type', [ $directory + 1000 ] );
        $filter = static function ( $args ) use ( $match, $allowed, $other ) { $args['include'] = (string) ( $match ? $allowed : $other ); return $args; };
        $hook = ATBDP_CATEGORY === $taxonomy ? 'atbdp_search_listing_category_argument' : 'atbdp_search_listing_location_argument';
        add_filter( $hook, $filter );
        try {
            $html = search_category_location_filter( [ 'immediate_category' => false, 'term_id' => 0, 'parent' => 0, 'ancestors' => [], 'orderby' => 'id', 'order' => 'ASC', 'hide_empty' => false, 'show_count' => false, 'listing_type' => $directory ], $taxonomy );
            if ( $match ) { $this->assertStringContainsString( 'Allowed intersection term', $html ); }
            else { $this->assertSame( '', $html ); }
            $this->assertStringNotContainsString( 'Other directory term', $html );
        } finally { remove_filter( $hook, $filter ); }
    }
}
