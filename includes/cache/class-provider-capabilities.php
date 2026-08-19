<?php

namespace Directorist\Cache;

/**
 * Validated provider capability set.
 */
final class Provider_Capabilities {
    const PURGE_DEPENDENCIES = 'purge_dependencies';
    const PURGE_GENERATIONS  = 'purge_generations';
    const PURGE_SITE         = 'purge_site';
    const PURGE_URL          = 'purge_url';
    const PURGE_URLS         = 'purge_urls';
    const WARM_URLS          = 'warm_urls';

    /** @var array<string,bool> */
    private $capabilities = [];

    /**
     * @param string[] $capabilities Candidate capabilities.
     */
    public function __construct( array $capabilities = [] ) {
        $supported = array_flip( self::supported() );

        foreach ( $capabilities as $capability ) {
            $capability = strtolower( trim( (string) $capability ) );

            if ( isset( $supported[ $capability ] ) ) {
                $this->capabilities[ $capability ] = true;
            }
        }
    }

    /** @return string[] */
    public static function supported() {
        return [
            self::PURGE_DEPENDENCIES,
            self::PURGE_GENERATIONS,
            self::PURGE_SITE,
            self::PURGE_URL,
            self::PURGE_URLS,
            self::WARM_URLS,
        ];
    }

    /** @return string[] */
    public function all() {
        $capabilities = array_keys( $this->capabilities );
        sort( $capabilities, SORT_STRING );

        return $capabilities;
    }

    /**
     * @param string $capability Capability identifier.
     * @return bool
     */
    public function supports( $capability ) {
        return isset( $this->capabilities[ strtolower( trim( (string) $capability ) ) ] );
    }
}
