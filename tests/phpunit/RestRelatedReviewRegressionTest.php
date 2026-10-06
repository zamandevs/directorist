<?php

use Directorist\Cache\Built_In\Cache_Engine;

require_once __DIR__ . '/fixtures/PerformanceReviewFixture.php';

/**
 * Cache bypass constants and request-static hook guards cannot be reset in PHP.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Directorist_Performance_Rest_Review_Regression_Test extends Directorist_Performance_Review_Fixture {
    public function test_related_taxonomy_normalization_respects_explicit_no_children() {
        if ( ! class_exists( '\\Directorist\\Rest_Api\\Controllers\\Version1\\Listings_Controller' ) ) {
            require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/rest-api/Version1/class-abstract-controller.php';
            require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/rest-api/Version1/class-abstract-posts-controller.php';
            require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/rest-api/Version1/class-listings-controller.php';
        }
        $class = '\\Directorist\\Rest_Api\\Controllers\\Version1\\Listings_Controller';
        $controller = new $class();
        $method = new ReflectionMethod( $class, 'normalize_related_tax_query_terms' );
        $method->setAccessible( true );
        $parent = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY ] );
        $child = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY, 'parent' => $parent ] );
        $args = [ 'tax_query' => [ [ 'taxonomy' => ATBDP_CATEGORY, 'field' => 'term_id', 'terms' => [ $parent ], 'include_children' => false ] ] ];
        $normalized = $method->invoke( $controller, $args );
        $this->assertSame( [ (int) get_term( $parent, ATBDP_CATEGORY )->term_taxonomy_id ], $normalized['tax_query'][0]['terms'], 'Explicit include_children=false must not add child term ' . $child );
    }

    public static function related_descendant_cases() {
        return [ [ null, 2 ], [ true, 2 ], [ false, 1 ], [ 0, 1 ] ];
    }

    /** @dataProvider related_descendant_cases */
    public function test_related_taxonomy_results_match_wordpress_descendant_semantics( $include_children, $expected_count ) {
        require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/rest-api/Version1/class-abstract-controller.php';
        require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/rest-api/Version1/class-abstract-posts-controller.php';
        require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/rest-api/Version1/class-listings-controller.php';
        $controller = new \Directorist\Rest_Api\Controllers\Version1\Listings_Controller();
        $parent = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY ] );
        $child = self::factory()->term->create( [ 'taxonomy' => ATBDP_CATEGORY, 'parent' => $parent ] );
        foreach ( [ $parent, $child ] as $term ) {
            $post = self::factory()->post->create( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish' ] );
            wp_set_object_terms( $post, [ $term ], ATBDP_CATEGORY );
        }
        $clause = [ 'taxonomy' => ATBDP_CATEGORY, 'field' => 'term_id', 'terms' => [ $parent ] ];
        if ( null !== $include_children ) { $clause['include_children'] = $include_children; }
        $args = [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish', 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'tax_query' => [ $clause ] ];
        $method = new ReflectionMethod( $controller, 'normalize_related_tax_query_terms' );
        $method->setAccessible( true );
        $canonical = new WP_Query( $args );
        $normalized = new WP_Query( $method->invoke( $controller, $args ) );
        $this->assertCount( $expected_count, $canonical->posts );
        $this->assertSame( $canonical->posts, $normalized->posts );
    }
}
