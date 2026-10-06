<?php

namespace Directorist\Cache;

/**
 * Resolves and memoizes entity state needed only during cache mutations.
 */
final class Mutation_Entity_Resolver {
    /** @var Route_Resolver */
    private $route_resolver;

    /** @var array<int,array> */
    private $listing_snapshots = [];

    /** @var array<string,array> */
    private $term_snapshots = [];

    /**
     * @param Route_Resolver|null $route_resolver Route classifier.
     */
    public function __construct( Route_Resolver $route_resolver = null ) {
        $this->route_resolver = $route_resolver ?: new Route_Resolver();
    }

    /**
     * Reuse one listing snapshot across repeated field writes in this request.
     *
     * @param int  $post_id Listing ID.
     * @param bool $refresh Force current state refresh.
     * @return array
     */
    public function listing( $post_id, $refresh = false ) {
        $post_id = absint( $post_id );

        if ( $refresh || ! isset( $this->listing_snapshots[ $post_id ] ) ) {
            $this->listing_snapshots[ $post_id ] = $this->build_listing_snapshot( get_post( $post_id ) );
        }

        return $this->listing_snapshots[ $post_id ];
    }

    /**
     * Reconstruct listing state before one direct taxonomy assignment.
     *
     * @param \WP_Post|null $post Listing post.
     * @param string        $taxonomy Changed taxonomy.
     * @param int[]         $old_tt_ids Prior term-taxonomy IDs.
     * @return array
     */
    public function listing_with_old_terms( $post, $taxonomy, array $old_tt_ids ) {
        $snapshot = $this->build_listing_snapshot( $post );

        if ( empty( $snapshot ) ) {
            return [];
        }

        $old_term_ids = $this->term_ids_from_taxonomy_ids( $old_tt_ids, $taxonomy );

        if ( ATBDP_DIRECTORY_TYPE === $taxonomy ) {
            $snapshot['directory_ids'] = $old_term_ids;
            return $snapshot;
        }

        $other_taxonomies = array_values( array_diff( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ], [ $taxonomy ] ) );
        $other_term_ids   = wp_get_object_terms( $post->ID, $other_taxonomies, [ 'fields' => 'ids' ] );
        $other_term_ids   = is_wp_error( $other_term_ids ) ? [] : array_map( 'absint', $other_term_ids );

        $snapshot['term_ids'] = array_values( array_unique( array_merge( $other_term_ids, $old_term_ids ) ) );
        sort( $snapshot['term_ids'], SORT_NUMERIC );

