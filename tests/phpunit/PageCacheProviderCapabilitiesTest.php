<?php
/**
 * Provider capability contract tests.
 */

use Directorist\Cache\Provider_Capabilities;

class Directorist_Page_Cache_Provider_Capabilities_Test extends WP_UnitTestCase {
    public function test_capabilities_are_normalized_deduplicated_and_sorted() {
        $capabilities = new Provider_Capabilities(
            [
                Provider_Capabilities::PURGE_SITE,
                ' PURGE_URL ',
                Provider_Capabilities::PURGE_SITE,
                'not-a-capability',
                '',
            ]
        );

        $this->assertSame(
            [
                Provider_Capabilities::PURGE_SITE,
                Provider_Capabilities::PURGE_URL,
            ],
            $capabilities->all()
        );
        $this->assertTrue( $capabilities->supports( Provider_Capabilities::PURGE_URL ) );
        $this->assertFalse( $capabilities->supports( 'not-a-capability' ) );
    }

    public function test_supported_capability_vocabulary_is_stable() {
        $this->assertSame(
            [
                'purge_dependencies',
                'purge_generations',
                'purge_site',
                'purge_url',
                'purge_urls',
                'warm_urls',
            ],
            Provider_Capabilities::supported()
        );
    }
}
