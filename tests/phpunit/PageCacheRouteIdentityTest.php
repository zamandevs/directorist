<?php
/**
 * Contract tests for Directorist page-cache route identity.
 */

use Directorist\Cache\Route_Identity;

class Directorist_Page_Cache_Route_Identity_Test extends WP_UnitTestCase {
    public function test_route_identity_is_stable_for_equivalent_input() {
        $first  = new Route_Identity(
            [
                'site_id'     => 1,
                'home_url'    => 'https://example.test/',
                'route_type'  => 'search',
                'object_id'   => 25,
                'page_id'     => 25,
                'page_number' => 2,
                'variation'   => [ 'in_cat' => '4', 'q' => 'coffee' ],
            ]
        );
        $second = new Route_Identity(
            [
                'site_id'     => 1,
                'home_url'    => 'https://example.test',
                'route_type'  => 'search',
                'object_id'   => 25,
                'page_id'     => 25,
                'page_number' => 2,
                'variation'   => [ 'q' => 'coffee', 'in_cat' => '4' ],
            ]
        );

        $this->assertSame( $first->get_cache_key(), $second->get_cache_key() );
        $this->assertSame( 'search', $first->get_route_type() );
        $this->assertSame( 2, $first->get_page_number() );
        $this->assertSame( 25, $first->get_page_id() );
    }

    public function test_multisite_and_home_url_are_part_of_the_identity() {
        $base = [
            'site_id'    => 1,
            'home_url'   => 'https://network.test/site-one/',
            'route_type' => 'listing',
            'object_id'  => 77,
        ];

        $site_one = new Route_Identity( $base );
        $site_two = new Route_Identity(
            array_merge(
                $base,
                [
                    'site_id'  => 2,
                    'home_url' => 'https://network.test/site-two/',
                ]
            )
        );

        $this->assertNotSame( $site_one->get_cache_key(), $site_two->get_cache_key() );
        $this->assertStringContainsString( 'site:1:', $site_one->get_cache_key() );
        $this->assertStringContainsString( 'site:2:', $site_two->get_cache_key() );
    }

    public function test_home_url_path_case_is_not_collapsed() {
        $upper = new Route_Identity(
            [
                'site_id'    => 1,
                'home_url'   => 'https://network.test/Travel/',
                'route_type' => 'listings',
                'object_id'  => 10,
            ]
        );
        $lower = new Route_Identity(
            [
                'site_id'    => 1,
                'home_url'   => 'https://network.test/travel/',
                'route_type' => 'listings',
                'object_id'  => 10,
            ]
        );

        $this->assertNotSame( $upper->get_cache_key(), $lower->get_cache_key() );
    }

    public function test_entity_context_is_normalized_and_deduplicated() {
        $identity = new Route_Identity(
            [
                'route_type' => 'listing',
                'object_id'  => 8,
                'entities'   => [
                    'directory' => [ 5, '5', 9 ],
                    'term'      => [ 20, 20, 21 ],
                    'author'    => [ 3 ],
                ],
            ]
        );

        $this->assertSame( [ 5, 9 ], $identity->get_entity_ids( 'directory' ) );
        $this->assertSame( [ 20, 21 ], $identity->get_entity_ids( 'term' ) );
        $this->assertSame( [ 3 ], $identity->get_entity_ids( 'author' ) );
    }
}
