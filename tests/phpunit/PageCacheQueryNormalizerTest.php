<?php
/**
 * Contract tests for Directorist page-cache query variation.
 */

use Directorist\Cache\Query_Normalizer;
use Directorist\Cache\Query_Schema_Resolver;

class Directorist_Page_Cache_Query_Normalizer_Test extends WP_UnitTestCase {
    /** @var int[] */
    private $directory_ids = [];

    public function tear_down() {
        foreach ( $this->directory_ids as $directory_id ) {
            wp_delete_term( $directory_id, ATBDP_DIRECTORY_TYPE );
        }

        $this->directory_ids = [];

        parent::tear_down();
    }

    public function test_equivalent_query_order_produces_the_same_normalized_variation() {
        $directory  = $this->create_directory( 'Search Order', [ 'title', 'category' ] );
        $normalizer = new Query_Normalizer();
        $first      = $normalizer->normalize(
            'search',
            [
                'sort'   => 'date-desc',
                'in_cat' => '12',
                'q'      => 'coffee',
                'directory_type' => $directory['slug'],
            ],
            'sort=date-desc&in_cat=12&q=coffee&directory_type=' . $directory['slug']
        );
        $second     = $normalizer->normalize(
            'search',
            [
                'q'      => 'coffee',
                'in_cat' => '12',
                'sort'   => 'date-desc',
                'directory_type' => $directory['slug'],
            ],
            'q=coffee&in_cat=12&sort=date-desc&directory_type=' . $directory['slug']
        );

        $this->assertTrue( $first->is_valid() );
        $this->assertTrue( $second->is_valid() );
        $this->assertSame( $first->get_args(), $second->get_args() );
    }

