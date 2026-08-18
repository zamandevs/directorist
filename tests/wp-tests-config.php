<?php
/**
 * Local WordPress test-suite configuration.
 *
 * Values can be overridden with environment variables so credentials are not
 * committed to the repository.
 */

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'directorist_tests' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = getenv( 'WP_PHPUNIT__TABLE_PREFIX' ) ?: 'wptests_';

define( 'WP_TESTS_DOMAIN', getenv( 'WP_TESTS_DOMAIN' ) ?: 'directorist.test' );
define( 'WP_TESTS_EMAIL', getenv( 'WP_TESTS_EMAIL' ) ?: 'admin@example.org' );
define( 'WP_TESTS_TITLE', getenv( 'WP_TESTS_TITLE' ) ?: 'Directorist Tests' );
define( 'WP_PHP_BINARY', PHP_BINARY );

$wordpress_path = getenv( 'WP_TESTS_WORDPRESS_PATH' ) ?: dirname( __DIR__, 4 ) . '/';
$wordpress_path = rtrim( $wordpress_path, '/\\' ) . '/';

define( 'ABSPATH', $wordpress_path );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_TESTS_FORCE_KNOWN_BUGS', false );
