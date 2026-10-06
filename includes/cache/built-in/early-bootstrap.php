<?php
/**
 * Fail-open bootstrap for Directorist's built-in early cache engine.
 */

if ( ! isset( $directorist_page_cache_builtin_config ) || ! is_array( $directorist_page_cache_builtin_config ) ) {
    return false;
}

$GLOBALS['directorist_page_cache_builtin_early_config'] = $directorist_page_cache_builtin_config;

if ( array_key_exists( 'enabled', $directorist_page_cache_builtin_config ) && empty( $directorist_page_cache_builtin_config['enabled'] ) ) {
    return false;
}

$directorist_page_cache_builtin_engine_file = isset( $directorist_page_cache_builtin_config['engine_file'] )
    ? $directorist_page_cache_builtin_config['engine_file']
    : '';

if ( ! is_string( $directorist_page_cache_builtin_engine_file )
    || '' === $directorist_page_cache_builtin_engine_file
    || is_link( $directorist_page_cache_builtin_engine_file )
    || ! is_file( $directorist_page_cache_builtin_engine_file )
    || ! is_readable( $directorist_page_cache_builtin_engine_file )
) {
    return false;
}

try {
    require_once $directorist_page_cache_builtin_engine_file;

    if ( ! function_exists( 'directorist_page_cache_builtin_engine' ) ) {
        return false;
    }

    $directorist_page_cache_builtin_engine = directorist_page_cache_builtin_engine( $directorist_page_cache_builtin_config );

    if ( ! is_object( $directorist_page_cache_builtin_engine ) || ! is_callable( [ $directorist_page_cache_builtin_engine, 'boot_early' ] ) ) {
        return false;
    }

    $directorist_page_cache_builtin_early_result = $directorist_page_cache_builtin_engine->boot_early( $_SERVER, $_COOKIE );

    if ( ! empty( $directorist_page_cache_builtin_early_result['served'] ) ) {
        $directorist_page_cache_builtin_engine->send_response( $directorist_page_cache_builtin_early_result );
        exit;
    }
} catch ( \Throwable $directorist_page_cache_builtin_exception ) {
    unset( $directorist_page_cache_builtin_exception );

    return false;
}

return true;
