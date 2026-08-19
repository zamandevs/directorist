<?php

namespace Directorist\Cache;

/**
 * Classifies advanced-cache.php without executing third-party code.
 */
class Dropin_Owner_Detector {
    const CACHE_OPTION   = 'directorist_page_cache_dropin_owner_v1';
    const MAX_READ_BYTES = 65536;

    /** @var string */
    private $path;

    /** @var bool */
    private $use_persistent_cache;

    /**
     * @param string|null $path Drop-in path.
     * @param bool        $use_persistent_cache Whether to cache classification.
     */
    public function __construct( $path = null, $use_persistent_cache = true ) {
        $this->path                 = null === $path ? WP_CONTENT_DIR . '/advanced-cache.php' : (string) $path;
        $this->use_persistent_cache = (bool) $use_persistent_cache;
    }

    /** @return string */
    public function detect() {
        if ( ! is_file( $this->path ) ) {
            return 'none';
        }

        if ( $this->use_persistent_cache ) {
            $cached = get_site_option( self::CACHE_OPTION, [] );

            if ( is_array( $cached ) && isset( $cached['path'], $cached['owner'] ) && $this->path === $cached['path'] ) {
                return $this->normalize_owner( $cached['owner'] );
            }
        }

        $content = $this->read_prefix();
        $owner   = $this->detect_content( $content );

        if ( $this->use_persistent_cache ) {
            update_site_option(
                self::CACHE_OPTION,
                [
                    'path'  => $this->path,
                    'owner' => $owner,
                ]
            );
        }

        return $owner;
    }

    /**
     * @param string $content Bounded drop-in source prefix.
     * @return string
     */
    public function detect_content( $content ) {
        $content = (string) $content;
        $owner   = 'unknown';

        if ( false !== stripos( $content, 'WPCACHEHOME' ) || false !== stripos( $content, 'WP SUPER CACHE' ) ) {
            $owner = 'wp-super-cache';
        } elseif ( false !== stripos( $content, 'cache_enabler_constants_file' ) || false !== stripos( $content, 'CACHE_ENABLER_DIR' ) ) {
            $owner = 'cache-enabler';
        } elseif ( false !== stripos( $content, 'WP_ROCKET_PATH' ) || false !== stripos( $content, 'WP Rocket' ) ) {
            $owner = 'wp-rocket';
        } elseif ( false !== stripos( $content, 'DIRECTORIST PAGE CACHE DROPIN' ) ) {
            $owner = 'directorist-cache';
        }

        $owner = apply_filters( 'directorist_page_cache_dropin_owner', $owner, $content, $this->path );

        return $this->normalize_owner( $owner );
    }

    /** @return void */
    public static function invalidate_persistent_cache() {
        delete_site_option( self::CACHE_OPTION );
    }

    /** @return string */
    private function read_prefix() {
        $handle = @fopen( $this->path, 'rb' );

        if ( false === $handle ) {
            return '';
        }

        $content = fread( $handle, self::MAX_READ_BYTES );
        fclose( $handle );

        return false === $content ? '' : $content;
    }

    /**
     * @param string $owner Owner identifier.
     * @return string
     */
    private function normalize_owner( $owner ) {
        $owner = strtolower( trim( (string) $owner ) );

        if ( in_array( $owner, [ 'none', 'unknown' ], true ) ) {
            return $owner;
        }

        return preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $owner ) ? $owner : 'unknown';
    }
}
