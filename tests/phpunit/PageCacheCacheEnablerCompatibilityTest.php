<?php
/**
 * Cache Enabler language-cookie compatibility behavior locks.
 */

use Directorist\Cache\Cache_Enabler_Compatibility;
use Directorist\Cache\Cache_Provider;

final class Directorist_Page_Cache_Cache_Enabler_Compatibility_Provider implements Cache_Provider {
    public $invalidations = [];
    public $purge_success = true;

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

        return [ 'success' => $this->purge_success, 'code' => 'purged_site' ];
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

    public function test_blank_cookie_setting_retains_effective_native_private_cookie_defaults() {
        $settings = [ 'excluded_cookies' => '' ];
        $writes   = 0;
        $compat   = $this->compatibility( $settings, $writes );
        $compat->activate( new Directorist_Page_Cache_Cache_Enabler_Compatibility_Provider() );

        foreach ( [ 'wordpress_logged_in_test', 'wp-postpass_test', 'comment_author_test', 'pll_language' ] as $cookie ) {
            $this->assertSame( 1, preg_match( $settings['excluded_cookies'], $cookie ), $cookie );
        }
        $this->assertSame( 0, preg_match( $settings['excluded_cookies'], 'ordinary_cookie' ) );
        $compat->deactivate();
        $this->assertSame( '', $settings['excluded_cookies'] );
    }

    public function test_private_paths_preserve_user_exclusions_and_restore_exact_original() {
        $settings = [ 'excluded_cookies' => '', 'excluded_page_paths' => '/^\/customer-private\//' ];
        $writes   = 0;
        $compat   = $this->compatibility( $settings, $writes, true, [ '/submission/', '/account/' ] );
        $provider = new Directorist_Page_Cache_Cache_Enabler_Compatibility_Provider();
        $compat->activate( $provider );
        foreach ( [ '/submission/', '/submission/edit/', '/account/', '/customer-private/one/' ] as $path ) {
            $this->assertSame( 1, preg_match( $settings['excluded_page_paths'], $path ), $path );
        }
        $this->assertSame( 0, preg_match( $settings['excluded_page_paths'], '/all-listings/' ) );
        $compat->activate( $provider );
        $this->assertCount( 1, $provider->invalidations );
        $compat->deactivate();
        $this->assertSame( '/^\/customer-private\//', $settings['excluded_page_paths'] );
        $this->assertSame( '', $settings['excluded_cookies'] );
    }

    public function test_policy_upgrade_purges_once_and_adopts_user_path_edits() {
        $settings = [ 'excluded_cookies' => '' ];
        $writes = 0;
        $provider = new Directorist_Page_Cache_Cache_Enabler_Compatibility_Provider();
        $compat = $this->compatibility( $settings, $writes, true, [ '/account/' ] );
        $compat->activate( $provider );
        $status = Cache_Enabler_Compatibility::current();
        $status['policy_version'] = 1;
        $status['applied_hash'] = 'old-policy';
        update_option( Cache_Enabler_Compatibility::OPTION_NAME, $status );
        $compat->activate( $provider );
        $compat->activate( $provider );
        $this->assertCount( 2, $provider->invalidations );
        $settings['excluded_page_paths'] = '/^\/customer-new\//';
        $compat->activate( $provider );
        $this->assertSame( 1, preg_match( $settings['excluded_page_paths'], '/customer-new/one/' ) );
        $compat->deactivate();
        $this->assertSame( '/^\/customer-new\//', $settings['excluded_page_paths'] );
    }

    public function test_failed_purge_is_retried_without_marking_policy_applied() {
        $settings = [ 'excluded_cookies' => '' ];
        $writes = 0;
        $provider = new Directorist_Page_Cache_Cache_Enabler_Compatibility_Provider();
        $provider->purge_success = false;
        $compat = $this->compatibility( $settings, $writes );
        $this->assertSame( 'configuration_purge_failed', $compat->activate( $provider )['code'] );
        $this->assertSame( '', Cache_Enabler_Compatibility::current()['applied_hash'] );
        $provider->purge_success = true;
        $this->assertSame( 'configuration_rebuilt', $compat->activate( $provider )['code'] );
        $this->assertCount( 2, $provider->invalidations );
    }

    private function compatibility( array &$settings, &$writes = 0, $write_success = true, array $paths = [] ) {
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
                'private_paths'     => static function () use ( $paths ) {
                    return $paths;
                },
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
