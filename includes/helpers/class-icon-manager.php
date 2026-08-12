<?php
/**
 * Directorist icon rendering utilities.
 *
 * @author wpWax
 */

namespace Directorist;

use Directorist\Asset_Loader\Asset_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Icon_Manager {
    const MODE_AUTO        = 'auto';
    const MODE_CLASS       = 'class';
    const MODE_LEGACY_MASK = 'legacy_mask';

    const FONT_ICON_STYLE_HANDLE = 'directorist-font-icon-base';

    const FONT_ICON_BASE_CSS = '.directorist-icon--font.directorist-icon-mask::after{content:none!important;display:none!important}';

    protected static $normalized_icons = [];

    protected static $rendered_icons = [];

    protected static $library_handles = [
        'font-awesome' => 'directorist-font-awesome',
        'line-awesome' => 'directorist-line-awesome',
        'unicons'      => 'directorist-unicons',
    ];

    protected static $prefix_libraries = [
        'fa'  => 'font-awesome',
        'fas' => 'font-awesome',
        'far' => 'font-awesome',
        'fab' => 'font-awesome',
        'la'  => 'line-awesome',
        'las' => 'line-awesome',
        'lar' => 'line-awesome',
        'lab' => 'line-awesome',
        'uil' => 'unicons',
    ];

    protected static $missing_css_icons = [
        'font-awesome' => [
            'fab fa-tripadvisor' => true,
        ],
        'unicons'      => [
            'uil uil-exit'     => true,
            'uil uil-home-alt' => true,
        ],
    ];

    /**
     * Render icon HTML.
     *
     * Supported icon fonts are rendered through CSS classes. Unsupported icons
     * keep the legacy SVG mask fallback for compatibility.
     *
     * @param string $icon        Icon classes.
     * @param string $extra_class Extra classes.
     *
     * @return string
     */
    public static function render( $icon, $extra_class = '' ) {
        if ( ! $icon || ! is_string( $icon ) ) {
            return '';
        }

        $mode      = self::render_mode();
        $cache_key = $mode . '|' . $icon . '|' . $extra_class;

        if ( isset( self::$rendered_icons[ $cache_key ] ) ) {
            $normalized = self::normalize( $icon );
            self::notify_rendered( $normalized, self::$rendered_icons[ $cache_key ] );
            return self::$rendered_icons[ $cache_key ];
        }

        $normalized = self::normalize( $icon );

        if ( self::should_render_mask_fallback( $normalized, $extra_class, $mode ) ) {
            self::$rendered_icons[ $cache_key ] = self::render_mask_fallback( $icon, $extra_class );
            self::notify_rendered( $normalized, self::$rendered_icons[ $cache_key ] );
            return self::$rendered_icons[ $cache_key ];
        }

        if ( empty( $normalized['supported'] ) && self::MODE_CLASS !== $mode ) {
            self::$rendered_icons[ $cache_key ] = '';
            self::notify_rendered( $normalized, '' );
            return '';
        }

        if ( ! empty( $normalized['library'] ) ) {
            self::enqueue_style_for_library( $normalized['library'] );
        }

        $icon_classes = ! empty( $normalized['classes'] ) ? $normalized['classes'] : self::sanitize_class_list( $icon );

        $classes = self::sanitize_class_list(
            trim( 'directorist-icon-mask directorist-icon--font ' . $icon_classes . ' ' . $extra_class )
        );

        if ( ! $classes ) {
            return '';
        }

        self::$rendered_icons[ $cache_key ] = sprintf(
            '<i class="%1$s" aria-hidden="true"></i>',
            esc_attr( $classes )
        );

        self::notify_rendered( $normalized, self::$rendered_icons[ $cache_key ] );

        return self::$rendered_icons[ $cache_key ];
    }

    /**
     * Get normalized icon font classes.
     *
     * @param string $icon Icon classes.
     *
     * @return string
     */
    public static function get_icon_classes( $icon ) {
        $normalized = self::normalize( $icon );

        if ( empty( $normalized['supported'] ) || self::requires_missing_css_mask_fallback( $normalized ) ) {
            return '';
        }

        self::enqueue_style_for_library( $normalized['library'] );

        return $normalized['classes'];
    }

    /**
     * Get the current icon render mode.
     *
     * @return string
     */
    public static function render_mode() {
        $mode = defined( 'DIRECTORIST_ICON_RENDER_MODE' ) ? DIRECTORIST_ICON_RENDER_MODE : self::MODE_AUTO;
        $mode = apply_filters( 'directorist_icon_render_mode', $mode );
        $mode = is_string( $mode ) ? sanitize_key( $mode ) : self::MODE_AUTO;

        if ( ! in_array( $mode, [ self::MODE_AUTO, self::MODE_CLASS, self::MODE_LEGACY_MASK ], true ) ) {
            return self::MODE_AUTO;
        }

        return $mode;
    }

    /**
     * Enqueue the icon libraries Directorist core templates commonly need.
     *
     * @param array $libraries Icon library keys.
     *
     * @return void
     */
    public static function enqueue_core_icon_styles( $libraries = [ 'font-awesome', 'line-awesome' ] ) {
        foreach ( $libraries as $library ) {
            self::enqueue_style_for_library( $library );
        }
    }

    /**
     * Normalize icon class input into icon font classes.
     *
     * @param string $icon Icon classes.
     *
     * @return array
     */
    protected static function normalize( $icon ) {
        $icon = trim( preg_replace( '/\s+/', ' ', (string) $icon ) );

        if ( isset( self::$normalized_icons[ $icon ] ) ) {
            return self::$normalized_icons[ $icon ];
        }

        $empty = [
            'raw'         => $icon,
            'classes'     => '',
            'library'     => '',
            'prefix'      => '',
            'name'        => '',
            'missing_css' => false,
            'supported'   => false,
        ];

        if ( '' === $icon ) {
            self::$normalized_icons[ $icon ] = $empty;
            return $empty;
        }

        $tokens = self::sanitize_tokens( explode( ' ', $icon ) );
        $parsed = self::parse_icon_tokens( $tokens );

        if ( empty( $parsed['prefix'] ) || empty( $parsed['name'] ) || ! isset( self::$prefix_libraries[ $parsed['prefix'] ] ) ) {
            self::$normalized_icons[ $icon ] = $empty;
            return $empty;
        }

        $prefix       = $parsed['prefix'];
        $name         = $parsed['name'];
        $library      = self::$prefix_libraries[ $prefix ];
        $icon_name    = self::normalize_icon_name( $prefix, $name );
        $extra_tokens = self::sanitize_class_list( implode( ' ', $parsed['extra'] ) );

        if ( ! $icon_name ) {
            self::$normalized_icons[ $icon ] = $empty;
            return $empty;
        }

        $classes     = trim( $prefix . ' ' . $icon_name . ' ' . $extra_tokens );
        $missing_css = self::is_missing_css_icon( $library, $prefix, $icon_name );

        self::$normalized_icons[ $icon ] = [
            'raw'         => $icon,
            'classes'     => $classes,
            'library'     => $library,
            'prefix'      => $prefix,
            'name'        => $icon_name,
            'missing_css' => $missing_css,
            'supported'   => true,
        ];

        return self::$normalized_icons[ $icon ];
    }

    /**
     * Normalize a single icon name for the selected library prefix.
     *
     * @param string $prefix Icon library prefix.
     * @param string $name   Icon name class.
     *
     * @return string
     */
    protected static function normalize_icon_name( $prefix, $name ) {
        if ( ! $prefix || ! $name ) {
            return '';
        }

        if ( 0 === strpos( $prefix, 'fa' ) && 0 !== strpos( $name, 'fa-' ) ) {
            return 'fa-' . $name;
        }

        if ( 0 === strpos( $prefix, 'la' ) ) {
            if ( preg_match( '/^la[rsb]?-(.+)$/', $name, $matches ) ) {
                return 'la-' . $matches[1];
            }

            if ( 0 === strpos( $name, 'la-' ) ) {
                return $name;
            }

            return 'la-' . $name;
        }

        if ( 'uil' === $prefix && 0 !== strpos( $name, 'uil-' ) ) {
            return 'uil-' . $name;
        }

        return $name;
    }

    /**
     * Check icons that exist as SVG files but are missing from registered icon CSS.
     *
     * @param string $library Icon library key.
     * @param string $prefix  Icon prefix.
     * @param string $name    Normalized icon name.
     *
     * @return bool
     */
    protected static function is_missing_css_icon( $library, $prefix, $name ) {
        $key = $prefix . ' ' . $name;

        return ! empty( self::$missing_css_icons[ $library ][ $key ] );
    }

    /**
     * Determine whether icon output should use legacy SVG mask markup.
     *
     * @param array  $normalized  Normalized icon data.
     * @param string $extra_class  Extra classes.
     * @param string $mode         Render mode.
     *
     * @return bool
     */
    protected static function should_render_mask_fallback( $normalized, $extra_class, $mode ) {
        if ( self::MODE_LEGACY_MASK === $mode ) {
            return true;
        }

        if ( self::MODE_CLASS === $mode ) {
            return false;
        }

        $fallback = empty( $normalized['supported'] ) || self::requires_missing_css_mask_fallback( $normalized ) || ( is_string( $extra_class ) && false !== strpos( $extra_class, 'directorist_fraction_star' ) );

        return (bool) apply_filters( 'directorist_icon_should_render_mask_fallback', $fallback, $normalized, $extra_class, $mode );
    }

    /**
     * Determine whether a known icon should use mask markup because CSS is missing.
     *
     * @param array $normalized Normalized icon data.
     *
     * @return bool
     */
    protected static function requires_missing_css_mask_fallback( $normalized ) {
        if ( empty( $normalized['supported'] ) || empty( $normalized['library'] ) || empty( $normalized['prefix'] ) || empty( $normalized['name'] ) ) {
            return false;
        }

        $key     = $normalized['prefix'] . ' ' . $normalized['name'];
        $missing = ! empty( $normalized['missing_css'] );

        return (bool) apply_filters(
            'directorist_icon_requires_mask_fallback',
            $missing,
            $normalized['library'],
            $normalized['prefix'],
            $normalized['name'],
            $key
        );
    }

    /**
     * Render the legacy SVG mask markup.
     *
     * @param string $icon        Icon classes.
     * @param string $extra_class Extra classes.
     *
     * @return string
     */
    protected static function render_mask_fallback( $icon, $extra_class = '' ) {
        $icon_src = Helper::get_icon_src( $icon );

        if ( ! $icon_src ) {
            return '';
        }

        $class = self::sanitize_class_list( trim( 'directorist-icon-mask ' . $extra_class ) );

        return sprintf(
            '<i class="%1$s" aria-hidden="true" style="--directorist-icon: url(%2$s)"></i>',
            esc_attr( $class ),
            esc_url( $icon_src )
        );
    }

    /**
     * Enqueue a specific icon library if its style handle is registered.
     *
     * @param string $library Icon library key.
     *
     * @return void
     */
    protected static function enqueue_style_for_library( $library ) {
        if ( ! isset( self::$library_handles[ $library ] ) ) {
            return;
        }

        if ( ! function_exists( 'wp_style_is' ) ) {
            return;
        }

        $handle = self::$library_handles[ $library ];

        if ( wp_style_is( $handle, 'registered' ) ) {
            self::enqueue_font_icon_base_style();
            Asset_Manager::require_style( $handle, 'icons', 'icon-render', [ 'library' => $library ] );
        }
    }

    /**
     * Prevent legacy mask CSS from generating a second box for font icons.
     *
     * @return void
     */
    protected static function enqueue_font_icon_base_style() {
        $handle = self::FONT_ICON_STYLE_HANDLE;

        if ( ! wp_style_is( $handle, 'registered' ) ) {
            wp_register_style( $handle, false, [], ATBDP_VERSION );
        }

        $inline_styles = (array) wp_styles()->get_data( $handle, 'after' );

        if ( ! in_array( self::FONT_ICON_BASE_CSS, $inline_styles, true ) ) {
            wp_add_inline_style( $handle, self::FONT_ICON_BASE_CSS );
        }

        Asset_Manager::require_style( $handle, 'icons', 'font-icon-base' );
    }

    /**
     * Notify an opt-in profiler about the icon output path.
     *
     * @param array  $normalized Normalized icon data.
     * @param string $html       Rendered icon markup.
     *
     * @return void
     */
    protected static function notify_rendered( $normalized, $html ) {
        if ( ! defined( 'DIRECTORIST_ASSET_PROFILING' ) || ! DIRECTORIST_ASSET_PROFILING ) {
            return;
        }

        if ( false !== strpos( $html, 'directorist-icon--font' ) ) {
            $outcome = 'class';
        } elseif ( false !== strpos( $html, '--directorist-icon' ) ) {
            $outcome = 'mask';
        } else {
            $outcome = 'missing_src';
        }

        do_action(
            'directorist_icon_rendered',
            [
                'outcome'     => $outcome,
                'missing_css' => ! empty( $normalized['missing_css'] ),
                'unsupported' => empty( $normalized['supported'] ),
            ]
        );
    }

    /**
     * Sanitize a space-separated class list.
     *
     * @param string $classes Class list.
     *
     * @return string
     */
    protected static function sanitize_class_list( $classes ) {
        if ( ! is_string( $classes ) || '' === trim( $classes ) ) {
            return '';
        }

        $sanitized = [];
        $tokens    = preg_split( '/\s+/', trim( $classes ) );

        foreach ( $tokens as $class ) {
            $class = sanitize_html_class( $class );

            if ( $class ) {
                $sanitized[] = $class;
            }
        }

        return implode( ' ', array_unique( $sanitized ) );
    }

    /**
     * Sanitize icon class tokens.
     *
     * @param array $tokens Raw tokens.
     *
     * @return array
     */
    protected static function sanitize_tokens( $tokens ) {
        $sanitized = [];

        foreach ( (array) $tokens as $token ) {
            $token = sanitize_html_class( $token );

            if ( $token ) {
                $sanitized[] = $token;
            }
        }

        return $sanitized;
    }

    /**
     * Parse a class list into icon prefix, icon name, and extra classes.
     *
     * @param array $tokens Sanitized tokens.
     *
     * @return array
     */
    protected static function parse_icon_tokens( $tokens ) {
        $prefix = '';
        $name   = '';
        $extra  = [];

        foreach ( $tokens as $token ) {
            if ( ! $prefix && isset( self::$prefix_libraries[ $token ] ) ) {
                $prefix = $token;
                continue;
            }

            if ( $prefix && ! $name ) {
                $name = $token;
                continue;
            }

            if ( ! $prefix || $name ) {
                $extra[] = $token;
            }
        }

        return [
            'prefix' => $prefix,
            'name'   => $name,
            'extra'  => $extra,
        ];
    }
}
