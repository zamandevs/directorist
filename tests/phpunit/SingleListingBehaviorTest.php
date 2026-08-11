<?php
/**
 * Behavior locks for core single-listing data and rendering contracts.
 */

use Directorist\Directorist_Single_Listing;
use Directorist\Directorist_Listings;

class Directorist_Single_Listing_Behavior_Test extends WP_UnitTestCase {
    private $directory_id;

    private $listing_id;

    public function set_up() {
        parent::set_up();

        $directory = wp_insert_term( 'Single Listing Directory', ATBDP_TYPE );
        $this->directory_id = (int) $directory['term_id'];

        update_term_meta(
            $this->directory_id,
            'submission_form_fields',
            [
                'fields' => [
                    'company_widget' => [
                        'widget_name'  => 'text',
                        'widget_group' => 'custom',
                        'field_key'    => 'company_name',
                        'label'        => 'Company',
                    ],
                ],
            ]
        );

        update_term_meta(
            $this->directory_id,
            'single_listings_contents',
            [
                'fields' => [
                    'company_single' => [
                        'widget_name'         => 'text',
                        'widget_group'        => 'custom',
                        'original_widget_key' => 'company_widget',
                    ],
                ],
                'groups' => [
                    [
                        'type'        => 'general_group',
                        'widget_name' => 'general_group',
                        'label'       => 'Details',
                        'fields'      => [ 'company_single' ],
                    ],
                ],
            ]
        );

        $this->listing_id = self::factory()->post->create(
            [
                'post_type'    => ATBDP_POST_TYPE,
                'post_status'  => 'publish',
                'post_title'   => 'Behavior Listing',
                'post_content' => 'Behavior listing description.',
            ]
        );

        wp_set_object_terms( $this->listing_id, [ $this->directory_id ], ATBDP_TYPE );
        update_post_meta( $this->listing_id, '_directory_type', $this->directory_id );
        update_post_meta( $this->listing_id, '_company_name', 'Example Company' );
        update_term_meta( $this->directory_id, '_default', 1 );

        $this->reset_single_listing();
    }

    public function tear_down() {
        remove_all_filters( 'directorist_single_listing_widget_value' );
        remove_all_filters( 'directorist_single_listing_thumbnails' );
        remove_all_filters( 'directorist_single_map_info_content' );
        remove_all_filters( 'directorist_cache_single_listing_map_data' );
        remove_all_filters( 'directorist_related_listing_args' );
        remove_all_filters( 'directorist_cache_related_listings' );
        $this->reset_single_listing();

        parent::tear_down();
    }

    public function test_instance_prepares_listing_and_directory_configuration() {
        $listing = Directorist_Single_Listing::instance( $this->listing_id );

        $this->assertSame( $this->listing_id, $listing->id );
        $this->assertSame( $this->directory_id, $listing->type );
        $this->assertSame( 'Behavior Listing', $listing->post->post_title );
        $this->assertCount( 1, $listing->content_data );
        $this->assertSame( 'Details', $listing->content_data[0]['label'] );
        $this->assertSame( 'company_name', $listing->content_data[0]['fields']['company_single']['field_key'] );
        $this->assertSame( 'Company', $listing->content_data[0]['fields']['company_single']['label'] );
    }

    public function test_field_value_preserves_underscored_legacy_explicit_and_filtered_values() {
        $listing = Directorist_Single_Listing::instance( $this->listing_id );

        update_post_meta( $this->listing_id, 'legacy_field', 'Legacy Value' );

        $this->assertSame( 'Example Company', $listing->get_field_value( [ 'field_key' => 'company_name' ] ) );
        $this->assertSame( 'Legacy Value', $listing->get_field_value( [ 'field_key' => 'legacy_field' ] ) );
        $this->assertSame( 'Explicit Value', $listing->get_field_value( [ 'value' => 'Explicit Value' ] ) );
        $this->assertSame(
            'Custom Content',
            $listing->get_field_value(
                [
                    'widget_name' => 'custom_content',
                    'content'     => 'Custom Content',
                ]
            )
        );

        add_filter(
            'directorist_single_listing_widget_value',
            static function ( $value, $field ) {
                return 'company_name' === ( $field['field_key'] ?? '' ) ? $value . ' Filtered' : $value;
            },
            10,
            2
        );

        $this->assertSame( 'Example Company Filtered', $listing->get_field_value( [ 'field_key' => 'company_name' ] ) );
    }

