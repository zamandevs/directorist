<?php
/**
 * Behavior locks for author listing statistics.
 */

use Directorist\Directorist_Listing_Author;
use Directorist\database\Listing_Index;
use Directorist\database\Listing_Index_Schema;

class Directorist_Author_Statistics_Behavior_Test extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();

        add_filter( 'directorist_use_listing_index', '__return_false', 999 );
        $this->reset_author_instance();
    }

    public function tear_down() {
        remove_filter( 'directorist_use_listing_index', '__return_false', 999 );
        $this->reset_author_instance();

        parent::tear_down();
    }

    public function test_author_without_listings_has_zero_statistics() {
        $author = $this->author_for( self::factory()->user->create() );

        $this->assertSame( 0, $author->get_rating() );
        $this->assertSame( 0, $author->get_review_count() );
        $this->assertSame( '<span>0</span> Listings', $author->listing_count_html() );
    }

    public function test_author_statistics_count_only_positive_rounded_listing_ratings() {
        $author_id = self::factory()->user->create();

        $this->create_listing( $author_id, '4.64' );
        $this->create_listing( $author_id, '4.65' );
        $this->create_listing( $author_id, '0.04' );
        $this->create_listing( $author_id, '' );

        $author = $this->author_for( $author_id );

        $this->assertSame( '4.7', $author->get_rating() );
        $this->assertSame( 2, $author->get_review_count() );
        $this->assertSame( '<span>4</span> Listings', $author->listing_count_html() );
    }

    public function test_non_published_listings_are_excluded_from_all_statistics() {
        $author_id = self::factory()->user->create();

        $this->create_listing( $author_id, '4.5' );
        $this->create_listing( $author_id, '5', 'pending' );

        $author = $this->author_for( $author_id );

        $this->assertSame( '4.5', $author->get_rating() );
        $this->assertSame( 1, $author->get_review_count() );
        $this->assertSame( '<span>1</span> Listing', $author->listing_count_html() );
    }

    public function test_shared_api_uses_one_memoized_index_aggregate() {
        global $wpdb;

        remove_filter( 'directorist_use_listing_index', '__return_false', 999 );
        Listing_Index_Schema::create();
        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_READY );
        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, Listing_Index_Schema::DATA_VERSION, false );
        Listing_Index::set_enabled( true );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::listing_table() );

        $author_id = self::factory()->user->create();
        $published = $this->create_listing( $author_id, '4.64' );
        $rated     = $this->create_listing( $author_id, '4.65' );
        $unrated   = $this->create_listing( $author_id, '' );
        $pending   = $this->create_listing( $author_id, '5', 'pending' );

        Listing_Index::sync_listing( $published );
        Listing_Index::sync_listing( $rated );
        Listing_Index::sync_listing( $unrated );
        Listing_Index::sync_listing( $pending );

        $aggregate_queries = 0;
        $count_aggregate   = static function( $sql ) use ( &$aggregate_queries ) {
            if ( false !== strpos( $sql, Listing_Index_Schema::listing_table() ) && false !== strpos( $sql, 'rated_listing_count' ) ) {
                ++$aggregate_queries;
            }

            return $sql;
        };

        add_filter( 'query', $count_aggregate );
        $first  = directorist_get_author_listing_statistics( $author_id );
        $second = directorist_get_author_listing_statistics( $author_id );
        remove_filter( 'query', $count_aggregate );
        add_filter( 'directorist_use_listing_index', '__return_false', 999 );

        $this->assertSame( $first, $second );
        $this->assertSame( 3, $first['listing_count'] );
        $this->assertSame( 2, $first['rated_listing_count'] );
        $this->assertEquals( 4.65, $first['average_rating'] );
        $this->assertSame( 1, $aggregate_queries );
    }

    public function test_shared_api_falls_back_to_canonical_posts_when_index_is_disabled() {
        $author_id = self::factory()->user->create();

        $this->create_listing( $author_id, '3.24' );
        $this->create_listing( $author_id, '4.75' );
        $this->create_listing( $author_id, '' );

        $statistics = directorist_get_author_listing_statistics( $author_id );

        $this->assertSame( 3, $statistics['listing_count'] );
        $this->assertSame( 2, $statistics['rated_listing_count'] );
        $this->assertEquals( 4.0, $statistics['average_rating'] );
        $this->assertSame( 'canonical', $statistics['source'] );
    }

    private function author_for( $author_id ) {
        set_query_var( 'author_id', $author_id );
        $this->reset_author_instance();

        return Directorist_Listing_Author::instance();
    }

    private function create_listing( $author_id, $rating, $status = 'publish' ) {
        $listing_id = self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => $status,
                'post_author' => $author_id,
            ]
        );

        if ( '' !== $rating ) {
            update_post_meta( $listing_id, directorist_get_rating_field_meta_key(), $rating );
        }

        return $listing_id;
    }

    private function reset_author_instance() {
        $property = new ReflectionProperty( Directorist_Listing_Author::class, 'instance' );
        $property->setAccessible( true );
        $property->setValue( null, null );
    }
}
