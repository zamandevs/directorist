<?php

namespace Directorist\Cache;

/**
 * Positively identifies public frontend routes owned by Directorist.
 */
final class Route_Resolver {
    /** @var Query_Normalizer */
    private $query_normalizer;

    /** @var string[] */
    private $public_shortcodes = [
        'directorist_all_listing',
        'directorist_category',
        'directorist_tag',
        'directorist_location',
        'directorist_all_categories',
        'directorist_all_locations',
        'directorist_search_listing',
        'directorist_search_result',
        'directorist_author_profile',
        'directorist_all_authors',
    ];

    /** @var string[] */
    private $private_shortcodes = [
        'directorist_add_listing',
        'directorist_user_dashboard',
        'directorist_signin_signup',
        'directorist_custom_registration',
        'directorist_user_login',
        'directorist_payment_receipt',
        'directorist_checkout',
        'directorist_transaction_failure',
    ];

    /** @var string[] */
    private $public_blocks = [
        'directorist/all-listing',
        'directorist/category',
        'directorist/tag',
        'directorist/location',
        'directorist/all-categories',
        'directorist/all-locations',
        'directorist/search-listing',
        'directorist/search-result',
        'directorist/author-profile',
        'directorist/all-authors',
    ];

    /** @var string[] */
    private $private_blocks = [
        'directorist/add-listing',
        'directorist/user-dashboard',
        'directorist/signin-signup',
        'directorist/checkout',
        'directorist/payment-receipt',
        'directorist/transaction-failure',
        'directorist/account-button',
    ];

    /**
     * @param Query_Normalizer|null $query_normalizer Query normalizer.
     */
    public function __construct( Query_Normalizer $query_normalizer = null ) {
        $this->query_normalizer = $query_normalizer ?: new Query_Normalizer();
    }

    /**
     * @param array|null $state Resolved WordPress state, or null for globals.
     * @return Route_Identity|null
     */
    public function resolve( array $state = null ) {
        $state = null === $state ? $this->current_state() : $this->normalize_state( $state );

        if ( $this->is_private_configured_page( $state ) || $this->has_private_content( $state['post_content'] ) ) {
            return null;
        }

        $identity_data = $this->resolve_core_identity( $state );

        if ( empty( $identity_data ) && $this->has_public_content( $state['post_content'] ) ) {
            $identity_data = [
                'route_type' => 'embedded',
                'object_id'  => $state['page_id'],
            ];
        }

        /**
         * Filters an unresolved or core-resolved public Directorist route.
         *
         * Private configured pages and content are rejected before this filter.
         * Return identity data or null. Core always owns site, query, page,
         * pagination, and language key material.
         *
         * @param array|null $identity Route identity data.
         * @param array      $state Resolved request state.
         */
        $identity_data = apply_filters( 'directorist_page_cache_route_identity', $identity_data, $state );

        if ( ! is_array( $identity_data ) || empty( $identity_data['route_type'] ) ) {
            return null;
        }

        $route_type = sanitize_key( $identity_data['route_type'] );
        $query      = $this->query_normalizer->normalize( $route_type, $state['query_args'], $state['raw_query'] );

        if ( ! $query->is_valid() ) {
            return null;
        }

        $variation   = $query->get_args();
        $page_number = $this->resolve_page_number( $state, $variation );
        unset( $variation['paged'], $variation['page'] );

        $entities = $this->resolve_entities( $route_type, $state );

        if ( ! empty( $identity_data['entities'] ) && is_array( $identity_data['entities'] ) ) {
            foreach ( $identity_data['entities'] as $type => $ids ) {
                $entities[ $type ] = array_merge( isset( $entities[ $type ] ) ? $entities[ $type ] : [], (array) $ids );
            }
        }

        return new Route_Identity(
            [
                'site_id'     => $state['site_id'],
                'home_url'    => $state['home_url'],
                'route_type'  => $route_type,
                'object_id'   => isset( $identity_data['object_id'] ) ? absint( $identity_data['object_id'] ) : 0,
                'page_id'     => $state['page_id'],
                'page_number' => $page_number,
                'variation'   => $variation,
                'entities'    => $entities,
                'language'    => $state['language'],
            ]
        );
    }