    public function test_general_section_visibility_and_field_markup_are_preserved() {
        $listing = Directorist_Single_Listing::instance( $this->listing_id );
        $section = $listing->content_data[0];

        $this->assertTrue( $listing->section_has_contents( $section ) );

        ob_start();
        $listing->section_template( $section );
        $html = ob_get_clean();

        $this->assertStringContainsString( 'directorist-card-general-section', $html );
        $this->assertStringContainsString( 'Details', $html );
        $this->assertStringContainsString( 'Company', $html );
        $this->assertStringContainsString( 'Example Company', $html );
    }

    public function test_listing_content_preserves_content_filters_and_shortcodes() {
        add_shortcode(
            'single_listing_fixture',
            static function () {
                return 'Shortcode Output';
            }
        );
        wp_update_post(
            [
                'ID'           => $this->listing_id,
                'post_content' => 'Before [single_listing_fixture] After',
            ]
        );
        $this->reset_single_listing();

        $content = Directorist_Single_Listing::instance( $this->listing_id )->get_contents();

        remove_shortcode( 'single_listing_fixture' );

        $this->assertStringContainsString( 'Before', $content );
        $this->assertStringContainsString( 'Shortcode Output', $content );
        $this->assertStringContainsString( 'After', $content );
    }

    public function test_listing_content_is_processed_once_per_instance() {
        $filter_calls = 0;
        add_filter(
            'directorist_the_content',
            static function ( $content ) use ( &$filter_calls ) {
                ++$filter_calls;
                return $content;
            }
        );

        $listing = Directorist_Single_Listing::instance( $this->listing_id );

        $this->assertSame( $listing->get_contents(), $listing->get_contents() );
        $this->assertSame( 1, $filter_calls );
    }

    public function test_stable_field_meta_is_loaded_once_while_repeated_reads_keep_their_value() {
        $metadata_calls = 0;
        add_filter(
            'get_post_metadata',
            static function ( $value, $object_id, $meta_key ) use ( &$metadata_calls ) {
                if ( '_company_name' === $meta_key ) {
                    ++$metadata_calls;
                }

                return $value;
            },
            10,
            3
        );

        $listing = Directorist_Single_Listing::instance( $this->listing_id );
        $field   = [ 'field_key' => 'company_name' ];

        $this->assertSame( 'Example Company', $listing->get_field_value( $field ) );
        $this->assertSame( 'Example Company', $listing->get_field_value( $field ) );
        $this->assertSame( 1, $metadata_calls );
    }

    public function test_hydrated_data_can_be_reset_after_an_in_request_mutation() {
        $listing = Directorist_Single_Listing::instance( $this->listing_id );
        $field   = [ 'field_key' => 'company_name' ];

        $this->assertSame( 'Example Company', $listing->get_field_value( $field ) );
        $this->assertStringContainsString( 'Behavior listing description.', $listing->get_contents() );

        update_post_meta( $this->listing_id, '_company_name', 'Updated Company' );
        wp_update_post(
            [
                'ID'           => $this->listing_id,
                'post_content' => 'Updated listing description.',
            ]
        );

        $this->assertSame( 'Example Company', $listing->get_field_value( $field ) );
        $this->assertStringNotContainsString( 'Updated listing description.', $listing->get_contents() );

        $listing->reset_hydrated_data();

        $this->assertSame( 'Updated Company', $listing->get_field_value( $field ) );
        $this->assertStringContainsString( 'Updated listing description.', $listing->get_contents() );
    }

