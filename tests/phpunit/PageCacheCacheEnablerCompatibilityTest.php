<?php
/**
 * Cache Enabler language-cookie compatibility behavior locks.
 */

use Directorist\Cache\Cache_Enabler_Compatibility;
use Directorist\Cache\Cache_Provider;

final class Directorist_Page_Cache_Cache_Enabler_Compatibility_Provider implements Cache_Provider {
    public $invalidations = [];

    public function get_id() {
        return 'cache-enabler';
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

class Directorist_Page_Cache_Cache_Enabler_Compatibility_Test extends WP_UnitTestCase {
    protected function tearDown(): void {
        Cache_Enabler_Compatibility::reset();
        parent::tearDown();
    }

    public function test_activation_preserves_user_regex_and_purges_once_per_configuration() {
        $settings = [ 'excluded_cookies' => '/^session_/' ];
        $writes   = 0;
        $provider = new Directorist_Page_Cache_Cache_Enabler_Compatibility_Provider();
        $compat   = $this->compatibility( $settings, $writes );

        $first   = $compat->activate( $provider );
        $managed = $settings['excluded_cookies'];
        $second  = $compat->activate( $provider );

        $this->assertSame( 'configuration_rebuilt', $first['code'] );
        $this->assertSame( 'configuration_ready', $second['code'] );
        $this->assertStringContainsString( 'session_', $managed );
        $this->assertStringContainsString( Cache_Enabler_Compatibility::MANAGED_MARKER, $managed );
        $this->assertSame( 1, preg_match( $managed, 'pll_language' ) );
        $this->assertSame( 1, preg_match( $managed, 'wp-wpml_current_language' ) );
        $this->assertSame( 1, preg_match( $managed, 'session_private' ) );
        $this->assertSame( 1, $writes );
        $this->assertCount( 1, $provider->invalidations );
    }

    public function test_user_change_is_adopted_without_losing_the_new_rule() {
        $settings = [ 'excluded_cookies' => '/^session_/' ];
        $writes   = 0;
        $provider = new Directorist_Page_Cache_Cache_Enabler_Compatibility_Provider();
        $compat   = $this->compatibility( $settings, $writes );
        $compat->activate( $provider );

        $settings['excluded_cookies'] = '/^customer_private$/';
        $result                       = $compat->activate( $provider );

        $this->assertSame( 'configuration_rebuilt', $result['code'] );
        $this->assertSame( 1, preg_match( $settings['excluded_cookies'], 'customer_private' ) );
        $this->assertSame( 1, preg_match( $settings['excluded_cookies'], 'pll_language' ) );
        $this->assertCount( 2, $provider->invalidations );

        $cleanup = $compat->deactivate();

        $this->assertSame( 'configuration_removed', $cleanup['code'] );
        $this->assertSame( '/^customer_private$/', $settings['excluded_cookies'] );
    }

    public function test_failed_settings_write_never_purges_or_marks_policy_current() {
        $settings = [ 'excluded_cookies' => '' ];
        $provider = new Directorist_Page_Cache_Cache_Enabler_Compatibility_Provider();
        $compat   = $this->compatibility( $settings, $writes, false );
        $result   = $compat->activate( $provider );

        $this->assertSame( 'configuration_write_failed', $result['code'] );
        $this->assertCount( 0, $provider->invalidations );
        $this->assertSame( [], Cache_Enabler_Compatibility::current() );
    }

    private function compatibility( array &$settings, &$writes = 0, $write_success = true ) {
        return new Cache_Enabler_Compatibility(
            [
                'read_settings'     => static function () use ( &$settings ) {
                    return $settings;
                },
                'write_settings'    => static function ( array $next ) use ( &$settings, &$writes, $write_success ) {
                    ++$writes;

                    if ( ! $write_success ) {
                        return false;
                    }

                    $settings = $next;

                    return true;
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
