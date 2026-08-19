<?php

namespace Directorist\Cache;

/**
 * Normalized provider-neutral invalidation request.
 */
final class Invalidation_Plan {
    /** @var int */
    private $site_id;

    /** @var array<string,bool> */
    private $urls = [];

    /** @var array<string,bool> */
    private $dependencies = [];

    /** @var array<string,bool> */
    private $generations = [];

    /** @var bool */
    private $conservative = false;

    /** @var string */
    private $reason = '';

    /**
     * @param int $site_id WordPress blog ID.
     */
    public function __construct( $site_id = 1 ) {
        $this->site_id = max( 1, absint( $site_id ) );
    }

    /**
     * @param string $url Public URL.
     * @return bool
     */
    public function add_url( $url ) {
        $url = esc_url_raw( (string) $url );

        if ( ! $this->is_same_site_url( $url ) ) {
            return false;
        }

        $this->urls[ $url ] = true;

        return true;
    }

    /**
     * @param string     $domain Dependency domain.
     * @param int|string $identifier Optional identifier.
     * @return bool
     */
    public function add_dependency( $domain, $identifier = '' ) {
        return $this->add_key( $this->dependencies, $domain, $identifier );
    }

    /**
     * @param string     $domain Generation domain.
     * @param int|string $identifier Optional identifier.
     * @return bool
     */
    public function add_generation( $domain, $identifier = '' ) {
        return $this->add_key( $this->generations, $domain, $identifier );
    }

    /**
     * Replace exact work with one site-generation invalidation.
     *
     * @param string $reason Stable reason code.
     * @return void
     */
    public function force_conservative( $reason ) {
        $this->urls         = [];
        $this->dependencies = [];
        $this->generations  = [];
        $this->conservative = true;
        $this->reason       = sanitize_key( (string) $reason );

        $this->add_generation( 'site' );
    }

    /** @return array */
    public function to_array() {
        $urls         = array_keys( $this->urls );
        $dependencies = array_keys( $this->dependencies );
        $generations  = array_keys( $this->generations );

        sort( $urls, SORT_STRING );
        sort( $dependencies, SORT_STRING );
        sort( $generations, SORT_STRING );

        return [
            'site_id'      => $this->site_id,
            'urls'         => $urls,
            'dependencies' => $dependencies,
            'generations'  => $generations,
            'conservative' => $this->conservative,
            'reason'       => $this->reason,
        ];
    }

    /**
     * @param array      $target Key set passed by reference.
     * @param string     $domain Key domain.
     * @param int|string $identifier Optional identifier.
     * @return bool
     */
    private function add_key( array &$target, $domain, $identifier ) {
        $key = Dependency_Key::build( $this->site_id, $domain, $identifier );

        if ( '' === $key ) {
            return false;
        }

        $target[ $key ] = true;

        return true;
    }

    /**
     * @param string $url URL to validate.
     * @return bool
     */
    private function is_same_site_url( $url ) {
        $url_parts  = wp_parse_url( $url );
        $home_parts = wp_parse_url( home_url( '/' ) );

        if ( ! is_array( $url_parts ) || ! is_array( $home_parts ) || empty( $url_parts['host'] ) || empty( $home_parts['host'] ) ) {
            return false;
        }

        if ( ! isset( $url_parts['scheme'] ) || ! in_array( strtolower( $url_parts['scheme'] ), [ 'http', 'https' ], true ) ) {
            return false;
        }

        if ( isset( $url_parts['user'] ) || isset( $url_parts['pass'] ) || isset( $url_parts['fragment'] ) ) {
            return false;
        }

        $url_scheme  = strtolower( $url_parts['scheme'] );
        $home_scheme = isset( $home_parts['scheme'] ) ? strtolower( $home_parts['scheme'] ) : '';
        $url_port    = isset( $url_parts['port'] ) ? absint( $url_parts['port'] ) : ( 'https' === $url_scheme ? 443 : 80 );
        $home_port   = isset( $home_parts['port'] ) ? absint( $home_parts['port'] ) : ( 'https' === $home_scheme ? 443 : 80 );

        return $url_scheme === $home_scheme
            && strtolower( $url_parts['host'] ) === strtolower( $home_parts['host'] )
            && $url_port === $home_port;
    }
}
