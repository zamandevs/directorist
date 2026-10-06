<?php
/**
 * Bounded read-only cache inventory behavior locks.
 */

use Directorist\Cache\Built_In\Cache_Inventory;
use PHPUnit\Framework\TestCase;

final class Directorist_Page_Cache_Built_In_Inventory_Test extends TestCase {
    private $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/directorist-core-cache-inventory-' . bin2hex( random_bytes( 6 ) );
        mkdir( $this->root . '/pages/aa/bb', 0777, true );
        mkdir( $this->root . '/generations/cc', 0777, true );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_inventory_counts_complete_entries_orphans_and_generations_without_mutation() {
        $complete = str_repeat( 'a', 64 );
        $orphan   = str_repeat( 'b', 64 );
        file_put_contents( $this->root . '/pages/aa/bb/' . $complete . '.json', '{}' );
        file_put_contents( $this->root . '/pages/aa/bb/' . $complete . '.body', 'body' );
        file_put_contents( $this->root . '/pages/aa/bb/' . $orphan . '.json', '{}' );
        file_put_contents( $this->root . '/generations/cc/' . str_repeat( 'c', 64 ) . '.gen', '1' );

        $status = ( new Cache_Inventory( $this->root ) )->status();

        $this->assertTrue( $status['success'] );
        $this->assertSame( 1, $status['entries'] );
        $this->assertSame( 1, $status['orphans'] );
        $this->assertSame( 1, $status['generations'] );
        $this->assertSame( 9, $status['bytes'] );
        $this->assertFalse( $status['truncated'] );
    }

    public function test_missing_or_symlinked_root_fails_closed() {
        $missing = $this->root . '/missing';
        $this->assertSame( 'cache_root_missing', ( new Cache_Inventory( $missing ) )->status()['code'] );
        $this->assertSame( 0, ( new Cache_Inventory( $missing ) )->status()['bytes'] );

        $link = $this->root . '-link';
        symlink( $this->root, $link );
        $this->assertSame( 'cache_root_symlink', ( new Cache_Inventory( $link ) )->status()['code'] );
        unlink( $link );
    }

    public function test_inventory_never_examines_more_than_the_hard_file_limit() {
        for ( $index = 0; $index <= Cache_Inventory::MAX_FILES; ++$index ) {
            $hash = hash( 'sha256', 'entry-' . $index );
            file_put_contents( $this->root . '/pages/aa/bb/' . $hash . '.json', '{}' );
        }

        $status = ( new Cache_Inventory( $this->root ) )->status();

        $this->assertTrue( $status['truncated'] );
        $this->assertLessThanOrEqual( Cache_Inventory::MAX_FILES, $status['examined'] );
    }

    public function test_inventory_exposes_bounded_entries_groups_and_pagination() {
        $this->write_entry( 'https://example.test/directory/one/', 'listing', 100, 600, 1000 );
        $this->write_entry( 'https://example.test/all-listings/', 'listings', 200, 700, 2000 );
        $this->write_entry( 'https://example.test/directory/two/', 'listing', 300, 800, 3000 );

        $status = ( new Cache_Inventory( $this->root, static function () { return 400; } ) )->status(
            [ 'page' => 1, 'per_page' => 2 ]
        );

        $this->assertSame( 3, $status['entries'] );
        $this->assertSame( 3, $status['cached_entries'] );
        $this->assertSame( 0, $status['inactive_entries'] );
        $this->assertSame( 3, $status['matched_entries'] );
        $this->assertSame( 2, $status['pages'] );
        $this->assertSame( 2, count( $status['items'] ) );
        $this->assertSame( 'https://example.test/directory/two/', $status['items'][0]['canonical_url'] );
        $this->assertSame( 'current', $status['items'][0]['state'] );
        $this->assertSame( 2, $status['groups']['listing']['entries'] );
        $this->assertSame( 1, $status['groups']['listings']['entries'] );

        $second = ( new Cache_Inventory( $this->root, static function () { return 400; } ) )->status(
            [ 'page' => 2, 'per_page' => 2 ]
        );

        $this->assertSame( 1, count( $second['items'] ) );
        $this->assertSame( 'https://example.test/directory/one/', $second['items'][0]['canonical_url'] );
    }

