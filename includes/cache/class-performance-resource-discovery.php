<?php

namespace Directorist\Cache;

/**
 * Bounded discovery of canonical public Directorist resources.
 */
final class Performance_Resource_Discovery {
    const PHASES = [ 'listing', 'page', 'archive', 'search' ];

    /** @var Performance_Translation_Resolver */
    private $translations;

    /** @var Route_Resolver */
    private $routes;

    public function __construct( Performance_Translation_Resolver $translations = null, Route_Resolver $routes = null ) {
        $this->translations = $translations ?: new Performance_Translation_Resolver();
        $this->routes       = $routes ?: new Route_Resolver();
    }

    /** @return array */
    public function batch( $phase, $cursor, $limit ) {
        $phase  = sanitize_key( (string) $phase );
        $cursor = max( 0, (int) $cursor );
        $limit  = min( 200, max( 1, (int) $limit ) );

        if ( 'listing' === $phase ) {
            return $this->listing_batch( $cursor, $limit );
        }

        if ( 'page' === $phase ) {
            return $this->page_batch( $cursor, $limit );
        }

        if ( 'archive' === $phase ) {
            return $this->archive_batch( $cursor, $limit );
        }

        if ( 'search' === $phase ) {
            return [ 'items' => 0 === $cursor ? $this->search_resources() : [], 'next_cursor' => 1, 'done' => true ];
        }

        return [ 'items' => [], 'next_cursor' => $cursor, 'done' => true ];
    }

    private function listing_batch( $cursor, $limit ) {
        $query = $this->post_query(
            [
                'post_type'              => ATBDP_POST_TYPE,
                'post_status'            => 'publish',
                'posts_per_page'         => $limit,
                'paged'                  => $cursor + 1,
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'fields'                 => 'ids',
                'suppress_filters'       => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => true,
            ]
        );
        $items = [];

        foreach ( $query->posts as $listing_id ) {
            $identity = $this->translations->post( $listing_id, ATBDP_POST_TYPE );
            $url      = $this->localized_post_url( $listing_id, $identity['language'] );

            if ( ! $this->is_public_url( $url ) ) {
                continue;
            }

            $items[] = [
                'logical_key'   => $identity['logical_key'],
                'language'      => $identity['language'],
                'title'         => get_the_title( $listing_id ),
                'url'           => $url,
                'type'          => 'listing',
                'route_type'    => 'listing',
                'object_id'     => (int) $listing_id,
                'modified_at'   => (int) get_post_modified_time( 'U', true, $listing_id ),
                'directory_ids' => $this->post_term_ids( $listing_id, ATBDP_DIRECTORY_TYPE ),
                'category_ids'  => $this->post_term_ids( $listing_id, ATBDP_CATEGORY ),
                'location_ids'  => $this->post_term_ids( $listing_id, ATBDP_LOCATION ),
            ];
        }

        return [
            'items'       => $items,
            'next_cursor' => $cursor + 1,
            'done'        => $cursor + 1 >= max( 1, (int) $query->max_num_pages ),
        ];
    }

    private function page_batch( $cursor, $limit ) {
        $query = $this->post_query(
            [
                'post_type'              => 'page',
                'post_status'            => 'publish',
                'posts_per_page'         => $limit,
                'paged'                  => $cursor + 1,
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'suppress_filters'       => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
            ]
        );
        $items = [];

        foreach ( $query->posts as $page ) {
            $identity = $this->translations->post( $page->ID, 'page' );
            $route    = $this->routes->resolve_page( $page, $identity['language'] );

            if ( ! $route instanceof Route_Identity ) {
                continue;
            }

            $route_type = $route->get_route_type();
            $url        = $this->localized_post_url( $page->ID, $identity['language'] );

            if ( ! $this->is_public_url( $url ) ) {
                continue;
            }

            $items[] = [
                'logical_key' => $identity['logical_key'],
                'language'    => $identity['language'],
                'title'       => get_the_title( $page ),
                'url'         => $url,
                'type'        => in_array( $route_type, [ 'search', 'search-form' ], true ) ? 'search' : 'page',
                'route_type'  => $route_type,
                'object_id'   => (int) $page->ID,
                'modified_at' => (int) get_post_modified_time( 'U', true, $page ),
            ];
        }

        if ( 0 === $cursor ) {
            $registered = apply_filters( 'directorist_performance_cache_registered_resources', [], $this );

            if ( is_array( $registered ) ) {
                $items = array_merge( $items, $registered );
            }
        }

        return [
            'items'       => $items,
            'next_cursor' => $cursor + 1,
            'done'        => $cursor + 1 >= max( 1, (int) $query->max_num_pages ),
        ];
    }

