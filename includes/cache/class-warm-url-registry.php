<?php

namespace Directorist\Cache;

/**
 * Request-scoped bounded registry for same-origin public warm URLs.
 */
final class Warm_URL_Registry {
    const MAX_URLS = 500;

    /** @var array */
    private $origin;

    /** @var int */
    private $limit;

    /** @var array<string,string> */
    private $urls = [];

    /** @var array<string,int> */
    private $sources = [];

    /**
     * @param string|null $home Site home URL.
     * @param int         $limit Maximum request-scoped URLs.
     */
    public function __construct( $home = null, $limit = self::MAX_URLS ) {
        $this->origin = $this->parse_origin( null === $home ? home_url( '/' ) : $home );
        $this->limit  = min( self::MAX_URLS, max( 1, absint( $limit ) ) );
    }

    /**
     * @param string|string[] $urls Candidate URLs.
     * @param string          $source Stable source identifier.
     * @return int Number of newly accepted URLs.
     */
    public function add( $urls, $source = 'extension' ) {
        $source   = sanitize_key( (string) $source );
        $source   = '' === $source ? 'extension' : $source;
        $accepted = 0;

        foreach ( (array) $urls as $url ) {
            if ( count( $this->urls ) >= $this->limit ) {
                break;
            }

            $normalized = $this->normalize( $url );

            if ( '' === $normalized || isset( $this->urls[ $normalized ] ) ) {
                continue;
            }

            $this->urls[ $normalized ] = $source;
            ++$accepted;
        }

        if ( $accepted ) {
            $this->sources[ $source ] = isset( $this->sources[ $source ] ) ? $this->sources[ $source ] + $accepted : $accepted;
        }

        return $accepted;
    }

    /** @return string[] */
    public function all() {
        return array_keys( $this->urls );
    }

    /** @return array<string,int> */
    public function sources() {
        return $this->sources;
    }

    /** @return void */
    public function reset() {
        $this->urls    = [];
        $this->sources = [];
    }

    /**
     * @param mixed $url Candidate URL.
     * @return string
     */
    private function normalize( $url ) {
        if ( ! is_string( $url ) || '' === $url || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
            return '';
        }

        $parts = wp_parse_url( $url );

        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
            return '';
        }

        $origin = $this->parse_origin( $url );

        if ( empty( $origin ) || $origin !== $this->origin ) {
            return '';
        }

        $scheme = $origin['scheme'];
        $host   = $origin['host'];
        $port   = $origin['port'];
        $path   = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
        $result = $scheme . '://' . $host;

        if ( ( 'https' === $scheme && 443 !== $port ) || ( 'http' === $scheme && 80 !== $port ) ) {
            $result .= ':' . $port;
        }

        $result .= $path;

        if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
            $result .= '?' . $parts['query'];
        }

        return esc_url_raw( $result, [ 'http', 'https' ] );
    }

    /**
     * @param string $url Absolute URL.
     * @return array
     */
    private function parse_origin( $url ) {
        $parts  = wp_parse_url( (string) $url );
        $scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';

        if ( ! is_array( $parts ) || ! in_array( $scheme, [ 'http', 'https' ], true ) || empty( $parts['host'] ) ) {
            return [];
        }

        return [
            'scheme' => $scheme,
            'host'   => strtolower( rtrim( $parts['host'], '.' ) ),
            'port'   => isset( $parts['port'] ) ? absint( $parts['port'] ) : ( 'https' === $scheme ? 443 : 80 ),
        ];
    }
}
