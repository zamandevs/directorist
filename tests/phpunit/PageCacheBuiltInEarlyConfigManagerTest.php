<?php
/**
 * Atomic early-config operational state behavior locks.
 */

use Directorist\Cache\Built_In\Early_Config;
use Directorist\Cache\Built_In\Early_Config_Manager;
use PHPUnit\Framework\TestCase;

final class Directorist_Page_Cache_Built_In_Early_Config_Manager_Test extends TestCase {
    private $root;

    private $path;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/directorist-pc08-config-' . bin2hex( random_bytes( 6 ) );
        $this->path = $this->root . '/config.json';
        mkdir( $this->root, 0777, true );
        file_put_contents( $this->path, ( new Early_Config( $this->root, DIRECTORIST_TESTS_PLUGIN_DIR ) )->render() );
    }

    protected function tearDown(): void {
        if ( is_file( $this->path ) || is_link( $this->path ) ) {
            unlink( $this->path );
        }

        if ( is_dir( $this->root ) ) {
            rmdir( $this->root );
        }
    }

    public function test_owned_config_defaults_enabled_and_updates_atomically() {
        $manager = new Early_Config_Manager( $this->path );

        $this->assertTrue( $manager->status()['enabled'] );
        $disabled = $manager->set_enabled( false );
        $this->assertTrue( $disabled['success'] );
        $this->assertTrue( $disabled['changed'] );
        $this->assertFalse( json_decode( file_get_contents( $this->path ), true )['enabled'] );

        $unchanged = $manager->set_enabled( false );
        $this->assertTrue( $unchanged['success'] );
        $this->assertFalse( $unchanged['changed'] );
    }

    public function test_legacy_owned_config_without_enabled_key_is_treated_as_enabled() {
        $config = json_decode( file_get_contents( $this->path ), true );
        unset( $config['enabled'] );
        file_put_contents( $this->path, json_encode( $config ) );

        $this->assertTrue( ( new Early_Config_Manager( $this->path ) )->status()['enabled'] );
    }

    public function test_cookie_policy_updates_atomically_and_only_when_normalized_policy_changes() {
        $manager = new Early_Config_Manager( $this->path );
        $policy  = [
            'reject_prefixes' => [ 'private_extension_' ],
            'vary'            => [
                'directory_currency' => [
                    'pattern'    => '^[A-Z]{3}$',
                    'max_length' => 3,
                ],
            ],
        ];

        $updated = $manager->set_cookie_policy( $policy );
        $config  = json_decode( file_get_contents( $this->path ), true );

        $this->assertTrue( $updated['success'] );
        $this->assertTrue( $updated['changed'] );
        $this->assertContains( 'private_extension_', $config['cookie_policy']['reject_prefixes'] );
        $this->assertSame( 3, $config['cookie_policy']['vary']['directory_currency']['max_length'] );

        $unchanged = $manager->set_cookie_policy( $policy );
        $this->assertTrue( $unchanged['success'] );
        $this->assertFalse( $unchanged['changed'] );
    }

    public function test_runtime_policy_updates_duration_and_filtered_result_behavior_atomically() {
        $manager = new Early_Config_Manager( $this->path );
        $updated = $manager->set_runtime_policy( 21600, false );
        $config  = json_decode( file_get_contents( $this->path ), true );

        $this->assertTrue( $updated['success'] );
        $this->assertTrue( $updated['changed'] );
        $this->assertSame( 21600, $config['ttl'] );
        $this->assertFalse( $config['cache_filtered_results'] );

        $unchanged = $manager->set_runtime_policy( 21600, false );
        $this->assertTrue( $unchanged['success'] );
        $this->assertFalse( $unchanged['changed'] );
    }

    public function test_missing_foreign_invalid_and_symlink_configs_are_never_rewritten() {
        unlink( $this->path );
        $manager = new Early_Config_Manager( $this->path );
        $this->assertSame( 'config_missing', $manager->set_enabled( false )['code'] );

        file_put_contents( $this->path, '{"owner":"foreign"}' );
        $this->assertSame( 'config_foreign', $manager->set_enabled( false )['code'] );
        $this->assertSame( '{"owner":"foreign"}', file_get_contents( $this->path ) );

        file_put_contents( $this->path, '{"_marker":"DIRECTORIST PAGE CACHE CONFIG","owner_id":"Owner-ID: directorist-page-cache","schema":0}' );
        $this->assertSame( 'config_invalid', $manager->set_enabled( false )['code'] );

        unlink( $this->path );
        $target = $this->root . '/target.json';
        file_put_contents( $target, ( new Early_Config( $this->root, DIRECTORIST_TESTS_PLUGIN_DIR ) )->render() );
        symlink( $target, $this->path );
        $this->assertSame( 'config_symlink', $manager->set_enabled( false )['code'] );
        $this->assertTrue( json_decode( file_get_contents( $target ), true )['enabled'] );
        unlink( $this->path );
        unlink( $target );
    }
}
