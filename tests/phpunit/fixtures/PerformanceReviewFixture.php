<?php

use Directorist\Cache\Built_In\Cache_Engine;

abstract class Directorist_Performance_Review_Fixture extends WP_UnitTestCase {
    protected $root;

    public function set_up() {
        parent::set_up();
        $this->root = sys_get_temp_dir() . '/directorist-independent-review-' . wp_generate_uuid4();
    }

    public function tear_down() {
        $this->remove_tree( $this->root );
        parent::tear_down();
    }

    protected function remove_tree( $path ) {
        if ( is_file( $path ) || is_link( $path ) ) {
            unlink( $path );
            return;
        }
        if ( ! is_dir( $path ) ) {
            return;
        }
        foreach ( array_diff( scandir( $path ), [ '.', '..' ] ) as $entry ) {
            $this->remove_tree( $path . '/' . $entry );
        }
        rmdir( $path );
    }

    protected function cache_nonce_html( $nonce ) {
        $writer = $this->nonce_engine();
        $this->assertTrue( $writer->boot_early( $this->nonce_server(), [] )['regeneration'] );
        $this->assertTrue( $writer->begin_capture()['eligible'] );
        $html = '<html><body><script>window.directorist=' . wp_json_encode( [ 'directorist_nonce' => $nonce ] ) . ';</script></body></html>';
        $this->assertTrue( $writer->finalize_capture( $html, 200, [ 'Content-Type: text/html' ] )['success'] );
    }

    protected function nonce_engine() {
        $descriptor = [ 'eligible' => true, 'site_id' => 1, 'route_type' => 'listing', 'dependencies' => [ 'directorist:1:site' ] ];
        return new Cache_Engine( [ 'cache_dir' => $this->root, 'ttl' => 3600 ], [
            'core_begin' => static function () use ( $descriptor ) { return $descriptor; },
            'core_finish' => static function () use ( $descriptor ) { return $descriptor; },
        ] );
    }

    protected function nonce_server() {
        return [ 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/directory/review-listing/', 'HTTPS' => 'on' ];
    }
}
