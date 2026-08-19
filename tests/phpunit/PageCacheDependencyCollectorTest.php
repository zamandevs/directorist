<?php
/**
 * Contract tests for request-local page-cache dependencies and integration APIs.
 */

use Directorist\Cache\Cache_Manager;
use Directorist\Cache\Dependency_Collector;
use Directorist\Cache\Route_Identity;

class Directorist_Page_Cache_Dependency_Collector_Test extends WP_UnitTestCase {
    public function tear_down() {
        Cache_Manager::instance()->end_request();
        parent::tear_down();
    }

    public function test_route_collection_adds_broad_and_exact_dependencies_once() {
        $collector = new Dependency_Collector( 7 );
        $identity  = new Route_Identity(
            [
                'site_id'    => 7,
                'route_type' => 'listing',
                'object_id'  => 42,
                'entities'   => [
                    'directory' => [ 3, 3 ],
                    'term'      => [ 8, 9, 8 ],
                    'author'    => [ 5 ],
                ],
            ]
        );

        $collector->collect_route( $identity );
        $collector->collect_route( $identity );

        $this->assertSame(
            [
                'directorist:7:author:5',
                'directorist:7:directory:3',
                'directorist:7:listing:42',
                'directorist:7:route:listing',
                'directorist:7:settings',
                'directorist:7:site',
                'directorist:7:template',
                'directorist:7:term:8',
                'directorist:7:term:9',
            ],
            $collector->all()
        );
    }

    public function test_collection_route_gets_collection_generation_dependency() {
        $collector = new Dependency_Collector( 1 );
        $collector->collect_route( new Route_Identity( [ 'route_type' => 'search', 'object_id' => 20, 'page_id' => 20 ] ) );

        $this->assertContains( 'directorist:1:collection:listings', $collector->all() );
        $this->assertContains( 'directorist:1:page:20', $collector->all() );
    }

    public function test_extension_dependencies_are_scoped_and_deduplicated() {
        $collector = new Dependency_Collector( 2 );

        $this->assertTrue( $collector->add_extension( 'booking', 'calendar-99' ) );
        $this->assertTrue( $collector->add_extension( 'booking', 'calendar-99' ) );
        $this->assertFalse( $collector->add_extension( '', 'calendar-99' ) );
        $this->assertSame( [ 'directorist:2:extension:booking:calendar-99' ], $collector->all() );
    }

    public function test_public_dependency_api_is_no_op_until_collection_begins() {
        $manager = Cache_Manager::instance();
        $manager->end_request();

        $this->assertFalse( directorist_page_cache_add_dependency( 'extension', 'booking:calendar-1' ) );

        $identity = new Route_Identity( [ 'site_id' => 1, 'route_type' => 'listing', 'object_id' => 15 ] );
        $manager->begin_request( $identity );

        $this->assertTrue( directorist_page_cache_add_dependency( 'extension', 'booking:calendar-1' ) );
        $this->assertContains( 'directorist:1:extension:booking:calendar-1', $manager->get_dependencies() );
    }

    public function test_listing_helper_uses_supplied_hydrated_entities() {
        $manager  = Cache_Manager::instance();
        $identity = new Route_Identity( [ 'site_id' => 1, 'route_type' => 'listings', 'object_id' => 20 ] );
        $manager->begin_request( $identity );

        $this->assertTrue(
            directorist_page_cache_add_listing_dependencies(
                15,
                4,
                [ 2 ],
                [ 8, 9 ]
            )
        );
        $this->assertContains( 'directorist:1:listing:15', $manager->get_dependencies() );
        $this->assertContains( 'directorist:1:author:4', $manager->get_dependencies() );
        $this->assertContains( 'directorist:1:directory:2', $manager->get_dependencies() );
        $this->assertContains( 'directorist:1:term:8', $manager->get_dependencies() );
        $this->assertContains( 'directorist:1:term:9', $manager->get_dependencies() );
    }

    public function test_public_private_veto_api_records_the_first_reason_only() {
        $manager = Cache_Manager::instance();
        $manager->end_request();

        $this->assertTrue( directorist_page_cache_mark_private( 'personalized_booking_state' ) );
        $this->assertTrue( directorist_page_cache_mark_private( 'later_reason' ) );
        $this->assertTrue( $manager->is_private() );
        $this->assertSame( 'personalized_booking_state', $manager->get_private_reason() );
    }

    public function test_request_end_clears_dependencies_and_private_state() {
        $manager  = Cache_Manager::instance();
        $identity = new Route_Identity( [ 'site_id' => 1, 'route_type' => 'listing', 'object_id' => 15 ] );

        $manager->begin_request( $identity );
        directorist_page_cache_add_dependency( 'term', 8 );
        directorist_page_cache_mark_private( 'dynamic_output' );
        $manager->end_request();

        $this->assertSame( [], $manager->get_dependencies() );
        $this->assertFalse( $manager->is_private() );
        $this->assertSame( '', $manager->get_private_reason() );
    }
}
