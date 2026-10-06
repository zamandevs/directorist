<?php

namespace Directorist\Cache;

/**
 * Reads an installed provider's public plugin version from a bounded header.
 */
final class Plugin_Version {
    const MAX_READ_BYTES = 8192;

    /** @var array<string,string> */
    private static $versions = [];

    /**
     * @param string[] $paths Absolute candidate plugin files.
     * @param string   $fallback Version used when no header can be read.
     * @return string
     */
    public static function resolve( array $paths, $fallback = '' ) {
        foreach ( array_values( array_unique( array_filter( array_map( 'strval', $paths ) ) ) ) as $path ) {
            if ( isset( self::$versions[ $path ] ) ) {
                return self::$versions[ $path ];
            }

            $version = self::read( $path );

            if ( '' !== $version ) {
                self::$versions[ $path ] = $version;

                return $version;
            }
        }

        return self::normalize( $fallback );
    }

    /** @return string */
    private static function read( $path ) {
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return '';
        }

        $handle = @fopen( $path, 'rb' );

        if ( false === $handle ) {
            return '';
        }

        $content = fread( $handle, self::MAX_READ_BYTES );
        fclose( $handle );

        if ( false === $content || ! preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $content, $matches ) ) {
            return '';
        }

        return self::normalize( $matches[1] );
    }

    /** @return string */
    private static function normalize( $version ) {
        $version = sanitize_text_field( (string) $version );

        return 64 >= strlen( $version ) ? $version : '';
    }
}