    /**
     * @param array $state Request state.
     * @return array|null
     */
    private function resolve_core_identity( array $state ) {
        if ( $state['is_singular_listing'] && $state['object_id'] ) {
            return [ 'route_type' => 'listing', 'object_id' => $state['object_id'] ];
        }

        $taxonomy_routes = [
            ATBDP_CATEGORY => 'category',
            ATBDP_LOCATION => 'location',
            ATBDP_TAGS     => 'tag',
        ];

        if ( isset( $taxonomy_routes[ $state['taxonomy'] ] ) && $state['term_id'] ) {
            return [
                'route_type' => $taxonomy_routes[ $state['taxonomy'] ],
                'object_id'  => $state['term_id'],
            ];
        }

        $public_pages = [
            'listings'   => 'listings',
            'results'    => 'search',
            'category'   => 'category',
            'location'   => 'location',
            'tag'        => 'tag',
            'author'     => 'author',
            'categories' => 'categories',
            'locations'  => 'locations',
            'search'     => 'search-form',
        ];

        foreach ( $public_pages as $page_name => $route_type ) {
            if ( empty( $state['configured_pages'][ $page_name ] ) || $state['page_id'] !== $state['configured_pages'][ $page_name ] ) {
                continue;
            }

            $object_id = $state['page_id'];

            if ( in_array( $route_type, [ 'category', 'location', 'tag' ], true ) && $state['term_id'] ) {
                $object_id = $state['term_id'];
            } elseif ( 'author' === $route_type && $state['author_id'] ) {
                $object_id = $state['author_id'];
            }

            return [ 'route_type' => $route_type, 'object_id' => $object_id ];
        }

        return null;
    }

    /**
     * @param string $route_type Route type.
     * @param array  $state Request state.
     * @return array
     */
    private function resolve_entities( $route_type, array $state ) {
        $entities = [
            'directory' => $state['directory_ids'],
            'term'      => $state['term_ids'],
            'author'    => $state['post_author'] ? [ $state['post_author'] ] : [],
        ];

        if ( in_array( $route_type, [ 'category', 'location', 'tag' ], true ) && $state['term_id'] ) {
            $entities['term'][] = $state['term_id'];
        }

        if ( 'author' === $route_type && $state['author_id'] ) {
            $entities['author'][] = $state['author_id'];
        }

        return $entities;
    }

    /**
     * @param array $state Request state.
     * @param array $variation Normalized variation.
     * @return int
     */
    private function resolve_page_number( array $state, array $variation ) {
        $page_number = max( 1, absint( $state['paged'] ) );

        foreach ( [ 'paged', 'page' ] as $name ) {
            if ( isset( $variation[ $name ] ) ) {
                $page_number = max( 1, absint( $variation[ $name ] ) );
            }
        }

        return $page_number;
    }

