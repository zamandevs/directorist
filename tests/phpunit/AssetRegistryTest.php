<?php
/**
 * Behavior-lock tests for Directorist logical asset registry.
 */

class Directorist_Asset_Registry_Test extends WP_UnitTestCase {
    public function test_registry_returns_nested_dependencies_for_listing_slider() {
        $requirements = \Directorist\Asset_Loader\Asset_Registry::get_requirements( 'listing-slider' );

        $this->assertSame( [ 'swiper' ], $requirements['assets'] );
        $this->assertSame( [ 'directorist-listing-slider' ], $requirements['scripts'] );
    }

    public function test_registry_requirements_are_filterable_for_extensions() {
        $filter = static function ( $requirements ) {
            $requirements['extension-fixture'] = [
                'styles'  => [ 'extension-fixture-style' ],
                'scripts' => [ 'extension-fixture-script' ],
            ];

            return $requirements;
        };

        add_filter( 'directorist_renderer_asset_requirements', $filter );

        $requirements = \Directorist\Asset_Loader\Asset_Registry::get_requirements( 'extension-fixture' );

        $this->assertSame( [ 'extension-fixture-style' ], $requirements['styles'] );
        $this->assertSame( [ 'extension-fixture-script' ], $requirements['scripts'] );

        remove_filter( 'directorist_renderer_asset_requirements', $filter );
    }
}
