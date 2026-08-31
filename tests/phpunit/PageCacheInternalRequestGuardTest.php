<?php
/**
 * Internal cache-control request recognition behavior locks.
 */

use Directorist\Cache\Internal_Request_Guard;
use PHPUnit\Framework\TestCase;

final class Directorist_Page_Cache_Internal_Request_Guard_Test extends TestCase {
    public function test_authenticated_cache_workers_are_internal_controls() {
        $verified = [];
        $guard    = new Internal_Request_Guard(
            static function ( $nonce, $action ) use ( &$verified ) {
                $verified[] = [ $nonce, $action ];

                return 'valid-nonce' === $nonce;
            }
        );

        $this->assertTrue(
            $guard->is_control_request(
                [ 'action' => 'wp_7_directorist_page_cache_warm', 'nonce' => 'valid-nonce' ],
                true,
                7
            )
        );
        $this->assertTrue(
            $guard->is_control_request(
                [ 'action' => 'wp_7_directorist_page_cache_cleanup', 'nonce' => 'valid-nonce' ],
                true,
                7
            )
        );
        $this->assertTrue(
            $guard->is_control_request(
                [ 'action' => 'wp_7_directorist_performance_cache_job', 'nonce' => 'valid-nonce' ],
                true,
                7
            )
        );
        $this->assertTrue(
            $guard->is_control_request(
                [ 'action' => 'wp_7_directorist_performance_resource_catalog', 'nonce' => 'valid-nonce' ],
                true,
                7
            )
        );
        $this->assertSame(
            [
                [ 'valid-nonce', 'wp_7_directorist_page_cache_warm' ],
                [ 'valid-nonce', 'wp_7_directorist_page_cache_cleanup' ],
                [ 'valid-nonce', 'wp_7_directorist_performance_cache_job' ],
                [ 'valid-nonce', 'wp_7_directorist_performance_resource_catalog' ],
            ],
            $verified
        );
    }

    public function test_untrusted_or_unrelated_requests_cannot_disable_mutation_tracking() {
        $guard = new Internal_Request_Guard(
            static function () {
                return false;
            }
        );

        $this->assertFalse( $guard->is_control_request( [ 'action' => 'wp_1_directorist_page_cache_warm', 'nonce' => 'invalid' ], true, 1 ) );
        $this->assertFalse( $guard->is_control_request( [ 'action' => 'wp_1_directorist_page_cache_warm', 'nonce' => 'valid' ], false, 1 ) );
        $this->assertFalse( $guard->is_control_request( [ 'action' => 'wp_2_directorist_page_cache_warm', 'nonce' => 'valid' ], true, 1 ) );
        $this->assertFalse( $guard->is_control_request( [ 'action' => 'wp_1_unrelated_worker', 'nonce' => 'valid' ], true, 1 ) );
        $this->assertFalse( $guard->is_control_request( [ 'action' => [ 'invalid' ], 'nonce' => 'valid' ], true, 1 ) );
    }
}
