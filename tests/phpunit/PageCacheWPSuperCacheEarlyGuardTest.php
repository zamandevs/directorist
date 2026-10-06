<?php
/**
 * Process-isolated locks for the pre-WordPress WP Super Cache guard.
 */

class Directorist_Page_Cache_WP_Super_Cache_Early_Guard_Test extends WP_UnitTestCase {
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_valid_bounded_language_cookie_keeps_early_cache_enabled() {
        require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/cache/wp-super-cache-early-guard.php';
        $GLOBALS['cache_enabled']                          = true;
        $GLOBALS['directorist_page_cache_wpsc_vary_rules'] = [
            'wp-wpml_current_language' => [ 'pattern' => '^[a-z][a-z0-9_-]{0,31}$', 'max_length' => 32 ],
        ];
        $_COOKIE['wp-wpml_current_language']               = 'sv';

        directorist_page_cache_wpsc_validate_variation_cookies();

        $this->assertTrue( $GLOBALS['cache_enabled'] );
        $this->assertFalse( defined( 'DONOTCACHEPAGE' ) );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_malformed_language_cookie_disables_serving_and_storage_before_wordpress() {
        require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/cache/wp-super-cache-early-guard.php';
        $GLOBALS['cache_enabled']                          = true;
        $GLOBALS['directorist_page_cache_wpsc_vary_rules'] = [
            'wp-wpml_current_language' => [ 'pattern' => '^[a-z][a-z0-9_-]{0,31}$', 'max_length' => 32 ],
        ];
        $_COOKIE['wp-wpml_current_language']               = '%0Ainvalid';

        directorist_page_cache_wpsc_validate_variation_cookies();

        $this->assertFalse( $GLOBALS['cache_enabled'] );
        $this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
    }
}