    private function archive_batch( $cursor, $limit ) {
        $taxonomies = [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ];
        $language   = has_filter( 'wpml_current_language' ) ? apply_filters( 'wpml_current_language', null ) : null;

        if ( has_action( 'wpml_switch_language' ) ) {
            do_action( 'wpml_switch_language', 'all' );
        }

        try {
            $terms = get_terms(
                [
                    'taxonomy'   => $taxonomies,
                    'hide_empty' => false,
                    'number'     => $limit,
                    'offset'     => $cursor * $limit,
                    'orderby'    => 'term_id',
                    'order'      => 'ASC',
                ]
            );
        } finally {
            if ( has_action( 'wpml_switch_language' ) ) {
                do_action( 'wpml_switch_language', $language );
            }
        }
        $terms = is_wp_error( $terms ) || ! is_array( $terms ) ? [] : $terms;
        $items = [];

        foreach ( $terms as $term ) {
            $url = get_term_link( $term );

            if ( is_wp_error( $url ) || ! $this->is_public_url( $url ) ) {
                continue;
            }

            $identity   = $this->translations->term( $term );
            $route_type = ATBDP_LOCATION === $term->taxonomy ? 'location' : ( ATBDP_TAGS === $term->taxonomy ? 'tag' : 'category' );
            $items[]    = [
                'logical_key' => $identity['logical_key'],
                'language'    => $identity['language'],
                'title'       => $term->name,
                'url'         => $url,
                'type'        => 'archive',
                'route_type'  => $route_type,
                'object_id'   => (int) $term->term_id,
                'modified_at' => 0,
            ];
        }

        return [ 'items' => $items, 'next_cursor' => $cursor + 1, 'done' => count( $terms ) < $limit ];
    }

    private function search_resources() {
        $url = $this->search_page_url();

        if ( ! is_string( $url ) || '' === $url ) {
            $url = home_url( '/' );
        }

        if ( ! $this->is_public_url( $url ) ) {
            return [];
        }

        $resources = [
            [
                'logical_key' => 'search-results',
                'language'    => has_filter( 'wpml_current_language' ) ? sanitize_key( (string) apply_filters( 'wpml_current_language', null ) ) : '',
                'title'       => __( 'Search results', 'directorist' ),
                'url'         => $url,
                'type'        => 'search',
                'route_type'  => 'search',
                'object_id'   => absint( get_directorist_option( 'search_result_page' ) ),
                'modified_at' => 0,
            ],
        ];
        $languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
        $current   = has_filter( 'wpml_current_language' ) ? apply_filters( 'wpml_current_language', null ) : null;

        try {
            foreach ( is_array( $languages ) ? $languages : [] as $language => $details ) {
                if ( has_action( 'wpml_switch_language' ) ) {
                    do_action( 'wpml_switch_language', $language );
                }

                $language_url = $this->wpml_search_page_url( $language, is_array( $details ) ? $details : [] );

                if ( $this->is_public_url( $language_url ) && ! $this->contains_url( $resources, $language_url ) ) {
                    $resources[] = array_merge( $resources[0], [ 'url' => $language_url, 'language' => sanitize_key( (string) $language ) ] );
                }
            }
        } finally {
            if ( has_action( 'wpml_switch_language' ) ) {
                do_action( 'wpml_switch_language', $current );
            }
        }

        if ( function_exists( 'pll_languages_list' ) ) {
            $page_id = absint( get_directorist_option( 'search_result_page' ) );

            foreach ( (array) pll_languages_list( [ 'fields' => 'slug' ] ) as $language ) {
                $language     = sanitize_key( (string) $language );
                $language_url = '';

                if ( 0 < $page_id && function_exists( 'pll_get_post' ) ) {
                    $translation_id = absint( pll_get_post( $page_id, $language ) );
                    $language_url   = 0 < $translation_id ? get_permalink( $translation_id ) : '';
                } elseif ( function_exists( 'pll_home_url' ) ) {
                    $language_url = pll_home_url( $language );
                }

                if ( $this->is_public_url( $language_url ) && ! $this->contains_url( $resources, $language_url ) ) {
                    $resources[] = array_merge( $resources[0], [ 'url' => $language_url, 'language' => $language ] );
                }
            }
        }

        $registered = apply_filters( 'directorist_performance_cache_search_resources', $resources, $this );

        return is_array( $registered ) ? $registered : $resources;
    }

