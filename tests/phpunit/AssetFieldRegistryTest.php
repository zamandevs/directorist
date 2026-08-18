<?php
/**
 * Behavior-lock tests for field-level asset requirements.
 */

class Directorist_Asset_Field_Registry_Test extends WP_UnitTestCase {
    public function test_plain_search_fields_do_not_require_vendor_assets() {
        $this->assertSame( [], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'search-form/fields/title' ) );
        $this->assertSame( [], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'search-form/fields/tag' ) );
        $this->assertSame( [], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'search-form/fields/review' ) );
        $this->assertSame( [], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'search-form/fields/pricing' ) );
    }

    public function test_search_select_templates_require_select2() {
        $this->assertSame( [ 'select2' ], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'search-form/fields/category' ) );
        $this->assertSame( [ 'select2' ], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'search-form/custom-fields/select' ) );
        $this->assertSame( [ 'select2' ], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'search-form/custom-fields/number/dropdown' ) );
    }

    public function test_search_location_template_switches_between_select2_and_geolocation() {
        $select_assets = \Directorist\Asset_Loader\Asset_Registry::get_field_requirements(
            'search-form/fields/location',
            [
                'data' => [
                    'location_source' => 'from_listing',
                ],
            ]
        );

        $map_assets = \Directorist\Asset_Loader\Asset_Registry::get_field_requirements(
            'search-form/fields/location',
            [
                'data' => [
                    'location_source' => 'from_map_api',
                ],
            ]
        );

        $this->assertSame( [ 'select2' ], $select_assets );
        $this->assertSame( [ 'geolocation' ], $map_assets );
    }

    public function test_listing_form_vendor_fields_require_only_their_vendor_assets() {
        $this->assertSame( [ 'select2' ], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'listing-form/fields/category' ) );
        $this->assertSame( [ 'select2' ], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'listing-form/fields/location' ) );
        $this->assertSame( [ 'select2' ], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'listing-form/fields/tag' ) );
        $this->assertSame( [ 'map' ], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'listing-form/fields/map' ) );
        $this->assertSame( [ 'media-uploader' ], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'listing-form/fields/image_upload' ) );
        $this->assertSame( [ 'plupload' ], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'listing-form/custom-fields/file' ) );
        $this->assertSame( [], \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'listing-form/custom-fields/select' ) );
    }

    public function test_field_assets_are_filterable() {
        $filter = static function ( $assets, $field_type ) {
            if ( 'category' === $field_type ) {
                $assets[] = 'sweetalert';
            }

            return $assets;
        };

        add_filter( 'directorist_field_asset_requirements', $filter, 10, 2 );

        $assets = \Directorist\Asset_Loader\Asset_Registry::get_field_requirements( 'search-form/fields/category' );

        remove_filter( 'directorist_field_asset_requirements', $filter, 10 );

        $this->assertSame( [ 'select2', 'sweetalert' ], $assets );
    }
}
