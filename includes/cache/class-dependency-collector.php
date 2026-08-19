<?php

namespace Directorist\Cache;

/**
 * Request-local set of entities that can make a cached response stale.
 */
final class Dependency_Collector {
    /** @var int */
    private $site_id;

    /** @var array<string,bool> */
    private $dependencies = [];

    /**
     * @param int $site_id WordPress blog ID.
     */
    public function __construct( $site_id = 1 ) {
        $this->site_id = max( 1, absint( $site_id ) );
    }

    /**
     * @param Route_Identity $identity Route identity.
     * @return void
     */
    public function collect_route( Route_Identity $identity ) {
        $route_type = $identity->get_route_type();

        $this->add( 'site' );
        $this->add( 'route', $route_type );
        $this->add( 'settings' );
        $this->add( 'template' );

        if ( in_array( $route_type, [ 'listings', 'search', 'category', 'location', 'tag', 'author', 'categories', 'locations', 'search-form', 'embedded' ], true ) ) {
            $this->add( 'collection', 'listings' );
        }

        if ( 'listing' === $route_type ) {
            $this->add( 'listing', $identity->get_object_id() );
        } elseif ( in_array( $route_type, [ 'category', 'location', 'tag' ], true ) ) {
            $this->add( 'term', $identity->get_object_id() );
        } elseif ( 'author' === $route_type ) {
            $this->add( 'author', $identity->get_object_id() );
        }

        if ( $identity->get_page_id() ) {
            $this->add( 'page', $identity->get_page_id() );
        }

        foreach ( [ 'directory', 'term', 'author' ] as $type ) {
            foreach ( $identity->get_entity_ids( $type ) as $id ) {
                $this->add( $type, $id );
            }
        }

        if ( $identity->get_language() ) {
            $this->add( 'language', $identity->get_language() );
        }

        /**
         * Fires after core route dependencies are collected.
         *
         * @param Dependency_Collector $collector Request-local collector.
         * @param Route_Identity       $identity Route identity.
         */
        do_action( 'directorist_page_cache_collect_dependencies', $this, $identity );
    }

    /**
     * @param string     $domain Dependency domain.
     * @param int|string $identifier Optional identifier.
     * @return bool
     */
    public function add( $domain, $identifier = '' ) {
        $domain = sanitize_key( (string) $domain );

        if ( '' === $domain ) {
            return false;
        }

        $parts = [ 'directorist', (string) $this->site_id, $domain ];

        if ( '' !== (string) $identifier ) {
            foreach ( explode( ':', (string) $identifier ) as $part ) {
                $part = sanitize_key( $part );

                if ( '' !== $part ) {
                    $parts[] = $part;
                }
            }
        }

        $key = implode( ':', $parts );

        if ( count( $parts ) < 3 ) {
            return false;
        }

        $this->dependencies[ $key ] = true;

        return true;
    }

    /**
     * @param string     $extension Extension slug.
     * @param int|string $identifier Extension-owned identifier.
     * @return bool
     */
    public function add_extension( $extension, $identifier = '' ) {
        $extension = sanitize_key( (string) $extension );

        if ( '' === $extension ) {
            return false;
        }

        $identifier = '' === (string) $identifier ? $extension : $extension . ':' . $identifier;

        return $this->add( 'extension', $identifier );
    }

    /** @return string[] */
    public function all() {
        $dependencies = array_keys( $this->dependencies );
        sort( $dependencies, SORT_STRING );

        return $dependencies;
    }
}
