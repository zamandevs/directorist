<?php
/**
 * WP Fastest Cache language-cookie compatibility behavior locks.
 */

use Directorist\Cache\Cache_Provider;
use Directorist\Cache\WP_Fastest_Cache_Compatibility;

final class Directorist_Page_Cache_WP_Fastest_Compatibility_Provider implements Cache_Provider {
    public $invalidations = [];

    public function get_id() {
        return 'wp-fastest-cache';
    }

    public function is_available() {
        return true;
    }

    public function get_capabilities() {
        return [ 'purge_site' ];
    }

    public function supports( $capability ) {
        return in_array( $capability, $this->get_capabilities(), true );
    }

    public function invalidate( array $request ) {
        $this->invalidations[] = $request;

        return [ 'success' => true, 'code' => 'purged_site' ];
    }

    public function warm( array $urls ) {
        unset( $urls );

        return [ 'success' => true, 'code' => 'unsupported' ];
    }

    public function get_status() {
        return [ 'available' => true ];
    }
}

class Directorist_Page_Cache_WP_Fastest_Cache_Compatibility_Test extends WP_UnitTestCase {
    protected function tearDown(): void {
        WP_Fastest_Cache_Compatibility::reset();
        parent::tearDown();
    }

    public function test_activation_appends_one_managed_cookie_rule_and_preserves_user_rules() {
        $rules    = [ [ 'type' => 'page', 'prefix' => 'contain', 'content' => '/private/' ] ];
        $writes   = 0;
        $refresh  = 0;
        $provider = new Directorist_Page_Cache_WP_Fastest_Compatibility_Provider();
        $compat   = $this->compatibility( $rules, $writes, $refresh );

        $first  = $compat->activate( $provider );
        $second = $compat->activate( $provider );

        $this->assertSame( 'configuration_rebuilt', $first['code'] );
        $this->assertSame( 'configuration_ready', $second['code'] );
        $this->assertSame( '/private/', $rules[0]['content'] );
        $this->assertCount( 2, $rules );
        $this->assertSame( 'cookie', $rules[1]['type'] );
        $this->assertStringContainsString( WP_Fastest_Cache_Compatibility::MANAGED_MARKER, $rules[1]['content'] );
        $this->assertSame( 1, preg_match( '/' . $rules[1]['content'] . '/i', 'pll_language=no' ) );
        $this->assertSame( 1, preg_match( '/' . $rules[1]['content'] . '/i', 'wp-wpml_current_language=sv' ) );
        $this->assertSame( 1, $writes );
        $this->assertSame( 1, $refresh );
        $this->assertCount( 1, $provider->invalidations );
    }

    public function test_user_rules_added_after_activation_survive_resync_and_cleanup() {
        $rules    = [];
        $writes   = 0;
        $refresh  = 0;
        $provider = new Directorist_Page_Cache_WP_Fastest_Compatibility_Provider();
        $compat   = $this->compatibility( $rules, $writes, $refresh );
        $compat->activate( $provider );
        array_unshift( $rules, [ 'type' => 'cookie', 'prefix' => 'contain', 'content' => 'customer_session=' ] );

        $result = $compat->activate( $provider );

        $this->assertSame( 'configuration_rebuilt', $result['code'] );
        $this->assertSame( 'customer_session=', $rules[0]['content'] );
        $this->assertCount( 2, $rules );
        $this->assertCount( 2, $provider->invalidations );

        $cleanup = $compat->deactivate();

        $this->assertSame( 'configuration_removed', $cleanup['code'] );
        $this->assertSame( [ [ 'type' => 'cookie', 'prefix' => 'contain', 'content' => 'customer_session=' ] ], $rules );
    }

    public function test_failed_rule_refresh_never_purges_or_marks_policy_current() {
        $rules    = [];
        $writes   = 0;
        $refresh  = 0;
        $provider = new Directorist_Page_Cache_WP_Fastest_Compatibility_Provider();
        $compat   = $this->compatibility( $rules, $writes, $refresh, false );
        $result   = $compat->activate( $provider );

        $this->assertSame( 'configuration_refresh_failed', $result['code'] );
        $this->assertCount( 0, $provider->invalidations );
        $this->assertSame( [], WP_Fastest_Cache_Compatibility::current() );
    }

    public function test_cleanup_refresh_failure_is_retried_after_the_managed_rule_is_removed() {
        $rules           = [];
        $writes          = 0;
        $refresh         = 0;
        $refresh_success = true;
        $provider        = new Directorist_Page_Cache_WP_Fastest_Compatibility_Provider();
        $compat          = $this->compatibility(
            $rules,
            $writes,
            $refresh,
            static function () use ( &$refresh_success ) {
                return $refresh_success;
            }
        );
        $compat->activate( $provider );

        $refresh_success = false;
        $failed          = $compat->deactivate();

        $this->assertSame( 'configuration_cleanup_failed', $failed['code'] );
        $this->assertSame( [], $rules );
        $this->assertNotSame( [], WP_Fastest_Cache_Compatibility::current() );

        $refresh_success = true;
        $retried         = $compat->deactivate();

        $this->assertSame( 'configuration_removed', $retried['code'] );
        $this->assertSame( 3, $refresh );
        $this->assertSame( [], WP_Fastest_Cache_Compatibility::current() );
    }

    private function compatibility( array &$rules, &$writes, &$refresh, $refresh_success = true ) {
        return new WP_Fastest_Cache_Compatibility(
            [
                'read_rules'        => static function () use ( &$rules ) {
                    return $rules;
                },
                'write_rules'       => static function ( array $next ) use ( &$rules, &$writes ) {
                    ++$writes;
                    $rules = $next;

                    return true;
                },
                'refresh_rules'     => static function () use ( &$refresh, $refresh_success ) {
                    ++$refresh;

                    return is_callable( $refresh_success ) ? (bool) call_user_func( $refresh_success ) : $refresh_success;
                },
                'cookie_policy'     => [ $this, 'cookie_policy' ],
                'warm_after_repair' => static function () {
                    return [ 'success' => true, 'code' => 'unsupported' ];
                },
            ]
        );
    }

    public function cookie_policy() {
        return [
            'vary' => [
                'pll_language'             => [ 'pattern' => '^[a-z]+$', 'max_length' => 8 ],
                'wp-wpml_current_language' => [ 'pattern' => '^[a-z]+$', 'max_length' => 8 ],
            ],
        ];
    }
}
