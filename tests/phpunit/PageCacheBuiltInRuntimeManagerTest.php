<?php
/**
 * Companion activation/deactivation transaction behavior locks.
 */

use Directorist\Cache\Built_In\Dropin_Installer;
use Directorist\Cache\Built_In\Runtime_Manager;
use Directorist\Cache\Built_In\Atomic_Writer;
use Directorist\Cache\Built_In\WP_Cache_Config;
use PHPUnit\Framework\TestCase;

final class Directorist_Page_Cache_Built_In_Runtime_Manager_Test extends TestCase {
    private $root;

    private $content_dir;

    private $plugin_dir;

    private $wp_config;

    protected function setUp(): void {
        $this->root        = sys_get_temp_dir() . '/directorist-pc05-lifecycle-' . bin2hex( random_bytes( 6 ) );
        $this->content_dir = $this->root . '/wp-content';
        $this->plugin_dir  = $this->content_dir . '/plugins/directorist-page-cache';
        $this->wp_config   = $this->root . '/wp-config.php';
        mkdir( $this->plugin_dir . '/src', 0777, true );
        file_put_contents( $this->plugin_dir . '/src/early-bootstrap.php', '<?php return false;' );
        file_put_contents( $this->wp_config, "<?php\n// WordPress config.\n" );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_foreign_dropin_and_per_site_multisite_activation_make_no_changes() {
        $dropin = $this->content_dir . '/advanced-cache.php';
        file_put_contents( $dropin, '<?php // foreign' );
        $before_config = file_get_contents( $this->wp_config );

        $foreign = $this->manager()->activate( false, false );
        $network = $this->manager()->activate( true, false );

        $this->assertSame( 'foreign_dropin', $foreign['code'] );
        $this->assertSame( 'network_activation_required', $network['code'] );
        $this->assertSame( '<?php // foreign', file_get_contents( $dropin ) );
        $this->assertSame( $before_config, file_get_contents( $this->wp_config ) );
    }

    public function test_activation_and_deactivation_manage_only_owned_files_and_wp_cache_block() {
        $manager = $this->manager();

        $this->assertSame( 'activated', $manager->activate( false, false )['code'] );
        $this->assertFileExists( $this->content_dir . '/advanced-cache.php' );
        $this->assertStringContainsString( 'DIRECTORIST PAGE CACHE WP_CACHE', file_get_contents( $this->wp_config ) );

        $this->assertSame( 'deactivated', $manager->deactivate()['code'] );
        $this->assertFileDoesNotExist( $this->content_dir . '/advanced-cache.php' );
        $this->assertStringNotContainsString( 'DIRECTORIST PAGE CACHE WP_CACHE', file_get_contents( $this->wp_config ) );
    }

    public function test_external_true_wp_cache_definition_is_preserved_on_deactivation() {
        $source = "<?php\ndefine( 'WP_CACHE', true ); // foreign owner\n";
        file_put_contents( $this->wp_config, $source );
        $manager = $this->manager();

        $this->assertSame( 'activated', $manager->activate( false, false )['code'] );
        $this->assertSame( 'deactivated', $manager->deactivate()['code'] );
        $this->assertSame( $source, file_get_contents( $this->wp_config ) );
    }

    public function test_foreign_replacement_after_activation_prevents_any_deactivation_mutation() {
        $manager = $this->manager();
        $manager->activate( false, false );
        $dropin = $this->content_dir . '/advanced-cache.php';
        file_put_contents( $dropin, '<?php // replacement provider' );
        $wp_config_before = file_get_contents( $this->wp_config );

        $result = $manager->deactivate();

        $this->assertSame( 'foreign_dropin', $result['code'] );
        $this->assertSame( '<?php // replacement provider', file_get_contents( $dropin ) );
        $this->assertSame( $wp_config_before, file_get_contents( $this->wp_config ) );
    }

    public function test_dropin_write_failure_rolls_back_generated_config_and_owned_wp_cache_block() {
        $source  = file_get_contents( $this->wp_config );
        $manager = $this->manager( new Directorist_Page_Cache_Built_In_Failing_Dropin_Writer() );

        $result = $manager->activate( false, false );

        $this->assertSame( 'forced_dropin_failure', $result['code'] );
        $this->assertSame( $source, file_get_contents( $this->wp_config ) );
        $this->assertFileDoesNotExist( $this->content_dir . '/advanced-cache.php' );
        $this->assertFileDoesNotExist( $this->content_dir . '/cache/directorist-page-cache/config.json' );
    }

    public function test_cookie_policy_sync_updates_only_owned_runtime_config() {
        $manager = $this->manager();
        $manager->activate( false, false );

        $result = $manager->sync_cookie_policy( [ 'reject_prefixes' => [ 'private_extension_' ] ] );
        $config = json_decode( file_get_contents( $this->content_dir . '/cache/directorist-page-cache/config.json' ), true );

        $this->assertTrue( $result['success'] );
        $this->assertTrue( $result['changed'] );
        $this->assertContains( 'private_extension_', $config['cookie_policy']['reject_prefixes'] );
    }

    private function manager( Atomic_Writer $writer = null ) {
        return new Runtime_Manager(
            new Dropin_Installer( $this->content_dir, $this->plugin_dir, $writer ),
            new WP_Cache_Config( $this->wp_config )
        );
    }

    private function remove_tree( $path ) {
        if ( is_link( $path ) || is_file( $path ) ) {
            unlink( $path );

            return;
        }

        if ( ! is_dir( $path ) ) {
            return;
        }

        foreach ( array_diff( scandir( $path ), [ '.', '..' ] ) as $item ) {
            $this->remove_tree( $path . '/' . $item );
        }

        rmdir( $path );
    }
}

final class Directorist_Page_Cache_Built_In_Failing_Dropin_Writer extends Atomic_Writer {
    public function write( $path, $content ) {
        if ( 'advanced-cache.php' === basename( $path ) ) {
            return [
                'success' => false,
                'code'    => 'forced_dropin_failure',
                'path'    => $path,
            ];
        }

        return parent::write( $path, $content );
    }
}
