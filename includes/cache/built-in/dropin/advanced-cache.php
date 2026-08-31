<?php
// DIRECTORIST PAGE CACHE DROPIN
// Owner-ID: directorist-page-cache

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CONTENT_DIR' ) ) {
    return;
}

$directorist_page_cache_builtin_config_file = WP_CONTENT_DIR . '/cache/directorist-page-cache/config.json';

if ( is_link( $directorist_page_cache_builtin_config_file ) || ! is_file( $directorist_page_cache_builtin_config_file ) || ! is_readable( $directorist_page_cache_builtin_config_file ) ) {
    return;
}

$directorist_page_cache_builtin_config_size = filesize( $directorist_page_cache_builtin_config_file );

if ( false === $directorist_page_cache_builtin_config_size || 32768 < $directorist_page_cache_builtin_config_size ) {
    return;
}

$directorist_page_cache_builtin_config_source = file_get_contents( $directorist_page_cache_builtin_config_file );

if ( false === $directorist_page_cache_builtin_config_source ) {
    return;
}

$directorist_page_cache_builtin_config = json_decode( $directorist_page_cache_builtin_config_source, true );

if ( ! is_array( $directorist_page_cache_builtin_config )
    || ! isset( $directorist_page_cache_builtin_config['_marker'], $directorist_page_cache_builtin_config['owner_id'], $directorist_page_cache_builtin_config['schema'], $directorist_page_cache_builtin_config['owner'], $directorist_page_cache_builtin_config['bootstrap_file'] )
    || 'DIRECTORIST PAGE CACHE CONFIG' !== $directorist_page_cache_builtin_config['_marker']
    || 'Owner-ID: directorist-page-cache' !== $directorist_page_cache_builtin_config['owner_id']
    || 1 !== $directorist_page_cache_builtin_config['schema']
    || 'directorist-page-cache' !== $directorist_page_cache_builtin_config['owner']
    || ! is_string( $directorist_page_cache_builtin_config['bootstrap_file'] )
    || '' === $directorist_page_cache_builtin_config['bootstrap_file']
) {
    return;
}

$directorist_page_cache_builtin_bootstrap = $directorist_page_cache_builtin_config['bootstrap_file'];

if ( is_link( $directorist_page_cache_builtin_bootstrap ) || ! is_file( $directorist_page_cache_builtin_bootstrap ) || ! is_readable( $directorist_page_cache_builtin_bootstrap ) ) {
    return;
}

require_once $directorist_page_cache_builtin_bootstrap;
