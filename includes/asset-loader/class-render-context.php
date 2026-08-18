<?php
/**
 * Normalized render context for templates and integrations.
 *
 * @author wpWax
 */

namespace Directorist\Asset_Loader;

defined( 'ABSPATH' ) || exit;

class Render_Context {
    /**
     * Build a normalized render context.
     *
     * @param string $template Template key.
     * @param string $file     Resolved file path.
     * @param array  $context  Extra context.
     *
     * @return array
     */
    protected static function normalize( $template, $file = '', $context = [] ) {
        $context = is_array( $context ) ? $context : [];
        $file    = is_string( $file ) ? $file : '';

        $defaults = [
            'source'         => self::source_from_file( $file ),
            'template'       => is_string( $template ) ? $template : '',
            'file'           => $file,
            'shortcode_key'  => '',
            'block'          => '',
            'extension'      => self::extension_from_file( $file ),
            'theme_override' => self::is_theme_override( $file ),
        ];

        $context = array_merge( $defaults, $context );

        if ( empty( $context['source'] ) || 'unknown' === $context['source'] ) {
            $context['source'] = self::source_from_file( $file );
        }

        if ( empty( $context['extension'] ) ) {
            $context['extension'] = self::extension_from_file( $file );
        }

        $context['theme_override'] = ! empty( $context['theme_override'] ) || self::is_theme_override( $file );

        return $context;
    }

    /**
     * Fire the normalized before-template hook.
     *
     * @param string $template Template key.
     * @param string $file     Resolved file path.
     * @param array  $args     Template args.
     * @param array  $context  Extra context.
     *
     * @return array Normalized context.
     */
    public static function before( $template, $file = '', $args = [], $context = [] ) {
        $context = self::normalize( $template, $file, $context );

        do_action( 'directorist_before_template_render', $template, $file, $args, $context );

        return $context;
    }

    /**
     * Fire the normalized after-template hook.
     *
     * @param string $template Template key.
     * @param string $file     Resolved file path.
     * @param array  $args     Template args.
     * @param array  $context  Extra context.
     *
     * @return void
     */
    public static function after( $template, $file = '', $args = [], $context = [] ) {
        $context = self::normalize( $template, $file, $context );

        do_action( 'directorist_after_template_render', $template, $file, $args, $context );
    }

    /**
     * Detect source type from resolved path.
     *
     * @param string $file File path.
     *
     * @return string
     */
    protected static function source_from_file( $file ) {
        if ( ! $file ) {
            return 'unknown';
        }

        if ( self::is_theme_override( $file ) ) {
            return 'theme';
        }

        if ( self::is_path_within( $file, defined( 'ATBDP_DIR' ) ? ATBDP_DIR : '' ) ) {
            return 'core';
        }

        if ( self::extension_from_file( $file ) ) {
            return 'extension';
        }

        return 'unknown';
    }

    /**
     * Determine whether file is under the active child or parent theme.
     *
     * @param string $file File path.
     *
     * @return bool
     */
    protected static function is_theme_override( $file ) {
        if ( ! $file ) {
            return false;
        }

        $theme_dirs = array_filter(
            [
                function_exists( 'get_stylesheet_directory' ) ? get_stylesheet_directory() : '',
                function_exists( 'get_template_directory' ) ? get_template_directory() : '',
            ]
        );

        foreach ( $theme_dirs as $theme_dir ) {
            if ( self::is_path_within( $file, $theme_dir ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get plugin/extension directory slug from file path.
     *
     * @param string $file File path.
     *
     * @return string
     */
    public static function extension_from_file( $file ) {
        if ( ! $file || ! defined( 'WP_PLUGIN_DIR' ) || self::is_path_within( $file, defined( 'ATBDP_DIR' ) ? ATBDP_DIR : '' ) ) {
            return '';
        }

        $plugin_dir = self::normalize_path( WP_PLUGIN_DIR );
        $file_path  = self::normalize_path( $file );

        if ( 0 !== strpos( $file_path, trailingslashit( $plugin_dir ) ) ) {
            return '';
        }

        $relative = ltrim( substr( $file_path, strlen( trailingslashit( $plugin_dir ) ) ), '/' );
        $parts    = explode( '/', $relative );

        return sanitize_key( $parts[0] ?? '' );
    }

    protected static function is_path_within( $file, $directory ) {
        if ( ! $file || ! $directory ) {
            return false;
        }

        $file      = self::normalize_path( $file );
        $directory = trailingslashit( self::normalize_path( $directory ) );

        return 0 === strpos( $file, $directory );
    }

    protected static function normalize_path( $path ) {
        $real = realpath( $path );

        if ( $real ) {
            $path = $real;
        }

        return str_replace( '\\', '/', rtrim( $path, '/\\' ) );
    }
}