    public function test_section_preflight_and_render_share_one_resolved_field_value() {
        $field_filter_calls = 0;
        add_filter(
            'directorist_single_listing_widget_value',
            static function ( $value, $field ) use ( &$field_filter_calls ) {
                if ( 'company_name' === ( $field['field_key'] ?? '' ) ) {
                    ++$field_filter_calls;
                }

                return $value;
            },
            10,
            2
        );

        $listing = Directorist_Single_Listing::instance( $this->listing_id );
        $section = $listing->content_data[0];

        $this->assertTrue( $listing->section_has_contents( $section ) );

        ob_start();
        $listing->section_template( $section );
        ob_end_clean();

        $this->assertSame( 1, $field_filter_calls );
    }

    public function test_custom_single_page_content_is_built_once_per_instance() {
        $page_id = self::factory()->post->create(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_content' => 'Custom single page content.',
            ]
        );
        update_term_meta( $this->directory_id, 'enable_single_listing_page', true );
        update_term_meta( $this->directory_id, 'single_listing_page', $page_id );

        $pre_content_calls = 0;
        add_filter(
            'directorist_custom_single_listing_pre_page_content',
            static function ( $content ) use ( &$pre_content_calls ) {
                ++$pre_content_calls;
                return $content;
            }
        );

        $listing = Directorist_Single_Listing::instance( $this->listing_id );

