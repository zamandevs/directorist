<?php
/**
 * Bounded author listing statistics with a canonical WordPress fallback.
 *
 * @since 8.9.4
 */

namespace Directorist\database;

defined( 'ABSPATH' ) || exit;

class Listing_Author_Statistics {
    private static $cache = [];

    private static $hooks_registered = false;

    public static function get( $author_id ) {
        $author_id = absint( $author_id );
        self::register_invalidation_hooks();

        if ( ! $author_id ) {
            return self::normalize( [], 'canonical' );
        }

        $cache_key = get_current_blog_id() . ':' . $author_id;

        if ( isset( self::$cache[ $cache_key ] ) ) {
            return self::$cache[ $cache_key ];
        }

        $use_index = self::can_use_index( $author_id );
        $result    = $use_index ? self::from_index( $author_id ) : null;

        if ( null === $result ) {
            $result = self::from_canonical_posts( $author_id );
        }

        $result = apply_filters( 'directorist_author_listing_statistics', $result, $author_id );
        $result = self::normalize( $result, $result['source'] ?? ( $use_index ? 'index' : 'canonical' ) );

        self::$cache[ $cache_key ] = $result;

        return $result;
    }

    public static function invalidate_author( $author_id ) {
        unset( self::$cache[ get_current_blog_id() . ':' . absint( $author_id ) ] );
    }

    public static function invalidate_listing( $post_id, $post = null ) {
        $post = $post instanceof \WP_Post ? $post : get_post( $post_id );

        if ( $post && ATBDP_POST_TYPE === $post->post_type ) {
            self::invalidate_author( $post->post_author );
        }
    }

    public static function invalidate_rating( $meta_id, $object_id, $meta_key ) {
        unset( $meta_id );

        $rating_key = function_exists( 'directorist_get_rating_field_meta_key' )
            ? directorist_get_rating_field_meta_key()
            : '_directorist_listing_rating';

        if ( $rating_key === $meta_key ) {
            self::invalidate_listing( $object_id );
        }
    }

    public static function reset_request_cache() {
        self::$cache = [];
    }

    private static function can_use_index( $author_id ) {
        $context = [
            'directorist_query_purpose' => 'author_listing_statistics',
            'author'                    => $author_id,
        ];

        $multilingual_query = has_filter( 'wpml_current_language' ) || function_exists( 'pll_current_language' );
        $use_index          = ! $multilingual_query && Listing_Index::is_enabled( $context );

        return (bool) apply_filters( 'directorist_use_author_statistics_index', $use_index, $author_id, $context );
    }

    private static function from_index( $author_id ) {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        // Match the canonical path, which rounds each listing before averaging.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS listing_count,
                    COALESCE(SUM(CASE WHEN rating_set = 1 AND ROUND(rating, 1) > 0 THEN 1 ELSE 0 END), 0) AS rated_listing_count,
                    COALESCE(AVG(CASE WHEN rating_set = 1 AND ROUND(rating, 1) > 0 THEN ROUND(rating, 1) END), 0) AS average_rating
                FROM ' . Listing_Index_Schema::listing_table() . '
                WHERE author_id = %d AND post_status = %s',
                $author_id,
                'publish'
            ),
            ARRAY_A
        );
        $error = $wpdb->last_error;
        $wpdb->suppress_errors( $suppress );

        if ( ! is_array( $row ) || $error ) {
            Listing_Index_Schema::reset_request_cache();
            return null;
        }

        return self::normalize( $row, 'index' );
    }

    private static function from_canonical_posts( $author_id ) {
        $listings = DB::get_listings_data(
            [
                'post_type'      => ATBDP_POST_TYPE,
                'post_status'    => 'publish',
                'author'         => $author_id,
                'orderby'        => 'post_date',
                'order'          => 'ASC',
                'posts_per_page' => -1,
            ]
        );

        $rated_listing_count = 0;
        $rating_sum          = 0.0;

        if ( ! empty( $listings->ids ) ) {
            update_meta_cache( 'post', $listings->ids );

            foreach ( $listings->ids as $listing_id ) {
                $rating = (float) directorist_get_listing_rating( $listing_id );

                if ( $rating <= 0 ) {
                    continue;
                }

                $rating_sum += $rating;
                ++$rated_listing_count;
            }
        }

        return self::normalize(
            [
                'listing_count'       => isset( $listings->total ) ? $listings->total : count( $listings->ids ),
                'rated_listing_count' => $rated_listing_count,
                'average_rating'      => $rated_listing_count ? $rating_sum / $rated_listing_count : 0,
            ],
            'canonical'
        );
    }

    private static function normalize( $result, $source ) {
        $result = is_array( $result ) ? $result : [];

        return [
            'listing_count'       => absint( $result['listing_count'] ?? 0 ),
            'rated_listing_count' => absint( $result['rated_listing_count'] ?? 0 ),
            'average_rating'      => (float) ( $result['average_rating'] ?? 0 ),
            'source'              => 'index' === $source ? 'index' : 'canonical',
        ];
    }

    private static function register_invalidation_hooks() {
        if ( self::$hooks_registered ) {
            return;
        }

        self::$hooks_registered = true;

        add_action( 'save_post_' . ATBDP_POST_TYPE, [ __CLASS__, 'invalidate_listing' ], 101, 2 );
        add_action( 'before_delete_post', [ __CLASS__, 'invalidate_listing' ], 101, 2 );
        add_action( 'added_post_meta', [ __CLASS__, 'invalidate_rating' ], 101, 3 );
        add_action( 'updated_post_meta', [ __CLASS__, 'invalidate_rating' ], 101, 3 );
        add_action( 'deleted_post_meta', [ __CLASS__, 'invalidate_rating' ], 101, 3 );
    }
}
