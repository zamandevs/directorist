<?php
/**
 * Generated early config validation tests.
 */

use Directorist\Cache\Built_In\Early_Config;
use PHPUnit\Framework\TestCase;

final class Directorist_Page_Cache_Built_In_Early_Config_Test extends TestCase {
    public function test_only_exact_schema_owner_and_bootstrap_shape_is_valid() {
        $valid = [
            '_marker'        => 'DIRECTORIST PAGE CACHE CONFIG',
            'owner_id'       => 'Owner-ID: directorist-page-cache',
            'schema'         => 1,
            'owner'          => 'directorist-page-cache',
            'bootstrap_file' => '/plugin/includes/cache/built-in/early-bootstrap.php',
        ];

        $this->assertTrue( Early_Config::is_valid( $valid ) );

        $invalid_values = [
            null,
            [],
            array_merge( $valid, [ '_marker' => 'foreign' ] ),
            array_merge( $valid, [ 'owner_id' => 'foreign' ] ),
            array_merge( $valid, [ 'schema' => 2 ] ),
            array_merge( $valid, [ 'owner' => 'foreign' ] ),
            array_merge( $valid, [ 'bootstrap_file' => '' ] ),
            array_merge( $valid, [ 'bootstrap_file' => [ 'invalid' ] ] ),
        ];

        foreach ( $invalid_values as $invalid ) {
            $this->assertFalse( Early_Config::is_valid( $invalid ) );
        }
    }

    public function test_rendered_configuration_is_bounded_non_executable_json() {
        $rendered = ( new Early_Config( '/content', '/plugin' ) )->render();
        $decoded  = json_decode( $rendered, true );

        $this->assertIsArray( $decoded );
        $this->assertTrue( Early_Config::is_valid( $decoded ) );
        $this->assertTrue( $decoded['enabled'] );
        $this->assertSame( 6 * HOUR_IN_SECONDS, $decoded['ttl'] );
        $this->assertSame( DAY_IN_SECONDS, $decoded['refresh_policy']['routes']['listing']['soft_ttl'] );
        $this->assertSame( admin_url( 'admin-ajax.php' ), $decoded['refresh_endpoint'] );
        $this->assertSame( directorist_page_cache_refresh_token(), $decoded['refresh_token'] );
        $this->assertTrue( $decoded['cache_filtered_results'] );
        $this->assertStringNotContainsString( '<?php', $rendered );
        $this->assertLessThan( 32768, strlen( $rendered ) );
    }

    public function test_unencodable_path_does_not_produce_a_configuration() {
        $this->assertSame( '', ( new Early_Config( '/content', "/plugin/\xB1" ) )->render() );
    }

    public function test_generated_configuration_uses_the_filtered_core_cookie_policy() {
        $callback = static function ( $policy ) {
            $policy['reject_prefixes'][] = 'private_extension_';

            return $policy;
        };
        add_filter( 'directorist_page_cache_cookie_policy', $callback );
        $config = ( new Early_Config( '/content', '/plugin' ) )->data();
        remove_filter( 'directorist_page_cache_cookie_policy', $callback );

        $this->assertContains( 'private_extension_', $config['cookie_policy']['reject_prefixes'] );
    }
}