        $this->assertSame( $listing->single_page_content(), $listing->single_page_content() );
        $this->assertSame( 1, $pre_content_calls );
    }

    public function test_map_data_preserves_values_and_is_built_once_per_instance() {
        update_post_meta( $this->listing_id, '_manual_lat', '23.8103' );
        update_post_meta( $this->listing_id, '_manual_lng', '90.4125' );
        update_post_meta( $this->listing_id, '_address', 'Dhaka' );
        $this->reset_single_listing();

        $map_filter_calls = 0;
        add_filter(
            'directorist_single_map_info_content',
            static function ( $content ) use ( &$map_filter_calls ) {
                ++$map_filter_calls;
                return $content;
            }
        );

        $GLOBALS['post'] = get_post( $this->listing_id );
        setup_postdata( $GLOBALS['post'] );

        $listing = Directorist_Single_Listing::instance( $this->listing_id );
        $first   = $listing->map_data();
        $second  = $listing->map_data();
        $data    = json_decode( $first, true );

        $this->assertSame( $first, $second );
        $this->assertSame( '23.8103', $data['manual_lat'] );
        $this->assertSame( '90.4125', $data['manual_lng'] );
        $this->assertStringContainsString( 'Dhaka', $data['info_content'] );
        $this->assertSame( 1, $map_filter_calls );
    }

    public function test_map_data_cache_can_be_disabled_for_dynamic_extension_output() {
        $map_filter_calls = 0;
        add_filter( 'directorist_cache_single_listing_map_data', '__return_false' );
        add_filter(
            'directorist_single_map_info_content',
            static function ( $content ) use ( &$map_filter_calls ) {
                ++$map_filter_calls;
                return $content;
            }
        );

        $listing = Directorist_Single_Listing::instance( $this->listing_id );

        $listing->map_data();
        $listing->map_data();

        $this->assertSame( 2, $map_filter_calls );
    }

    public function test_slider_data_is_built_once_and_keeps_a_fallback_image() {
        $thumbnail_filter_calls = 0;
        add_filter(
            'directorist_single_listing_thumbnails',
            static function ( $images ) use ( &$thumbnail_filter_calls ) {
                ++$thumbnail_filter_calls;
                return $images;
            }
        );

        $listing = Directorist_Single_Listing::instance( $this->listing_id );
        $first   = $listing->get_slider_data();
        $second  = $listing->get_slider_data();

        $this->assertSame( $first, $second );
        $this->assertNotEmpty( $first['images'] );
        $this->assertSame( 1, $thumbnail_filter_calls );
    }

    public function test_image_field_preserves_content_based_section_visibility_without_rendering_an_empty_field() {
        $listing = Directorist_Single_Listing::instance( $this->listing_id );
        $field   = [
            'widget_name'  => 'image_upload',
            'widget_group' => 'preset',
            'field_key'    => 'listing_img',
        ];
        $section = [
            'fields' => [ $field ],
        ];

        $this->assertTrue( $listing->section_has_contents( $section ) );

        ob_start();
        $listing->field_template( $field );
        $html = ob_get_clean();

        $this->assertSame( '', $html );
    }

    public function test_related_listing_model_primes_visible_posts_once() {
        $listings = new Directorist_Listings(
            [ 'directory_type' => (string) $this->directory_id ],
            'related',
            [
                'post_type'      => ATBDP_POST_TYPE,
                'post_status'    => 'publish',
                'post__in'       => [ $this->listing_id ],
                'posts_per_page' => 1,
                'no_found_rows'  => true,
            ]
        );

        $listings->prime_post_caches();
        $listings->prime_post_caches();

        $primed = new ReflectionProperty( Directorist_Listings::class, 'post_caches_primed' );
        $primed->setAccessible( true );
        $this->assertTrue( $primed->getValue( $listings ) );
    }

    public function test_related_listings_preserve_query_contract_and_result_selection() {
        $category = wp_insert_term( 'Related Category', ATBDP_CATEGORY );
        $tag      = wp_insert_term( 'Related Tag', ATBDP_TAGS );

        wp_set_object_terms( $this->listing_id, [ (int) $category['term_id'] ], ATBDP_CATEGORY );
        wp_set_object_terms( $this->listing_id, [ (int) $tag['term_id'] ], ATBDP_TAGS );

        $related_id = self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Related Listing',
            ]
        );
        wp_set_object_terms( $related_id, [ $this->directory_id ], ATBDP_TYPE );
        wp_set_object_terms( $related_id, [ (int) $category['term_id'] ], ATBDP_CATEGORY );
        wp_set_object_terms( $related_id, [ (int) $tag['term_id'] ], ATBDP_TAGS );
        update_post_meta( $related_id, '_directory_type', $this->directory_id );

        $captured_args = [];
        add_filter(
            'directorist_related_listing_args',
            static function ( $args ) use ( &$captured_args ) {
                $captured_args = $args;
                return $args;
            }
        );

        $related = Directorist_Single_Listing::instance( $this->listing_id )->get_related_listings(
            [
                'similar_listings_number_of_listings_to_show' => 2,
                'similar_listings_logics'                     => 'AND',
            ]
        );

        $this->assertSame( 2, $captured_args['posts_per_page'] );
        $this->assertTrue( $captured_args['no_found_rows'] );
        $this->assertSame( [ $this->listing_id ], $captured_args['post__not_in'] );
        $this->assertSame( 'AND', $captured_args['tax_query']['relation'] );
        $this->assertContains( $related_id, $related->post_ids() );
        $this->assertNotContains( $this->listing_id, $related->post_ids() );

        $cached = Directorist_Single_Listing::instance( $this->listing_id )->get_related_listings(
            [
                'similar_listings_number_of_listings_to_show' => 2,
                'similar_listings_logics'                     => 'AND',
            ]
        );

        $this->assertSame( $related, $cached );
    }

    public function test_related_listing_cache_can_be_disabled_for_dynamic_extension_queries() {
        add_filter( 'directorist_cache_related_listings', '__return_false' );

        $listing = Directorist_Single_Listing::instance( $this->listing_id );
        $args    = [ 'similar_listings_number_of_listings_to_show' => 1 ];

        $first  = $listing->get_related_listings( $args );
        $second = $listing->get_related_listings( $args );

        $this->assertNotSame( $first, $second );
    }

    private function reset_single_listing() {
        $instance = new ReflectionProperty( Directorist_Single_Listing::class, 'instance' );
        $instance->setAccessible( true );
        $instance->setValue( null, null );
    }
}
