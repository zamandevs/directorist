<?php
/**
 * Contract tests for Directorist page-cache query variation.
 */

use Directorist\Cache\Query_Normalizer;

class Directorist_Page_Cache_Query_Normalizer_Test extends WP_UnitTestCase {
    public function test_equivalent_query_order_produces_the_same_normalized_variation() {
        $normalizer = new Query_Normalizer();
        $first      = $normalizer->normalize(
            'search',
            [
                'sort'   => 'date-desc',
                'in_cat' => '12',
                'q'      => 'coffee',
            ],
            'sort=date-desc&in_cat=12&q=coffee'
        );
        $second     = $normalizer->normalize(
            'search',
            [
                'q'      => 'coffee',
                'in_cat' => '12',
                'sort'   => 'date-desc',
            ],
            'q=coffee&in_cat=12&sort=date-desc'
        );

        $this->assertTrue( $first->is_valid() );
        $this->assertTrue( $second->is_valid() );
        $this->assertSame( $first->get_args(), $second->get_args() );
        $this->assertSame( $first->get_hash(), $second->get_hash() );
    }

    public function test_nested_custom_fields_are_canonicalized_without_losing_values() {
        $result = ( new Query_Normalizer() )->normalize(
            'search',
            [
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

    public function test_collection_routes_allow_the_query_arguments_directorist_renders() {
        $result = ( new Query_Normalizer() )->normalize(
            'listings',
            [
                'q'                => 'hotel',
                'in_cat'           => '3,5',
                'in_loc'           => [ '7', '9' ],
                'in_tag'           => '11',
                'directory_type'   => 'travel',
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

        $this->assertTrue( $result->is_valid(), $result->get_reason() );
    }

    public function test_unknown_query_argument_is_rejected() {
        $result = ( new Query_Normalizer() )->normalize(
            'search',
            [
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
        $result = ( new Query_Normalizer() )->normalize(
            'search',
            [ 'in_cat' => [ '4', '8' ] ],
            'in_cat%5B%5D=4&in_cat%5B%5D=8'
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
}