        return $snapshot;
    }

    /**
     * Reuse one term snapshot across repeated builder field writes.
     *
     * @param int    $term_id Term ID.
     * @param string $taxonomy Taxonomy slug.
     * @param bool   $refresh Force current state refresh.
     * @return array
     */
    public function term( $term_id, $taxonomy, $refresh = false ) {
        $key = $this->term_key( $term_id, $taxonomy );

        if ( $refresh || ! isset( $this->term_snapshots[ $key ] ) ) {
            $this->term_snapshots[ $key ] = $this->build_term_snapshot( $term_id, $taxonomy );
        }

        return $this->term_snapshots[ $key ];
    }

    /**
     * @param \WP_Post|null $post Page post.
     * @return array
     */
    public function page( $post ) {
        if ( ! $post instanceof \WP_Post ) {
            return [];
        }

        $url = get_permalink( $post );

        return [ 'url' => $url ? $url : '' ];
    }

    /**
     * @param \WP_Post|null $post Post to inspect.
     * @return bool
     */
    public function is_public_directorist_page( $post ) {
        if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
            return false;
        }

        if ( $this->route_resolver->contains_private_content( $post->post_content ) ) {
            return false;
        }

        if ( in_array( $post->ID, $this->configured_public_page_ids(), true ) ) {
            return true;
        }

        return $this->route_resolver->is_public_content( $post->post_content );
    }

    /**
     * @param int $user_id User ID.
     * @return string
     */
    public function author_url( $user_id ) {
        return \ATBDP_Permalink::get_user_profile_page_link( $user_id );
    }

    /** @return string[] */
    public function configured_public_urls() {
        $urls = [];

        foreach ( $this->configured_public_page_ids() as $page_id ) {
            $url = get_permalink( $page_id );

            if ( $url ) {
                $urls[] = $url;
            }
        }

        return array_values( array_unique( $urls ) );
    }

    /**
     * @param int $post_id Listing ID.
     * @return void
     */
    public function forget_listing( $post_id ) {
        unset( $this->listing_snapshots[ absint( $post_id ) ] );
    }

    /**
     * @param int    $term_id Term ID.
     * @param string $taxonomy Taxonomy slug.
     * @return void
     */
    public function forget_term( $term_id, $taxonomy ) {
        unset( $this->term_snapshots[ $this->term_key( $term_id, $taxonomy ) ] );
    }

    /** @return void */
    public function reset() {
        $this->listing_snapshots = [];
        $this->term_snapshots    = [];
    }

    /**
     * @param \WP_Post|null $post Listing post.
     * @return array
     */
    private function build_listing_snapshot( $post ) {
        if ( ! $post instanceof \WP_Post || ATBDP_POST_TYPE !== $post->post_type ) {
            return [];
        }

        $term_ids      = wp_get_object_terms( $post->ID, [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ], [ 'fields' => 'ids' ] );
        $directory_ids = wp_get_object_terms( $post->ID, ATBDP_DIRECTORY_TYPE, [ 'fields' => 'ids' ] );
        $url           = get_permalink( $post );

        return [
            'author_id'     => absint( $post->post_author ),
            'directory_ids' => is_wp_error( $directory_ids ) ? [] : array_map( 'absint', $directory_ids ),
            'status'        => sanitize_key( $post->post_status ),
            'term_ids'      => is_wp_error( $term_ids ) ? [] : array_map( 'absint', $term_ids ),
            'url'           => $url ? $url : '',
        ];
    }

    /**
     * @param int    $term_id Term ID.
     * @param string $taxonomy Taxonomy.
     * @return array
     */
    private function build_term_snapshot( $term_id, $taxonomy ) {
        if ( ! $this->is_directorist_taxonomy( $taxonomy ) ) {
            return [];
        }

        $term = get_term( $term_id, $taxonomy );

        if ( ! $term instanceof \WP_Term ) {
            return [];
        }

        $listing_ids = get_objects_in_term( $term_id, $taxonomy );

        return [
            'listing_ids' => is_wp_error( $listing_ids ) ? [] : array_map( 'absint', $listing_ids ),
            'url'         => $this->term_url( $term ),
        ];
    }

    /**
     * @param int[]  $tt_ids Term-taxonomy IDs.
     * @param string $taxonomy Taxonomy slug.
     * @return int[]
     */
    private function term_ids_from_taxonomy_ids( array $tt_ids, $taxonomy ) {
        $term_ids = [];

        foreach ( $tt_ids as $tt_id ) {
            $term = get_term_by( 'term_taxonomy_id', absint( $tt_id ), $taxonomy );

            if ( $term instanceof \WP_Term ) {
                $term_ids[] = absint( $term->term_id );
            }
        }

        $term_ids = array_values( array_unique( $term_ids ) );
        sort( $term_ids, SORT_NUMERIC );

        return $term_ids;
    }

    /**
     * @param \WP_Term $term Directorist term.
     * @return string
     */
    private function term_url( $term ) {
        if ( ATBDP_CATEGORY === $term->taxonomy ) {
            return \ATBDP_Permalink::atbdp_get_category_page( $term );
        }

        if ( ATBDP_LOCATION === $term->taxonomy ) {
            return \ATBDP_Permalink::atbdp_get_location_page( $term );
        }

        if ( ATBDP_TAGS === $term->taxonomy ) {
            return \ATBDP_Permalink::atbdp_get_tag_page( $term );
        }

        return '';
    }

    /** @return int[] */
    private function configured_public_page_ids() {
        $ids = [];

        foreach ( Route_Resolver::public_page_names() as $name ) {
            $ids[] = function_exists( 'directorist_get_page_id' ) ? absint( directorist_get_page_id( $name ) ) : 0;
        }

        return array_values( array_unique( array_filter( $ids ) ) );
    }

    /**
     * @param string $taxonomy Taxonomy slug.
     * @return bool
     */
    private function is_directorist_taxonomy( $taxonomy ) {
        return in_array( $taxonomy, [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS, ATBDP_DIRECTORY_TYPE ], true );
    }

    /**
     * @param int    $term_id Term ID.
     * @param string $taxonomy Taxonomy.
     * @return string
     */
    private function term_key( $term_id, $taxonomy ) {
        return sanitize_key( $taxonomy ) . ':' . absint( $term_id );
    }
}