    public function test_inventory_filters_an_exact_canonical_url_before_pagination() {
        for ( $index = 1; $index <= 55; ++$index ) {
            $this->write_entry(
                'https://example.test/directory/item-' . $index . '/',
                'listing',
                100 + $index,
                500,
                600
            );
        }

        $target = ( new Cache_Inventory( $this->root, static function () { return 400; } ) )->status(
            [
                'canonical_url' => 'https://example.test/directory/item-1/',
                'route_type'    => 'listing',
                'page'          => 1,
                'per_page'      => 50,
            ]
        );

        $this->assertTrue( $target['success'] );
        $this->assertSame( 1, $target['matched_entries'] );
        $this->assertCount( 1, $target['items'] );
        $this->assertSame( 'https://example.test/directory/item-1/', $target['items'][0]['canonical_url'] );
    }

    public function test_user_inventory_excludes_expired_and_invalidated_entries_without_hiding_physical_cleanup_counts() {
        $generation = 'directorist:1:listing:20';
        $this->write_generation( $generation, 2 );
        $invalidated_bytes = $this->write_entry( 'https://example.test/directory/current/', 'listing', 300, 800, 3000, [ $generation => 1 ] );
        $expired_bytes     = $this->write_entry( 'https://example.test/directory/expired/', 'listing', 100, 200, 250 );
        $cached_bytes      = $this->write_entry( 'https://example.test/all-listings/', 'listings', 350, 900, 3000 );

        $status = ( new Cache_Inventory( $this->root, static function () { return 400; } ) )->status(
            [ 'route_type' => 'listing', 'page' => 1, 'per_page' => 20 ]
        );

        $this->assertSame( 3, $status['entries'] );
        $this->assertSame( 1, $status['cached_entries'] );
        $this->assertSame( 2, $status['inactive_entries'] );
        $this->assertSame( $cached_bytes, $status['cached_bytes'] );
        $this->assertSame( $invalidated_bytes + $expired_bytes, $status['inactive_bytes'] );
        $this->assertSame( 0, $status['matched_entries'] );
        $this->assertSame( [], $status['items'] );
        $this->assertSame( [ 'listings' ], array_keys( $status['groups'] ) );
    }

    private function write_entry( $url, $route_type, $created_at, $expires_at, $stale_until, array $generations = [] ) {
        $hash      = hash( 'sha256', $url );
        $directory = $this->root . '/pages/' . substr( $hash, 0, 2 ) . '/' . substr( $hash, 2, 2 );
        $body      = 'body-' . $hash;

        if ( ! is_dir( $directory ) ) {
            mkdir( $directory, 0777, true );
        }

        $body_path     = $directory . '/' . $hash . '.body';
        $metadata_path = $directory . '/' . $hash . '.json';

        file_put_contents( $body_path, $body );
        file_put_contents(
            $metadata_path,
            json_encode(
                [
                    'schema'        => 1,
                    'owner'         => 'directorist-page-cache',
                    'request_hash'  => $hash,
                    'canonical_url' => $url,
                    'route_type'    => $route_type,
                    'created_at'    => $created_at,
                    'expires_at'    => $expires_at,
                    'stale_until'   => $stale_until,
                    'body_size'     => strlen( $body ),
                    'generations'   => $generations,
                ]
            )
        );

        return filesize( $body_path ) + filesize( $metadata_path );
    }

    private function write_generation( $dependency, $generation ) {
        $hash      = hash( 'sha256', $dependency );
        $directory = $this->root . '/generations/' . substr( $hash, 0, 2 );

        if ( ! is_dir( $directory ) ) {
            mkdir( $directory, 0777, true );
        }

        file_put_contents( $directory . '/' . $hash . '.gen', (string) $generation );
    }

    private function remove_tree( $path ) {
        if ( is_file( $path ) || is_link( $path ) ) {
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
