<?php
/**
 * Route-aware cache refresh policy behavior locks.
 */

use Directorist\Cache\Built_In\Refresh_Policy;
use PHPUnit\Framework\TestCase;

final class Directorist_Page_Cache_Built_In_Refresh_Policy_Test extends TestCase {
    public function test_route_policy_and_deterministic_jitter_stay_inside_declared_bounds() {
        $policy = new Refresh_Policy();
        $config = [
            'ttl'            => 60,
            'stale_ttl'      => 30,
            'refresh_policy' => [
                'default' => [ 'soft_ttl' => 100, 'hard_ttl' => 400, 'jitter' => 10 ],
                'routes'  => [ 'listing' => [ 'soft_ttl' => 200, 'hard_ttl' => 800, 'jitter' => 20 ] ],
            ],
        ];
        $hash   = hash( 'sha256', 'listing-key' );
        $first  = $policy->resolve( $config, [ 'route_type' => 'listing' ], $hash );
        $second = $policy->resolve( $config, [ 'route_type' => 'listing' ], $hash );

        $this->assertSame( $first, $second );
        $this->assertGreaterThanOrEqual( 180, $first['soft_ttl'] );
        $this->assertLessThanOrEqual( 220, $first['soft_ttl'] );
        $this->assertSame( 800, $first['hard_ttl'] );
        $this->assertSame( 800 - $first['soft_ttl'], $first['stale_ttl'] );
    }

    public function test_extension_filter_can_narrow_time_sensitive_output_with_normalized_bounds() {
        $callback = static function () {
            return [ 'soft_ttl' => 120, 'hard_ttl' => 300, 'jitter' => 0 ];
        };
        add_filter( 'directorist_page_cache_refresh_policy', $callback );

        try {
            $resolved = ( new Refresh_Policy() )->resolve(
                [ 'ttl' => DAY_IN_SECONDS, 'stale_ttl' => HOUR_IN_SECONDS ],
                [ 'route_type' => 'listing' ],
                hash( 'sha256', 'time-sensitive' )
            );
        } finally {
            remove_filter( 'directorist_page_cache_refresh_policy', $callback );
        }

        $this->assertSame( 120, $resolved['soft_ttl'] );
        $this->assertSame( 300, $resolved['hard_ttl'] );
        $this->assertSame( 180, $resolved['stale_ttl'] );
    }
}
