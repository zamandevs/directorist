<?php

namespace Directorist\Cache;

/**
 * Discovers a bounded set of important Directorist public URLs on demand.
 */
final class Warm_URL_Discovery {
    const MAX_LISTINGS = 50;
    const MAX_TERMS    = 100;
    const MAX_PAGES    = 10;

    /** @var Warm_URL_Registry */
    private $registry;

    /** @var array */
    private $last_limits = [];

    /**
     * @param Warm_URL_Registry|null $registry Request-scoped URL registry.
     */
    public function __construct( Warm_URL_Registry $registry = null ) {
        $this->registry = $registry ?: directorist_page_cache_warm_url_registry();
    }

    /**
     * @param array $args Bounded source limits.
     * @return string[]
     */
    public function discover( array $args = [] ) {
        $this->last_limits = $this->normalize_limits( $args );
        $this->add_configured_pages();
        $this->add_latest_listings( $this->last_limits['listing_limit'] );
        $this->add_terms( $this->last_limits['term_limit'] );
        $this->add_pagination( $this->last_limits['page_limit'] );

        $filtered = apply_filters(
            'directorist_page_cache_discovered_warm_urls',
            $this->registry->all(),
            $this->last_limits,
            $this
        );

        if ( is_array( $filtered ) ) {
            $this->registry->reset();
            $this->registry->add( $filtered, 'discovery' );
        }

        return $this->registry->all();
    }

    /** @return array */
    public function get_last_limits() {
        return $this->last_limits;
    }

    /** @return void */
    private function add_configured_pages() {
        foreach ( $this->configured_pages() as $url ) {
            $this->registry->add( $url, 'configured-pages' );
        }
    }

    /**
     * @param int $limit Maximum listings.
     * @return void
     */
    private function add_latest_listings( $limit ) {
        if ( 1 > $limit ) {
            return;
        }

        $listing_ids = get_posts(
            [
                'post_type'              => ATBDP_POST_TYPE,
                'post_status'            => 'publish',
                'posts_per_page'         => $limit,
                'orderby'                => 'modified',
                'order'                  => 'DESC',
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );

        foreach ( $listing_ids as $listing_id ) {
            $this->registry->add( get_permalink( $listing_id ), 'latest-listings' );
        }
    }

    /**
     * @param int $limit Maximum terms across all Directorist taxonomies.
     * @return void
     */
    private function add_terms( $limit ) {
        if ( 1 > $limit ) {
            return;
        }

        $terms = get_terms(
            [
                'taxonomy'   => [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ],
                'hide_empty' => false,
                'number'     => $limit,
                'orderby'    => 'count',
                'order'      => 'DESC',
            ]
        );

        if ( is_wp_error( $terms ) ) {
            return;
        }

        foreach ( $terms as $term ) {
            $url = get_term_link( $term );

            if ( ! is_wp_error( $url ) ) {
                $this->registry->add( $this->absolute_url( $url ), 'taxonomy-terms' );
            }
        }
    }

    /**
     * @param int $limit Maximum page number, including page one.
     * @return void
     */
    private function add_pagination( $limit ) {
        if ( 2 > $limit ) {
            return;
        }

        foreach ( [ 'listings', 'results' ] as $page_name ) {
            $page_id = directorist_get_page_id( $page_name );
            $url     = $this->published_permalink( $page_id );

            if ( '' === $url ) {
                continue;
            }

            for ( $page = 2; $page <= $limit; ++$page ) {
                $this->registry->add( add_query_arg( 'paged', $page, $url ), 'pagination' );
            }
        }
    }

    /** @return string[] */
    private function configured_pages() {
        $urls = [];

        foreach ( [ 'listings', 'results', 'category', 'location', 'tag', 'author', 'categories', 'locations', 'search' ] as $page_name ) {
            $url = $this->published_permalink( directorist_get_page_id( $page_name ) );

            if ( '' !== $url ) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @param int $page_id Page ID.
     * @return string
     */
    private function published_permalink( $page_id ) {
        $post = get_post( absint( $page_id ) );

        if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
            return '';
        }

        $url = get_permalink( $post );

        return $url ? $url : '';
    }

    /**
     * @param string $url Absolute or root-relative public URL.
     * @return string
     */
    private function absolute_url( $url ) {
        if ( 0 === strpos( $url, '?' ) ) {
            return home_url( '/' ) . $url;
        }

        if ( 0 === strpos( $url, '/' ) ) {
            return home_url( $url );
        }

        return $url;
    }

    /**
     * @param array $args Raw limits.
     * @return array
     */
    private function normalize_limits( array $args ) {
        return [
            'listing_limit' => min( self::MAX_LISTINGS, max( 0, isset( $args['listing_limit'] ) ? absint( $args['listing_limit'] ) : 20 ) ),
            'term_limit'    => min( self::MAX_TERMS, max( 0, isset( $args['term_limit'] ) ? absint( $args['term_limit'] ) : 30 ) ),
            'page_limit'    => min( self::MAX_PAGES, max( 1, isset( $args['page_limit'] ) ? absint( $args['page_limit'] ) : 3 ) ),
        ];
    }
}