    /**
     * @param array $state Request state.
     * @return bool
     */
    private function is_private_configured_page( array $state ) {
        foreach ( [ 'form', 'dashboard', 'checkout', 'receipt', 'failed', 'registration', 'login' ] as $page_name ) {
            if ( ! empty( $state['configured_pages'][ $page_name ] ) && $state['page_id'] === $state['configured_pages'][ $page_name ] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $content Post content.
     * @return bool
     */
    private function has_public_content( $content ) {
        return $this->content_has_any( $content, $this->public_shortcodes, $this->public_blocks );
    }

    /**
     * @param string $content Post content.
     * @return bool
     */
    private function has_private_content( $content ) {
        return $this->content_has_any( $content, $this->private_shortcodes, $this->private_blocks );
    }

    /**
     * @param string   $content Post content.
     * @param string[] $shortcodes Shortcode tags.
     * @param string[] $blocks Block names.
     * @return bool
     */
    private function content_has_any( $content, array $shortcodes, array $blocks ) {
        if ( '' === $content ) {
            return false;
        }

        foreach ( $shortcodes as $shortcode ) {
            if ( has_shortcode( $content, $shortcode ) ) {
                return true;
            }
        }

        foreach ( $blocks as $block ) {
            if ( has_block( $block, $content ) ) {
                return true;
            }
        }

        return false;
    }

    /** @return array */
    private function current_state() {
        $page_id  = is_page() ? get_queried_object_id() : 0;
        $object   = get_queried_object();
        $taxonomy = $object instanceof \WP_Term ? $object->taxonomy : '';
        $term_id  = $object instanceof \WP_Term ? $object->term_id : 0;

        if ( ! $term_id ) {
            $term_map = [
                'atbdp_category' => ATBDP_CATEGORY,
                'atbdp_location' => ATBDP_LOCATION,
                'atbdp_tag'      => ATBDP_TAGS,
            ];

            foreach ( $term_map as $query_var => $term_taxonomy ) {
                $slug = get_query_var( $query_var );
                $term = $slug ? get_term_by( 'slug', $slug, $term_taxonomy ) : false;

                if ( $term instanceof \WP_Term ) {
                    $taxonomy = $term_taxonomy;
                    $term_id  = $term->term_id;
                    break;
                }
            }
        }

        $object_id     = is_singular( ATBDP_POST_TYPE ) ? get_queried_object_id() : 0;
        $post          = $object_id ? get_post( $object_id ) : null;
        $directory_ids = $object_id ? wp_get_object_terms( $object_id, ATBDP_TYPE, [ 'fields' => 'ids' ] ) : [];
        $term_ids      = $object_id ? wp_get_object_terms( $object_id, [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ], [ 'fields' => 'ids' ] ) : [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed for duplicate keys; never rendered or executed.
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $raw_query   = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public cache-key input.
        $query_args    = wp_unslash( $_GET );
        $directory_var = get_query_var( 'directory-type' );

        if ( $directory_var && empty( $query_args['directory-type'] ) ) {
            $query_args['directory-type'] = $directory_var;
        }

        if ( ! $term_id ) {
            foreach ( [ 'category' => ATBDP_CATEGORY, 'location' => ATBDP_LOCATION, 'tag' => ATBDP_TAGS ] as $query_name => $term_taxonomy ) {
                if ( empty( $query_args[ $query_name ] ) ) {
                    continue;
                }

                $term = get_term_by( 'slug', sanitize_title( wp_unslash( $query_args[ $query_name ] ) ), $term_taxonomy );

                if ( $term instanceof \WP_Term ) {
                    $taxonomy = $term_taxonomy;
                    $term_id  = $term->term_id;
                    break;
                }
            }
        }

        return $this->normalize_state(
            [
                'site_id'             => get_current_blog_id(),
                'home_url'            => home_url( '/' ),
                'request_uri'         => $request_uri,
                'page_id'             => $page_id,
                'object_id'           => $object_id,
                'term_id'             => $term_id,
                'author_id'           => $this->resolve_current_author_id(),
                'taxonomy'            => $taxonomy,
                'paged'               => max( 1, absint( get_query_var( 'paged' ) ?: get_query_var( 'page' ) ) ),
                'query_args'          => $query_args,
                'raw_query'           => $raw_query,
                'post_content'        => $page_id ? (string) get_post_field( 'post_content', $page_id ) : '',
                'configured_pages'    => $this->configured_pages(),
                'is_singular_listing' => is_singular( ATBDP_POST_TYPE ),
                'directory_ids'       => is_wp_error( $directory_ids ) ? [] : $directory_ids,
                'term_ids'            => is_wp_error( $term_ids ) ? [] : $term_ids,
                'post_author'         => $post instanceof \WP_Post ? $post->post_author : 0,
                'language'            => $this->current_language(),
            ]
        );
    }

    /**
     * @param array $state Raw state.
     * @return array
     */
    private function normalize_state( array $state ) {
        $state = wp_parse_args(
            $state,
            [
                'site_id'             => 1,
                'home_url'            => '',
                'request_uri'         => '',
                'page_id'             => 0,
                'object_id'           => 0,
                'term_id'             => 0,
                'author_id'           => 0,
                'taxonomy'            => '',
                'paged'               => 1,
                'query_args'          => [],
                'raw_query'           => '',
                'post_content'        => '',
                'configured_pages'    => [],
                'is_singular_listing' => false,
                'directory_ids'       => [],
                'term_ids'            => [],
                'post_author'         => 0,
                'language'            => '',
            ]
        );

        foreach ( [ 'site_id', 'page_id', 'object_id', 'term_id', 'author_id', 'paged', 'post_author' ] as $name ) {
            $state[ $name ] = absint( $state[ $name ] );
        }

        $state['query_args']          = is_array( $state['query_args'] ) ? $state['query_args'] : [];
        $state['configured_pages']    = is_array( $state['configured_pages'] ) ? array_map( 'absint', $state['configured_pages'] ) : [];
        $state['directory_ids']       = array_map( 'absint', (array) $state['directory_ids'] );
        $state['term_ids']            = array_map( 'absint', (array) $state['term_ids'] );
        $state['post_content']        = (string) $state['post_content'];
        $state['taxonomy']            = (string) $state['taxonomy'];
        $state['raw_query']           = (string) $state['raw_query'];
        $state['is_singular_listing'] = (bool) $state['is_singular_listing'];

        if ( '' === $state['raw_query'] && '' !== (string) $state['request_uri'] ) {
            $state['raw_query'] = (string) wp_parse_url( $state['request_uri'], PHP_URL_QUERY );
        }

        return $state;
    }

    /** @return array */
    private function configured_pages() {
        $pages = [];

        foreach ( [ 'listings', 'results', 'category', 'location', 'tag', 'author', 'categories', 'locations', 'search', 'form', 'dashboard', 'checkout', 'receipt', 'failed', 'registration', 'login' ] as $name ) {
            $pages[ $name ] = function_exists( 'directorist_get_page_id' ) ? directorist_get_page_id( $name ) : 0;
        }

        return $pages;
    }

    /** @return int */
    private function resolve_current_author_id() {
        $author = get_query_var( 'author_id' );

        if ( is_numeric( $author ) ) {
            return absint( $author );
        }

        $user = $author ? get_user_by( 'slug', sanitize_title( $author ) ) : false;

        return $user instanceof \WP_User ? $user->ID : 0;
    }

    /** @return string */
    private function current_language() {
        $language = apply_filters( 'wpml_current_language', null );

        if ( ! $language && function_exists( 'pll_current_language' ) ) {
            $language = pll_current_language( 'slug' );
        }

        return sanitize_key( (string) $language );
    }
}