    public function test_nested_custom_fields_are_canonicalized_without_losing_values() {
        $directory = $this->create_directory(
            'Custom Search',
            [ 'pricing' ],
            [ 'select_2', 'text_1' ],
            [
                'select_2' => [ 'widget_name' => 'select', 'field_key' => 'custom-select-2' ],
                'text_1'   => [ 'widget_name' => 'text', 'field_key' => 'custom-text-1' ],
            ]
        );
        $result = ( new Query_Normalizer() )->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'custom_field' => [
                    'custom-select-2' => [ 'premium', 'basic' ],
                    'custom-text-1'   => 'Ocean View',
                ],
                'price'        => [ '10', '90' ],
            ],
            'custom_field%5Bcustom-select-2%5D%5B%5D=premium&custom_field%5Bcustom-select-2%5D%5B%5D=basic&custom_field%5Bcustom-text-1%5D=Ocean+View&price%5B%5D=10&price%5B%5D=90'
        );

        $this->assertTrue( $result->is_valid() );
        $this->assertSame( [ 'basic', 'premium' ], $result->get_args()['custom_field']['custom-select-2'] );
        $this->assertSame( [ '10', '90' ], $result->get_args()['price'] );
    }

    public function test_nested_keys_that_collapse_during_normalization_are_rejected() {
        $callback = static function ( $schema ) {
            $schema['arguments'][]                      = 'custom_field';
            $schema['nested_arguments']['custom_field'] = true;

            return $schema;
        };
        add_filter( 'directorist_page_cache_query_schema', $callback );

        $result = ( new Query_Normalizer() )->normalize(
            'search',
            [
                'custom_field' => [
                    'room.type' => 'suite',
                    'roomtype'  => 'standard',
                ],
            ]
        );
        remove_filter( 'directorist_page_cache_query_schema', $callback );

        $this->assertFalse( $result->is_valid() );
        $this->assertSame( 'invalid_query_value', $result->get_reason() );
    }

    public function test_collection_routes_allow_the_query_arguments_directorist_renders() {
        $directory = $this->create_directory(
            'Rendered Search',
            [ 'title', 'category', 'location', 'tag' ],
            [ 'pricing', 'review', 'radius_search' ]
        );
        $result = ( new Query_Normalizer() )->normalize(
            'listings',
            [
                'q'                => 'hotel',
                'in_cat'           => '3,5',
                'in_loc'           => [ '7', '9' ],
                'in_tag'           => '11',
                'directory_type'   => $directory['slug'],
                'sort'             => 'price-asc',
                'view'             => 'grid',
                'paged'            => '2',
                'price_range'      => 'moderate',
                'search_by_rating' => [ '4', '5' ],
                'miles'            => '25',
                'address'          => 'Dhaka',
                'cityLat'          => '23.8103',
                'cityLng'          => '90.4125',
            ]
        );

        $this->assertTrue( $result->is_valid(), $result->get_reason() . ':' . $result->get_detail() );
    }

    public function test_unknown_query_argument_is_rejected() {
        $directory = $this->create_directory( 'Unknown Argument', [ 'title' ] );
        $result = ( new Query_Normalizer() )->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'q'             => 'hotel',
                'private_token' => 'secret',
            ]
        );

        $this->assertFalse( $result->is_valid() );
        $this->assertSame( 'unsupported_query_argument', $result->get_reason() );
        $this->assertSame( 'private_token', $result->get_detail() );
    }

    /**
     * @dataProvider nonce_argument_provider
     */
    public function test_nonce_and_security_arguments_are_always_rejected( $name ) {
        $result = ( new Query_Normalizer() )->normalize( 'search', [ $name => 'abc123' ] );

        $this->assertFalse( $result->is_valid() );
        $this->assertSame( 'private_query_argument', $result->get_reason() );
    }

    public function nonce_argument_provider() {
        return [
            'WordPress nonce'   => [ '_wpnonce' ],
            'Directorist nonce' => [ 'atbdp_nonce' ],
            'generic nonce'     => [ 'nonce' ],
            'security token'    => [ 'security' ],
        ];
    }

    public function test_ambiguous_duplicate_scalar_key_is_rejected() {
        $result = ( new Query_Normalizer() )->normalize(
            'search',
            [ 'sort' => 'date-desc' ],
            'sort=price-asc&sort=date-desc'
        );

        $this->assertFalse( $result->is_valid() );
        $this->assertSame( 'duplicate_query_argument', $result->get_reason() );
        $this->assertSame( 'sort', $result->get_detail() );
    }

    public function test_mixed_scalar_and_array_shape_for_the_same_key_is_rejected() {
        $result = ( new Query_Normalizer() )->normalize(
            'search',
            [ 'in_cat' => [ '4', '8' ] ],
            'in_cat=4&in_cat%5B%5D=8'
        );

        $this->assertFalse( $result->is_valid() );
        $this->assertSame( 'duplicate_query_argument', $result->get_reason() );
    }

    public function test_repeated_array_values_are_not_treated_as_ambiguous_duplicates() {
        $directory = $this->create_directory( 'Array Category', [ 'category' ] );
        $result = ( new Query_Normalizer() )->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'in_cat'         => [ '4', '8' ],
            ],
            'directory_type=' . $directory['slug'] . '&in_cat%5B%5D=4&in_cat%5B%5D=8'
        );

        $this->assertTrue( $result->is_valid() );
        $this->assertSame( [ '4', '8' ], $result->get_args()['in_cat'] );
    }

    public function test_single_listing_rejects_collection_query_variation() {
        $result = ( new Query_Normalizer() )->normalize( 'listing', [ 'sort' => 'date-desc' ] );

        $this->assertFalse( $result->is_valid() );
    }

    public function test_taxonomy_page_slug_and_search_form_state_are_supported() {
        $result = ( new Query_Normalizer() )->normalize(
            'category',
            [
                'category' => 'restaurants',
                'cat_id'   => '17',
                'loc_id'   => '9',
            ]
        );

        $this->assertTrue( $result->is_valid(), $result->get_reason() );
    }

    public function test_only_fields_placed_in_search_bar_or_filter_are_allowed() {
        $directory  = $this->create_directory(
            'Placed Fields',
            [ 'title' ],
            [ 'active_text' ],
            [
                'active_text' => [ 'widget_name' => 'text', 'field_key' => 'custom-active-text' ],
                'stale_text'  => [ 'widget_name' => 'text', 'field_key' => 'custom-stale-text' ],
            ],
            [ 'stale_text' ]
        );
        $normalizer = new Query_Normalizer();
        $active     = $normalizer->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'custom_field'   => [ 'custom-active-text' => 'visible' ],
            ]
        );
        $stale      = $normalizer->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'custom_field'   => [ 'custom-stale-text' => 'hidden' ],
            ]
        );

        $this->assertTrue( $active->is_valid(), $active->get_reason() );
        $this->assertFalse( $stale->is_valid() );
        $this->assertSame( 'unsupported_query_argument', $stale->get_reason() );
        $this->assertSame( 'custom_field[custom-stale-text]', $stale->get_detail() );
    }

    public function test_preset_arguments_follow_the_selected_directory_configuration() {
        $directory = $this->create_directory( 'Title Only', [ 'title' ] );
        $result    = ( new Query_Normalizer() )->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'in_cat'         => '12',
            ]
        );

        $this->assertFalse( $result->is_valid() );
        $this->assertSame( 'in_cat', $result->get_detail() );
    }

    public function test_all_directory_selection_unions_each_directory_search_schema() {
        $first = $this->create_directory(
            'Union One',
            [],
            [ 'first_text' ],
            [ 'first_text' => [ 'widget_name' => 'text', 'field_key' => 'custom-first' ] ]
        );
        $this->create_directory(
            'Union Two',
            [],
            [ 'second_text' ],
            [ 'second_text' => [ 'widget_name' => 'text', 'field_key' => 'custom-second' ] ]
        );

        $result = ( new Query_Normalizer() )->normalize(
            'search',
            [
                'directory_type' => 'all',
                'custom_field'   => [
                    'custom-first'  => 'one',
                    'custom-second' => 'two',
                ],
            ]
        );

        $this->assertNotEmpty( $first['id'] );
        $this->assertTrue( $result->is_valid(), $result->get_reason() . ':' . $result->get_detail() );
    }

    public function test_global_provider_arguments_union_builder_and_extension_schema() {
        $this->create_directory(
            'Provider Union One',
            [ 'title' ],
            [ 'first_text' ],
            [ 'first_text' => [ 'widget_name' => 'text', 'field_key' => 'custom-first' ] ]
        );
        $this->create_directory( 'Provider Union Two', [], [ 'radius_search' ] );
        $callback = static function ( $schema, $route_type ) {
            if ( 'search' === $route_type ) {
                $schema['arguments'][] = 'extension_public_filter';
            }

            return $schema;
        };
        add_filter( 'directorist_page_cache_query_schema', $callback, 10, 2 );
        $arguments = ( new Query_Schema_Resolver() )->all_public_arguments();
        remove_filter( 'directorist_page_cache_query_schema', $callback, 10 );

        $this->assertContains( 'q', $arguments );
        $this->assertContains( 'custom_field', $arguments );
        $this->assertContains( 'miles', $arguments );
        $this->assertContains( 'extension_public_filter', $arguments );
        $this->assertSame( $arguments, array_values( array_unique( $arguments ) ) );
    }

    public function test_builder_configuration_changes_are_used_without_manual_cache_management() {
        $directory  = $this->create_directory( 'Mutable Search', [ 'title' ] );
        $normalizer = new Query_Normalizer();
        $before     = $normalizer->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'in_cat'         => '12',
            ]
        );

        $search_config                          = get_term_meta( $directory['id'], 'search_form_fields', true );
        $search_config['fields']['category']    = $this->search_field( 'category' );
        $search_config['groups'][0]['fields'][] = 'category';
        update_term_meta( $directory['id'], 'search_form_fields', $search_config );

        $after = $normalizer->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'in_cat'         => '12',
            ]
        );

        $this->assertFalse( $before->is_valid() );
        $this->assertTrue( $after->is_valid(), $after->get_reason() );
    }

    public function test_extensions_can_register_builder_field_query_arguments_in_code() {
        $directory = $this->create_directory( 'Extension Search', [], [ 'business_hours' ] );
        $calls     = 0;
        $callback  = static function ( $schema, $field, $submission_field, $directory_id ) use ( $directory, &$calls ) {
            unset( $submission_field );

            if ( $directory['id'] === $directory_id && 'business_hours' === $field['widget_name'] ) {
                ++$calls;
                $schema['arguments'][] = 'open_now';
            }

            return $schema;
        };
        add_filter( 'directorist_page_cache_field_query_schema', $callback, 10, 4 );

        $result = ( new Query_Normalizer() )->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'open_now'       => '1',
            ]
        );
        remove_filter( 'directorist_page_cache_field_query_schema', $callback, 10 );

        $this->assertTrue( $result->is_valid(), $result->get_reason() );
        $this->assertSame( 1, $calls );
    }

    public function test_location_arguments_follow_the_builder_location_source() {
        $directory                                              = $this->create_directory( 'Location Source', [ 'location' ] );
        $normalizer                                             = new Query_Normalizer();
        $search_config                                          = get_term_meta( $directory['id'], 'search_form_fields', true );
        $search_config['fields']['location']['location_source'] = 'from_listing_location';
        update_term_meta( $directory['id'], 'search_form_fields', $search_config );

        $taxonomy_location  = $normalizer->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'in_loc'         => '15',
            ]
        );
        $unexpected_address = $normalizer->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'address'        => 'Dhaka',
            ]
        );

        $search_config['fields']['location']['location_source'] = 'from_map_api';
        update_term_meta( $directory['id'], 'search_form_fields', $search_config );

        $map_location        = $normalizer->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'address'        => 'Dhaka',
                'cityLat'        => '23.8103',
                'cityLng'        => '90.4125',
            ]
        );
        $unexpected_taxonomy = $normalizer->normalize(
            'search',
            [
                'directory_type' => $directory['slug'],
                'in_loc'         => '15',
            ]
        );

        $this->assertTrue( $taxonomy_location->is_valid(), $taxonomy_location->get_reason() );
        $this->assertFalse( $unexpected_address->is_valid() );
        $this->assertTrue( $map_location->is_valid(), $map_location->get_reason() );
        $this->assertFalse( $unexpected_taxonomy->is_valid() );
    }

    public function test_legacy_top_level_allowlist_filter_remains_compatible() {
        $callback = static function ( $arguments ) {
            $arguments[] = 'legacy_public_filter';

            return $arguments;
        };
        add_filter( 'directorist_page_cache_query_allowlist', $callback );
        $result = ( new Query_Normalizer() )->normalize( 'embedded', [ 'legacy_public_filter' => 'active' ] );
        remove_filter( 'directorist_page_cache_query_allowlist', $callback );

        $this->assertTrue( $result->is_valid(), $result->get_reason() );
    }

    /**
     * @param string   $name Directory name.
     * @param string[] $basic_fields Active Search Bar fields.
     * @param string[] $advanced_fields Active Search Filter fields.
     * @param array    $custom_fields Custom submission fields keyed by builder widget key.
     * @param string[] $unplaced_fields Definitions that are deliberately absent from groups.
     * @return array{id:int,slug:string}
     */
    private function create_directory( $name, array $basic_fields = [], array $advanced_fields = [], array $custom_fields = [], array $unplaced_fields = [] ) {
        $term_id = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => $name,
            ]
        );
        $term    = get_term( $term_id, ATBDP_DIRECTORY_TYPE );
        $fields  = [];

        foreach ( array_unique( array_merge( $basic_fields, $advanced_fields, $unplaced_fields ) ) as $field_key ) {
            $widget_name          = isset( $custom_fields[ $field_key ]['widget_name'] ) ? $custom_fields[ $field_key ]['widget_name'] : $field_key;
            $fields[ $field_key ] = $this->search_field( $widget_name, $field_key );
        }

        update_term_meta(
            $term_id,
            'search_form_fields',
            [
                'fields' => $fields,
                'groups' => [
                    [ 'label' => 'Search Bar', 'fields' => $basic_fields ],
                    [ 'label' => 'Search Filter', 'fields' => $advanced_fields ],
                ],
            ]
        );

        $submission_fields = [];
        foreach ( $custom_fields as $field_key => $field ) {
            $submission_fields[ $field_key ] = array_merge(
                [
                    'widget_name' => $field['widget_name'],
                    'widget_key'  => $field_key,
                    'field_key'   => $field['field_key'],
                ],
                $field
            );
        }
        update_term_meta( $term_id, 'submission_form_fields', [ 'fields' => $submission_fields ] );

        $this->directory_ids[] = $term_id;

        return [ 'id' => $term_id, 'slug' => $term->slug ];
    }

    /**
     * @param string $widget_name Field widget name.
     * @param string $field_key Builder storage key.
     * @return array
     */
    private function search_field( $widget_name, $field_key = '' ) {
        $field_key = $field_key ?: $widget_name;

        return [
            'widget_group'        => 'available_widgets',
            'widget_name'         => $widget_name,
            'widget_key'          => $field_key,
            'original_widget_key' => $field_key,
        ];
    }
}