    private function search_page_url() {
        return class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_search_result_page_link() : home_url( '/' );
    }

    private function wpml_search_page_url( $language, array $details ) {
        $page_id = absint( get_directorist_option( 'search_result_page' ) );

        if ( 0 < $page_id ) {
            $translated_id = absint( apply_filters( 'wpml_object_id', $page_id, 'page', true, $language ) );
            $translated    = 0 < $translated_id ? get_post( $translated_id ) : null;

            if ( $translated instanceof \WP_Post && 'publish' === $translated->post_status ) {
                return $this->localized_post_url( $translated_id, $language );
            }
        }

        if ( 1 > $page_id && isset( $details['url'] ) && $this->is_public_url( $details['url'] ) ) {
            return $details['url'];
        }

        return $this->search_page_url();
    }

    private function post_term_ids( $post_id, $taxonomy ) {
        $terms = get_the_terms( $post_id, $taxonomy );

        return is_wp_error( $terms ) || ! is_array( $terms ) ? [] : array_map( 'absint', wp_list_pluck( $terms, 'term_id' ) );
    }

    private function post_query( array $args ) {
        $language = has_filter( 'wpml_current_language' ) ? apply_filters( 'wpml_current_language', null ) : null;

        if ( has_action( 'wpml_switch_language' ) ) {
            do_action( 'wpml_switch_language', 'all' );
        }

        try {
            return new \WP_Query( $args );
        } finally {
            if ( has_action( 'wpml_switch_language' ) ) {
                do_action( 'wpml_switch_language', $language );
            }
        }
    }

    private function localized_post_url( $post_id, $language ) {
        $language = sanitize_key( (string) $language );
        $current  = has_filter( 'wpml_current_language' ) ? sanitize_key( (string) apply_filters( 'wpml_current_language', null ) ) : '';

        if ( '' !== $language && has_action( 'wpml_switch_language' ) ) {
            do_action( 'wpml_switch_language', $language );
        }

        try {
            $url = get_permalink( $post_id );
        } finally {
            if ( '' !== $language && has_action( 'wpml_switch_language' ) ) {
                do_action( 'wpml_switch_language', $current );
            }
        }

        if ( '' !== $language && is_string( $url ) && has_filter( 'wpml_permalink' ) ) {
            $url = apply_filters( 'wpml_permalink', $url, $language, true );
        }

        return $url;
    }

    private function contains_url( array $resources, $url ) {
        $target = untrailingslashit( esc_url_raw( (string) $url ) );

        foreach ( $resources as $resource ) {
            if ( isset( $resource['url'] ) && $target === untrailingslashit( esc_url_raw( (string) $resource['url'] ) ) ) {
                return true;
            }
        }

        return false;
    }

    private function is_public_url( $url ) {
        $registry = new Warm_URL_Registry( home_url( '/' ), 1 );
        $registry->add( is_string( $url ) ? $url : '', 'resource-discovery' );

        return ! empty( $registry->all() );
    }
}
