<?php
/**
 * Behavior locks for bounded same-origin warm URL registration.
 */

use Directorist\Cache\Warm_URL_Registry;

class Directorist_Page_Cache_Warm_URL_Registry_Test extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        directorist_page_cache_warm_url_registry()->reset();
    }

    protected function tearDown(): void {
        directorist_page_cache_warm_url_registry()->reset();
        parent::tearDown();
    }

    public function test_registry_accepts_only_bounded_same_origin_public_urls() {
        $registry = new Warm_URL_Registry( 'https://example.test/', 3 );
        $accepted = $registry->add(
            [
                'https://example.test/directory/',
                'https://EXAMPLE.test:443/directory/',
                'https://example.test/location/dhaka/?paged=2',
                'https://other.test/private/',
                'https://user@example.test/private/',
                'https://example.test/private/#fragment',
                'javascript:alert(1)',
                'https://example.test/third/',
                'https://example.test/over-limit/',
            ],
            'test'
        );

        $this->assertSame( 3, $accepted );
        $this->assertSame(
            [
                'https://example.test/directory/',
                'https://example.test/location/dhaka/?paged=2',
                'https://example.test/third/',
            ],
            $registry->all()
        );
        $this->assertSame( [ 'test' => 3 ], $registry->sources() );
    }

    public function test_public_registration_api_is_lazy_idempotent_and_filterable() {
        $url = home_url( '/extension-calendar/' );

        $this->assertSame( 1, directorist_page_cache_register_warm_urls( [ $url ], 'booking' ) );
        $this->assertSame( 0, directorist_page_cache_register_warm_urls( [ $url ], 'booking' ) );
        $this->assertTrue( class_exists( Warm_URL_Registry::class, false ) );
        $this->assertContains( $url, directorist_page_cache_warm_url_registry()->all() );
    }
}
