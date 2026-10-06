<?php
/**
 * Behavior locks for Directorist taxonomy title handling.
 */

class Directorist_SEO_Title_Behavior_Test extends WP_UnitTestCase {
    private $original_options;

    public function set_up() {
        parent::set_up();
        $this->original_options = get_option( 'atbdp_option', [] );
    }

    public function tear_down() {
        update_option( 'atbdp_option', $this->original_options );
        remove_all_filters( 'directorist_option' );
        set_query_var( 'atbdp_category', '' );
        set_query_var( 'atbdp_location', '' );
        set_query_var( 'atbdp_tag', '' );
        wp_reset_postdata();

        parent::tear_down();
    }

    public function test_unrelated_integer_post_title_is_not_changed() {
        $post_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Menu Page',
            ]
        );

        $seo = new ATBDP_SEO();

        $this->assertSame( 'Menu Page', $seo->update_taxonomy_page_title( 'Menu Page', $post_id ) );
    }

    public function test_category_page_title_uses_requested_directorist_term_name() {
        $category_page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Directory Category',
            ]
        );
        $term             = wp_insert_term( 'Restaurants', ATBDP_CATEGORY, [ 'slug' => 'restaurants' ] );

        $options                         = get_option( 'atbdp_option', [] );
        $options['single_category_page'] = $category_page_id;
        update_option( 'atbdp_option', $options );

        $GLOBALS['post'] = get_post( $category_page_id );
        set_query_var( 'atbdp_category', 'restaurants' );

        $page_option_calls = 0;
        add_filter(
            'directorist_option',
            static function ( $value, $name ) use ( &$page_option_calls ) {
                if ( in_array( $name, [ 'single_category_page', 'single_location_page', 'single_tag_page' ], true ) ) {
                    ++$page_option_calls;
                }

                return $value;
            },
            10,
            2
        );

        $seo = new ATBDP_SEO();

        $this->assertSame( 'Restaurants', $seo->get_taxonomy_page_title( 'Directory Category', $category_page_id ) );
        $this->assertSame( 'Restaurants', $seo->get_taxonomy_page_title( 'Directory Category', $category_page_id ) );
        $this->assertSame( 3, $page_option_calls );
        $this->assertSame( (int) $term['term_id'], get_term_by( 'slug', 'restaurants', ATBDP_CATEGORY )->term_id );
    }

    public function test_location_and_tag_pages_use_their_requested_term_names() {
        $cases = [
            [ 'location', 'single_location_page', 'atbdp_location', ATBDP_LOCATION, 'Dhaka', 'dhaka' ],
            [ 'tag', 'single_tag_page', 'atbdp_tag', ATBDP_TAGS, 'Featured', 'featured' ],
        ];

        foreach ( $cases as [ $page_name, $option_name, $query_var, $taxonomy, $term_name, $term_slug ] ) {
            $page_id = self::factory()->post->create(
                [
                    'post_type'   => 'page',
                    'post_status' => 'publish',
                    'post_title'  => 'Directory ' . ucfirst( $page_name ),
                ]
            );
            wp_insert_term( $term_name, $taxonomy, [ 'slug' => $term_slug ] );

            $options                 = get_option( 'atbdp_option', [] );
            $options[ $option_name ] = $page_id;
            update_option( 'atbdp_option', $options );

            $GLOBALS['post'] = get_post( $page_id );
            set_query_var( $query_var, $term_slug );

            $seo = new ATBDP_SEO();

            $this->assertSame( $term_name, $seo->get_taxonomy_page_title( get_the_title( $page_id ), $page_id ) );

            set_query_var( $query_var, '' );
        }
    }

    public function test_single_listing_title_filter_does_not_resolve_taxonomy_pages() {
        $listing_id   = self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Single Listing',
            ]
        );
        $menu_page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Menu Page',
            ]
        );

        $options                         = get_option( 'atbdp_option', [] );
        $options['single_category_page'] = self::factory()->post->create( [ 'post_type' => 'page' ] );
        $options['single_location_page'] = self::factory()->post->create( [ 'post_type' => 'page' ] );
        $options['single_tag_page']      = self::factory()->post->create( [ 'post_type' => 'page' ] );
        update_option( 'atbdp_option', $options );

        $requested_options = [];
        add_filter(
            'directorist_option',
            static function ( $value, $name ) use ( &$requested_options ) {
                $requested_options[] = $name;
                return $value;
            },
            10,
            2
        );

        $this->go_to( get_permalink( $listing_id ) );

        $seo = new ATBDP_SEO();

        $this->assertSame( 'Menu Page', $seo->update_taxonomy_page_title( 'Menu Page', $menu_page_id ) );
        $this->assertSame( [], array_values( array_intersect( [ 'single_category_page', 'single_location_page', 'single_tag_page' ], $requested_options ) ) );
    }
}
