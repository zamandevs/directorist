<?php
/**
 * Normalized render context for templates and integrations.
 *
 * @author wpWax
 */

namespace Directorist\Asset_Loader;

defined( 'ABSPATH' ) || exit;

class Render_Context {
    protected static $file_context_cache = [];

    protected static $normalized_path_cache = [];

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
        $file_context = self::file_context( $file );

        $defaults = [
            'source'         => $file_context['source'],
            'template'       => is_string( $template ) ? $template : '',
            'file'           => $file,
            'shortcode_key'  => '',
            'block'          => '',
            'extension'      => $file_context['extension'],
            'theme_override' => $file_context['theme_override'],
        ];

        $context = array_merge( $defaults, $context );

        if ( empty( $context['source'] ) || 'unknown' === $context['source'] ) {
            $context['source'] = $file_context['source'];
        }

        if ( empty( $context['extension'] ) ) {
            $context['extension'] = $file_context['extension'];
        }

        $context['theme_override'] = ! empty( $context['theme_override'] ) || $file_context['theme_override'];

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
        $context = self::file_context( $file );
        return $context['source'];
    }

    /**
     * Determine whether file is under the active child or parent theme.
     *
     * @param string $file File path.
     *
     * @return bool
     */
    protected static function is_theme_override( $file ) {
        $context = self::file_context( $file );
        return $context['theme_override'];
    }

    /**
     * Get plugin/extension directory slug from file path.
     *
     * @param string $file File path.
     *
     * @return string
     */
    public static function extension_from_file( $file ) {
        $context = self::file_context( $file );
        return $context['extension'];
    }

    protected static function file_context( $file ) {
        $file = is_string( $file ) ? $file : '';
        $theme_dirs = array_values(
            array_filter(
                [
                    function_exists( 'get_stylesheet_directory' ) ? get_stylesheet_directory() : '',
                    function_exists( 'get_template_directory' ) ? get_template_directory() : '',
                ]
            )
        );
        $core_dir   = defined( 'ATBDP_DIR' ) ? ATBDP_DIR : '';
        $plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '';
        $cache_key  = md5( serialize( [ $file, $theme_dirs, $core_dir, $plugin_dir ] ) );

        if ( isset( self::$file_context_cache[ $cache_key ] ) ) {
            return self::$file_context_cache[ $cache_key ];
        }

        $context = [
            'source'         => 'unknown',
            'extension'      => '',
            'theme_override' => false,
        ];

        if ( ! $file ) {
            self::$file_context_cache[ $cache_key ] = $context;
            return $context;
        }

        foreach ( $theme_dirs as $theme_dir ) {
            if ( self::is_path_within( $file, $theme_dir ) ) {
                $context['source']         = 'theme';
                $context['theme_override'] = true;
                self::$file_context_cache[ $cache_key ] = $context;
                return $context;
            }
        }

        if ( self::is_path_within( $file, $core_dir ) ) {
            $context['source'] = 'core';
            self::$file_context_cache[ $cache_key ] = $context;
            return $context;
        }

        if ( $plugin_dir && self::is_path_within( $file, $plugin_dir ) ) {
            $normalized_plugin_dir = trailingslashit( self::normalize_path( $plugin_dir ) );
            $normalized_file       = self::normalize_path( $file );
            $relative              = ltrim( substr( $normalized_file, strlen( $normalized_plugin_dir ) ), '/' );
            $parts                 = explode( '/', $relative );
            $context['extension']  = sanitize_key( $parts[0] ?? '' );

            if ( $context['extension'] ) {
                $context['source'] = 'extension';
            }
        }

        self::$file_context_cache[ $cache_key ] = $context;
        return $context;
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
        $cache_key = (string) $path;

        if ( array_key_exists( $cache_key, self::$normalized_path_cache ) ) {
            return self::$normalized_path_cache[ $cache_key ];
        }

        $real = realpath( $path );

        if ( $real ) {
            $path = $real;
        }

        self::$normalized_path_cache[ $cache_key ] = str_replace( '\\', '/', rtrim( $path, '/\\' ) );
        return self::$normalized_path_cache[ $cache_key ];
    }
}
