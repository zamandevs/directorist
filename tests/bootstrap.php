<?php
/**
 * PHPUnit bootstrap for Directorist behavior-lock tests.
 *
 * These tests load WordPress through wp-phpunit and then load Directorist as
 * the plugin under test. Behavior-lock tests should capture today's public
 * behavior before performance refactors change internals.
 */

if ( ! defined( 'DIRECTORIST_TESTS_PLUGIN_DIR' ) ) {
    define( 'DIRECTORIST_TESTS_PLUGIN_DIR', dirname( __DIR__ ) );
}

if ( ! defined( 'DIRECTORIST_ASSET_PROFILING' ) ) {
    define( 'DIRECTORIST_ASSET_PROFILING', true );
}

$composer_autoload = DIRECTORIST_TESTS_PLUGIN_DIR . '/vendor/autoload.php';
if ( ! file_exists( $composer_autoload ) ) {
    echo 'Composer dependencies are missing. Run `composer install` first.' . PHP_EOL;
    exit( 1 );
}

require_once $composer_autoload;

$wp_phpunit_dir = DIRECTORIST_TESTS_PLUGIN_DIR . '/vendor/wp-phpunit/wp-phpunit';
if ( ! file_exists( $wp_phpunit_dir . '/includes/functions.php' ) ) {
    echo 'wp-phpunit is missing. Run `composer install` first.' . PHP_EOL;
    exit( 1 );
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
    define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', DIRECTORIST_TESTS_PLUGIN_DIR . '/vendor/yoast/phpunit-polyfills' );
}

require_once $wp_phpunit_dir . '/includes/functions.php';

tests_add_filter(
    'muplugins_loaded',
    static function () {
        require_once DIRECTORIST_TESTS_PLUGIN_DIR . '/directorist-base.php';
    }
);

require_once $wp_phpunit_dir . '/includes/bootstrap.php';
