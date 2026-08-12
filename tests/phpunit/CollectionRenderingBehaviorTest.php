<?php
/**
 * Behavior locks for collection, search-field, and repeated template rendering.
 */

use Directorist\Directorist_Listings;
use Directorist\Helper;

class Directorist_Collection_Rendering_Behavior_Test extends WP_UnitTestCase {
    private $directory_id;

    private $listing_id;

    private $temp_files = [];

    public function set_up() {
        parent::set_up();

        $directory         = wp_insert_term( 'Collection Directory', ATBDP_TYPE );
        $this->directory_id = (int) $directory['term_id'];

        update_term_meta( $this->directory_id, '_default', 1 );
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

        $this->listing_id = self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Collection Listing',
            ]
        );

        wp_set_object_terms( $this->listing_id, [ $this->directory_id ], ATBDP_TYPE );
        update_post_meta( $this->listing_id, '_directory_type', $this->directory_id );
        update_post_meta( $this->listing_id, '_company_name', 'Collection Company' );
    }

    public function tear_down() {
        unset( $_GET['in_cat'], $_GET['in_loc'], $_GET['directory_type'] );

        remove_all_filters( 'directorist_template_file_path' );
        remove_all_filters( 'directorist_template' );
        remove_all_filters( 'directorist_listing_archive_fields' );
        remove_all_filters( 'get_term_metadata' );
        remove_all_filters( 'directorist_use_search_taxonomy_directory_index' );
        remove_all_filters( 'directorist_search_taxonomy_directory_term_ids' );
        remove_all_filters( 'directorist_cache_listing_archive_submission_fields' );
        remove_all_filters( 'directorist_cache_listing_search_form_models' );

        foreach ( $this->temp_files as $file ) {
            if ( file_exists( $file ) ) {
                unlink( $file );
            }
        }

        parent::tear_down();
    }

    public function test_search_taxonomy_options_preserve_directory_filter_hierarchy_selection_and_icons() {
        $other_directory = wp_insert_term( 'Other Collection Directory', ATBDP_TYPE );
        $parent          = wp_insert_term( 'Parent Category', ATBDP_CATEGORY );
        $child           = wp_insert_term(
            'Child Category',
            ATBDP_CATEGORY,
            [ 'parent' => (int) $parent['term_id'] ]
        );
        $excluded        = wp_insert_term( 'Other Category', ATBDP_CATEGORY );

        update_term_meta( (int) $parent['term_id'], '_directory_type', [ $this->directory_id ] );
        update_term_meta( (int) $child['term_id'], '_directory_type', [ $this->directory_id ] );
        update_term_meta( (int) $excluded['term_id'], '_directory_type', [ (int) $other_directory['term_id'] ] );
        update_term_meta( (int) $parent['term_id'], 'category_icon', 'fas fa-briefcase' );

        $_GET['in_cat'] = (string) $child['term_id'];

        $html = search_category_location_filter(
            $this->taxonomy_settings( $this->directory_id ),
            ATBDP_CATEGORY
        );

        $this->assertStringContainsString( 'value="' . (int) $parent['term_id'] . '"', $html );
        $this->assertStringContainsString( 'data-icon-class="fas fa-briefcase"', $html );
        $this->assertStringContainsString( 'value="' . (int) $child['term_id'] . '" selected', $html );
        $this->assertStringContainsString( 'Parent Category', $html );
        $this->assertStringContainsString( 'Child Category', $html );
        $this->assertStringNotContainsString( 'Other Category', $html );
    }

    public function test_search_taxonomy_candidate_cache_is_exact_and_invalidated_by_directory_meta_changes() {
        $matching = wp_insert_term( 'Indexed Matching Category', ATBDP_CATEGORY );
        $other    = wp_insert_term( 'Indexed Other Category', ATBDP_CATEGORY );

        directorist_update_term_directory( (int) $matching['term_id'], [ $this->directory_id ] );
        directorist_update_term_directory( (int) $other['term_id'], [ $this->directory_id + 100 ] );

        $first = directorist_search_taxonomy_directory_term_ids( $this->directory_id, ATBDP_CATEGORY );

        $this->assertContains( (int) $matching['term_id'], $first );
        $this->assertNotContains( (int) $other['term_id'], $first );

        directorist_update_term_directory( (int) $matching['term_id'], [ $this->directory_id + 100 ] );
        $second = directorist_search_taxonomy_directory_term_ids( $this->directory_id, ATBDP_CATEGORY );

        $this->assertNotContains( (int) $matching['term_id'], $second );
    }

    public function test_search_taxonomy_candidate_map_can_be_disabled_for_dynamic_metadata_integrations() {
        add_filter( 'directorist_use_search_taxonomy_directory_index', '__return_false' );

        $this->assertNull(
            directorist_search_taxonomy_directory_term_ids( $this->directory_id, ATBDP_CATEGORY )
        );
    }

    public function test_card_field_preserves_legacy_meta_fallback_and_template_arguments() {
        delete_post_meta( $this->listing_id, '_company_name' );
        update_post_meta( $this->listing_id, 'company_name', 'Legacy Collection Company' );

        $captured = null;
        $fixture  = $this->create_template_fixture(
            '<?php $GLOBALS["directorist_collection_field_fixture"] = compact( "post_id", "data", "value", "label", "original_field" ); echo esc_html( $value );'
        );

        add_filter(
            'directorist_template_file_path',
            static function ( $file, $template ) use ( $fixture ) {
                return 'archive/custom-fields/text' === $template ? $fixture : $file;
            },
            10,
            2
        );

        $listings       = $this->create_listings_model();
        $GLOBALS['post'] = get_post( $this->listing_id );
        setup_postdata( $GLOBALS['post'] );
        $listings->set_loop_data();

        ob_start();
        $listings->render_card_field(
            [
                'type'                => 'list-item',
                'widget_name'         => 'text',
                'widget_key'          => 'company_card',
                'original_widget_key' => 'company_widget',
                'show_label'          => true,
                'label'               => 'Unresolved Label',
                'icon'                => '',
            ]
        );
        $html = ob_get_clean();
        wp_reset_postdata();

        $captured = $GLOBALS['directorist_collection_field_fixture'] ?? null;
        unset( $GLOBALS['directorist_collection_field_fixture'] );

        $this->assertSame( 'Legacy Collection Company', trim( $html ) );
        $this->assertSame( $this->listing_id, $captured['post_id'] );
        $this->assertSame( 'Legacy Collection Company', $captured['value'] );
        $this->assertSame( 'Company', $captured['label'] );
        $this->assertSame( 'company_name', $captured['data']['original_field']['field_key'] );
        $this->assertArrayHasKey( 'fields', $captured['original_field'] );
    }

    public function test_card_submission_configuration_is_loaded_once_per_renderer_with_dynamic_opt_out() {
        $metadata_calls = 0;
        add_filter(
            'get_term_metadata',
            static function ( $value, $term_id, $meta_key ) use ( &$metadata_calls ) {
                if ( 'submission_form_fields' === $meta_key ) {
                    ++$metadata_calls;
                }

                return $value;
            },
            10,
            3
        );

        $listings        = $this->create_listings_model();
        $GLOBALS['post'] = get_post( $this->listing_id );
        setup_postdata( $GLOBALS['post'] );

        $field = [
            'type'                => 'list-item',
            'widget_name'         => 'text',
            'widget_key'          => 'company_card',
            'original_widget_key' => 'company_widget',
            'show_label'          => true,
            'label'               => 'Company',
            'icon'                => '',
        ];

        ob_start();
        $listings->render_card_field( $field );
        $listings->render_card_field( $field );
        ob_end_clean();
        wp_reset_postdata();

        $this->assertSame( 1, $metadata_calls );

        add_filter( 'directorist_cache_listing_archive_submission_fields', '__return_false' );
        $metadata_calls = 0;
        $listings        = $this->create_listings_model();
        $GLOBALS['post'] = get_post( $this->listing_id );
        setup_postdata( $GLOBALS['post'] );

        ob_start();
        $listings->render_card_field( $field );
        $listings->render_card_field( $field );
        ob_end_clean();
        wp_reset_postdata();

        $this->assertSame( 2, $metadata_calls );
    }

    public function test_archive_field_filter_remains_listing_specific_for_each_card() {
        $calls = [];
        add_filter(
            'directorist_listing_archive_fields',
            static function ( $fields, $data ) use ( &$calls ) {
                $calls[] = [
                    'post_id'   => get_the_ID(),
                    'author_id' => (int) ( $data['author_id'] ?? 0 ),
                    'directory' => (int) ( $data['directory_type_id'] ?? 0 ),
                ];
                return $fields;
            },
            10,
            2
        );

        $listings        = $this->create_listings_model();
        $GLOBALS['post'] = get_post( $this->listing_id );
        setup_postdata( $GLOBALS['post'] );
        $listings->set_loop_data();
        wp_reset_postdata();

        $this->assertCount( 1, $calls );
        $this->assertSame( $this->listing_id, $calls[0]['post_id'] );
        $this->assertSame( $this->directory_id, $calls[0]['directory'] );
        $this->assertSame( (int) get_post_field( 'post_author', $this->listing_id ), $calls[0]['author_id'] );
    }

    public function test_basic_and_advanced_archive_renderers_share_compatible_search_form_state() {
        $instances = [];
        $fixture   = $this->create_template_fixture(
            '<?php $GLOBALS["directorist_collection_search_forms"][] = $searchform; echo esc_html( get_class( $searchform ) );'
        );

        add_filter(
            'directorist_template_file_path',
            static function ( $file, $template ) use ( $fixture ) {
                return in_array( $template, [ 'archive/basic-search-form', 'archive/advance-search-form' ], true )
                    ? $fixture
                    : $file;
            },
            10,
            2
        );

        $listings = $this->create_listings_model();

        ob_start();
        $listings->basic_search_form_template();
        $listings->advance_search_form_template();
        $html = ob_get_clean();

        $instances = $GLOBALS['directorist_collection_search_forms'] ?? [];
        unset( $GLOBALS['directorist_collection_search_forms'] );

        $this->assertStringContainsString( 'Directorist_Listing_Search_Form', $html );
        $this->assertCount( 2, $instances );
        $this->assertSame( $instances[0], $instances[1] );

        add_filter( 'directorist_cache_listing_search_form_models', '__return_false' );
        $GLOBALS['directorist_collection_search_forms'] = [];

        ob_start();
        $listings->basic_search_form_template();
        $listings->advance_search_form_template();
        ob_end_clean();

        $uncached = $GLOBALS['directorist_collection_search_forms'];
        unset( $GLOBALS['directorist_collection_search_forms'] );

        $this->assertNotSame( $uncached[0], $uncached[1] );
    }

    public function test_repeated_template_render_preserves_filters_hooks_arguments_and_output_order() {
        $fixture = $this->create_template_fixture( '<?php echo esc_html( $value );' );
        $path_filter_calls = 0;
        $events            = [];

        add_filter(
            'directorist_template_file_path',
            static function ( $file, $template, $args ) use ( $fixture, &$path_filter_calls ) {
                if ( 'phase-five/fixture' === $template ) {
                    ++$path_filter_calls;
                    return $fixture;
                }

                return $file;
            },
            10,
            3
        );

        $before = static function ( $template, $file, $args, $context ) use ( &$events ) {
            if ( 'phase-five/fixture' === $template ) {
                $events[] = [ 'before', $args['value'], $context['source'], $file ];
            }
        };
        $after = static function ( $template, $file, $args, $context ) use ( &$events ) {
            if ( 'phase-five/fixture' === $template ) {
                $events[] = [ 'after', $args['value'], $context['source'], $file ];
            }
        };

        add_action( 'directorist_before_template_render', $before, 20, 4 );
        add_action( 'directorist_after_template_render', $after, 20, 4 );

        ob_start();
        Helper::get_template( 'phase-five/fixture', [ 'value' => 'first' ] );
        Helper::get_template( 'phase-five/fixture', [ 'value' => 'second' ] );
        $html = ob_get_clean();

        remove_action( 'directorist_before_template_render', $before, 20 );
        remove_action( 'directorist_after_template_render', $after, 20 );

        $this->assertSame( 'firstsecond', $html );
        $this->assertSame( 2, $path_filter_calls );
        $this->assertSame( [ 'before', 'first' ], array_slice( $events[0], 0, 2 ) );
        $this->assertSame( [ 'after', 'first' ], array_slice( $events[1], 0, 2 ) );
        $this->assertSame( [ 'before', 'second' ], array_slice( $events[2], 0, 2 ) );
        $this->assertSame( [ 'after', 'second' ], array_slice( $events[3], 0, 2 ) );
        $this->assertSame( $events[0][2], $events[1][2] );
        $this->assertSame( $events[0][3], $events[1][3] );
    }

    private function create_listings_model() {
        return new Directorist_Listings(
            [
                'directory_type'  => (string) $this->directory_id,
                'show_pagination' => 'no',
            ],
            'listing',
            [
                'post_type'      => ATBDP_POST_TYPE,
                'post_status'    => 'publish',
                'post__in'       => [ $this->listing_id ],
                'posts_per_page' => 1,
                'no_found_rows'  => true,
                'fields'         => 'ids',
            ]
        );
    }

    private function taxonomy_settings( $directory_id ) {
        return [
            'parent'                       => 0,
            'term_id'                      => 0,
            'hide_empty'                   => 0,
            'orderby'                      => 'name',
            'order'                        => 'asc',
            'show_count'                   => 0,
            'single_only'                  => 0,
            'pad_counts'                   => true,
            'immediate_category'           => 0,
            'active_term_id'               => 0,
            'ancestors'                    => [],
            'listing_type'                 => $directory_id,
            'categories_with_custom_field' => [],
        ];
    }

    private function create_template_fixture( $contents ) {
        $file = tempnam( sys_get_temp_dir(), 'directorist-p5-template-' );
        file_put_contents( $file, $contents );
        $this->temp_files[] = $file;

        return $file;
    }
}
