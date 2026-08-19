<?php
/**
 * Behavior tests for deterministic page-cache invalidation planning.
 */

use Directorist\Cache\Change_Set;
use Directorist\Cache\Change_Type;
use Directorist\Cache\Invalidation_Planner;

class Directorist_Page_Cache_Invalidation_Planner_Test extends WP_UnitTestCase {
    public function test_listing_plan_contains_old_and_new_exact_entities_and_collection_generation() {
        $changes = new Change_Set( 1 );
        $changes->record(
            Change_Type::LISTING,
            42,
            [
                'before'     => [
                    'url'           => home_url( '/old-listing/' ),
                    'author_id'     => 3,
                    'term_ids'      => [ 7 ],
                    'directory_ids' => [ 11 ],
                ],
                'after'      => [
                    'url'           => home_url( '/new-listing/' ),
                    'author_id'     => 4,
                    'term_ids'      => [ 8 ],
                    'directory_ids' => [ 12 ],
                ],
                'collection' => true,
            ]
        );

        $plan = ( new Invalidation_Planner() )->build( $changes )->to_array();

        $this->assertSame(
            [ home_url( '/new-listing/' ), home_url( '/old-listing/' ) ],
            $plan['urls']
        );
        $this->assertSame(
            [
                'directorist:1:author:3',
                'directorist:1:author:4',
                'directorist:1:directory:11',
                'directorist:1:directory:12',
                'directorist:1:listing:42',
                'directorist:1:term:7',
                'directorist:1:term:8',
            ],
            $plan['dependencies']
        );
        $this->assertSame( [ 'directorist:1:collection:listings' ], $plan['generations'] );
    }

    public function test_term_and_author_changes_include_exact_urls_and_broad_collection_generation() {
        $changes = new Change_Set( 1 );
        $changes->record(
            Change_Type::TERM,
            7,
            [
                'taxonomy'    => ATBDP_CATEGORY,
                'before_url'  => home_url( '/categories/old/' ),
                'after_url'   => home_url( '/categories/new/' ),
                'listing_ids' => [ 42 ],
                'collection'  => true,
            ]
        );
        $changes->record(
            Change_Type::AUTHOR,
            3,
            [ 'urls' => [ home_url( '/author/example/' ) ] ]
        );

        $plan = ( new Invalidation_Planner() )->build( $changes )->to_array();

        $this->assertSame(
            [ home_url( '/author/example/' ), home_url( '/categories/new/' ), home_url( '/categories/old/' ) ],
            $plan['urls']
        );
        $this->assertContains( 'directorist:1:author:3', $plan['dependencies'] );
        $this->assertContains( 'directorist:1:listing:42', $plan['dependencies'] );
        $this->assertContains( 'directorist:1:term:7', $plan['dependencies'] );
        $this->assertContains( 'directorist:1:taxonomy:at_biz_dir-category', $plan['generations'] );
        $this->assertContains( 'directorist:1:collection:listings', $plan['generations'] );
    }

    public function test_settings_and_directory_changes_use_conservative_generations() {
        $changes = new Change_Set( 2 );
        $changes->record( Change_Type::SETTINGS, 'global', [ 'urls' => [ home_url( '/directory/' ) ] ] );
        $changes->record( Change_Type::DIRECTORY, 9, [ 'collection' => true ] );

        $plan = ( new Invalidation_Planner() )->build( $changes )->to_array();

        $this->assertSame( [ 'directorist:2:directory:9' ], $plan['dependencies'] );
        $this->assertSame(
            [
                'directorist:2:collection:listings',
                'directorist:2:settings',
                'directorist:2:template',
            ],
            $plan['generations']
        );
    }

    public function test_exact_entity_overflow_falls_back_to_site_generation() {
        $changes = new Change_Set( 1 );

        for ( $listing_id = 1; $listing_id <= 101; $listing_id++ ) {
            $changes->record(
                Change_Type::LISTING,
                $listing_id,
                [ 'after' => [ 'url' => home_url( '/listing-' . $listing_id . '/' ) ] ]
            );
        }

        $plan = ( new Invalidation_Planner( 100 ) )->build( $changes )->to_array();

        $this->assertSame( [], $plan['urls'] );
        $this->assertSame( [], $plan['dependencies'] );
        $this->assertSame( [ 'directorist:1:site' ], $plan['generations'] );
        $this->assertTrue( $plan['conservative'] );
        $this->assertSame( 'exact_limit_exceeded', $plan['reason'] );
    }

    public function test_extension_plan_accepts_only_same_site_public_urls() {
        $home_parts       = wp_parse_url( home_url( '/' ) );
        $alternate_port   = isset( $home_parts['port'] ) && 8443 === (int) $home_parts['port'] ? 9443 : 8443;
        $different_port   = $home_parts['scheme'] . '://' . $home_parts['host'] . ':' . $alternate_port . '/cache/';
        $different_scheme = ( 'https' === $home_parts['scheme'] ? 'http' : 'https' ) . '://' . $home_parts['host'] . '/cache/';
        $changes          = new Change_Set( 1 );
        $changes->record(
            Change_Type::EXTENSION,
            'booking:availability',
            [
                'urls'         => [
                    home_url( '/listing/bookable/' ),
                    home_url( '/listing/bookable/#fragment' ),
                    $different_port,
                    $different_scheme,
                    'https://evil.example/cache/',
                ],
                'listing_ids'  => [ 15 ],
                'dependencies' => [ [ 'domain' => 'extension', 'identifier' => 'booking:calendar:8' ] ],
                'collection'   => true,
            ]
        );

        $plan = ( new Invalidation_Planner() )->build( $changes )->to_array();

        $this->assertSame( [ home_url( '/listing/bookable/' ) ], $plan['urls'] );
        $this->assertContains( 'directorist:1:extension:booking:availability', $plan['dependencies'] );
        $this->assertContains( 'directorist:1:extension:booking:calendar:8', $plan['dependencies'] );
        $this->assertContains( 'directorist:1:listing:15', $plan['dependencies'] );
    }

    public function test_one_extension_change_with_unbounded_exact_entities_falls_back_to_site_generation() {
        $changes = new Change_Set( 1 );
        $changes->record(
            Change_Type::EXTENSION,
            'booking:bulk',
            [ 'listing_ids' => range( 1, 101 ) ]
        );

        $plan = ( new Invalidation_Planner( 100 ) )->build( $changes )->to_array();

        $this->assertSame( [], $plan['dependencies'] );
        $this->assertSame( [ 'directorist:1:site' ], $plan['generations'] );
        $this->assertTrue( $plan['conservative'] );
    }
}
