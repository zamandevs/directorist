<?php
/**
 * Provider drop-in ownership tests.
 */

use Directorist\Cache\Dropin_Owner_Detector;

class Directorist_Page_Cache_Dropin_Owner_Detector_Test extends WP_UnitTestCase {
    public function test_known_dropin_fingerprints_are_detected_without_execution() {
        $detector = new Dropin_Owner_Detector( '', false );

        $this->assertSame( 'wp-super-cache', $detector->detect_content( '<?php // WP SUPER CACHE; WPCACHEHOME' ) );
        $this->assertSame( 'cache-enabler', $detector->detect_content( '<?php $cache_enabler_constants_file = true; CACHE_ENABLER_DIR;' ) );
        $this->assertSame( 'wp-rocket', $detector->detect_content( '<?php define( "WP_ROCKET_PATH", "/plugin/" );' ) );
        $this->assertSame( 'directorist-cache', $detector->detect_content( '<?php // DIRECTORIST PAGE CACHE DROPIN' ) );
    }

    public function test_missing_empty_and_unrecognized_dropins_have_distinct_results() {
        $missing = new Dropin_Owner_Detector( '/path/that/does/not/exist.php', false );
        $empty   = new Dropin_Owner_Detector( $this->make_dropin( '' ), false );
        $unknown = new Dropin_Owner_Detector( $this->make_dropin( '<?php echo "third party";' ), false );

        $this->assertSame( 'none', $missing->detect() );
        $this->assertSame( 'unknown', $empty->detect() );
        $this->assertSame( 'unknown', $unknown->detect() );
    }

    public function test_detection_reads_only_the_bounded_prefix() {
        $content  = str_repeat( 'x', Dropin_Owner_Detector::MAX_READ_BYTES );
        $content .= 'WPCACHEHOME';
        $detector = new Dropin_Owner_Detector( $this->make_dropin( $content ), false );

        $this->assertSame( 'unknown', $detector->detect() );
    }

    public function test_owner_filter_can_declare_a_custom_dropin() {
        $callback = static function ( $owner, $content ) {
            return false !== strpos( $content, 'ACME_CACHE_OWNER' ) ? 'acme-cache' : $owner;
        };

        add_filter( 'directorist_page_cache_dropin_owner', $callback, 10, 2 );
        $detector = new Dropin_Owner_Detector( '', false );

        $this->assertSame( 'acme-cache', $detector->detect_content( '<?php // ACME_CACHE_OWNER' ) );

        remove_filter( 'directorist_page_cache_dropin_owner', $callback, 10 );
    }

    public function test_persistent_classification_changes_only_after_lifecycle_invalidation() {
        $path = $this->make_dropin( '<?php // WP SUPER CACHE' );

        Dropin_Owner_Detector::invalidate_persistent_cache();
        $this->assertSame( 'wp-super-cache', ( new Dropin_Owner_Detector( $path ) )->detect() );

        file_put_contents( $path, '<?php // CACHE_ENABLER_DIR' );
        $this->assertSame( 'wp-super-cache', ( new Dropin_Owner_Detector( $path ) )->detect() );

        directorist_page_cache_flush_provider_detection();
        $this->assertSame( 'cache-enabler', ( new Dropin_Owner_Detector( $path ) )->detect() );

        Dropin_Owner_Detector::invalidate_persistent_cache();
    }

    private function make_dropin( $content ) {
        $path = wp_tempnam( 'directorist-dropin.php' );
        file_put_contents( $path, $content );

        return $path;
    }
}
