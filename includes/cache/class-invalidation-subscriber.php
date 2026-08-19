<?php

namespace Directorist\Cache;

/**
 * Observes completed WordPress/Directorist mutations and records semantics.
 */
final class Invalidation_Subscriber {
    /** @var Change_Set */
    private $changes;

    /** @var Invalidation_Dispatcher|null */
    private $dispatcher;

    /** @var bool */
    private $registered = false;

    /** @var array<int,array> */
    private $pending_posts = [];

    /** @var array<string,array> */
    private $pending_terms = [];

    /** @var array<int,array> */
    private $pending_users = [];

    /** @var Mutation_Entity_Resolver|null */
    private $entities;

    /**
     * @param Change_Set                    $changes Request-local changes.
     * @param Invalidation_Dispatcher|null  $dispatcher Optional dispatcher.
     * @param Mutation_Entity_Resolver|null $entities Optional entity resolver.
     */
    public function __construct( Change_Set $changes, Invalidation_Dispatcher $dispatcher = null, Mutation_Entity_Resolver $entities = null ) {
        $this->changes    = $changes;
        $this->dispatcher = $dispatcher;
        $this->entities   = $entities;
    }

    /** @return bool */
    public function register() {
        if ( $this->registered ) {
            return false;
        }

        $this->registered = true;

        foreach ( $this->hook_definitions() as $definition ) {
            add_action( $definition[0], [ $this, $definition[1] ], $definition[2], $definition[3] );
        }

        if ( $this->dispatcher ) {
            add_action( 'shutdown', [ $this, 'dispatch' ], PHP_INT_MAX );
        }

        return true;
    }

    /** @return bool */
    public function unregister() {
        if ( ! $this->registered ) {
            return false;
        }

        $this->registered = false;

        foreach ( $this->hook_definitions() as $definition ) {
            remove_action( $definition[0], [ $this, $definition[1] ], $definition[2] );
        }

        remove_action( 'shutdown', [ $this, 'dispatch' ], PHP_INT_MAX );

        $this->pending_posts = [];
        $this->pending_terms = [];
        $this->pending_users = [];
        if ( $this->entities instanceof Mutation_Entity_Resolver ) {
            $this->entities->reset();
        }

        return true;
    }

    /**
     * @param int   $post_id Post ID.
     * @param array $data Pending post data.
     * @return void
     */
    public function capture_post_before( $post_id, $data ) {
        unset( $data );

        $post = get_post( $post_id );

        if ( $post instanceof \WP_Post && ATBDP_POST_TYPE === $post->post_type ) {
            $this->pending_posts[ $post_id ] = $this->entities()->listing( $post_id, true );
        } elseif ( $this->entities()->is_public_directorist_page( $post ) ) {
            $this->pending_posts[ $post_id ] = $this->entities()->page( $post );
        }
    }

    /**
     * @param int      $post_id Post ID.
     * @param \WP_Post $post Current post.
     * @param bool     $update Whether this was an update.
     * @param \WP_Post $post_before Prior post object.
     * @return void
     */
    public function record_post_write( $post_id, $post, $update, $post_before = null ) {
        unset( $update, $post_before );

        if ( ATBDP_POST_TYPE === $post->post_type ) {
            $before = isset( $this->pending_posts[ $post_id ] ) ? $this->pending_posts[ $post_id ] : [];
            $this->record_listing_change( $post_id, 'post', $before, $this->entities()->listing( $post_id, true ) );
            unset( $this->pending_posts[ $post_id ] );
            return;
        }

        $before = isset( $this->pending_posts[ $post_id ] ) ? $this->pending_posts[ $post_id ] : [];

        if ( $this->entities()->is_public_directorist_page( $post ) || ! empty( $before ) ) {
            $this->record_page_change( $post_id, $before, $this->entities()->page( $post ) );
        }

        unset( $this->pending_posts[ $post_id ] );
    }

    /**
     * @param int      $post_id Post ID.
     * @param \WP_Post $post Post object.
     * @return void
     */
    public function capture_post_delete( $post_id, $post = null ) {
        $post = $post instanceof \WP_Post ? $post : get_post( $post_id );

        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        if ( ATBDP_POST_TYPE === $post->post_type ) {
            $this->pending_posts[ $post_id ] = $this->entities()->listing( $post_id, true );
        } elseif ( $this->entities()->is_public_directorist_page( $post ) ) {
            $this->pending_posts[ $post_id ] = $this->entities()->page( $post );
        }
    }

    /**
     * @param int      $post_id Post ID.
     * @param \WP_Post $post Deleted post.
     * @return void
     */
    public function record_post_delete( $post_id, $post = null ) {
        $before = isset( $this->pending_posts[ $post_id ] ) ? $this->pending_posts[ $post_id ] : [];

        if ( ( $post instanceof \WP_Post && ATBDP_POST_TYPE === $post->post_type ) || isset( $before['directory_ids'] ) ) {
            $this->record_listing_change( $post_id, 'delete', $before, [] );
        } elseif ( ! empty( $before ) ) {
            $this->record_page_change( $post_id, $before, [] );
        }

        unset( $this->pending_posts[ $post_id ] );
        $this->entities()->forget_listing( $post_id );
    }

    /**
     * @param int    $meta_id Meta ID.
     * @param int    $post_id Post ID.
     * @param string $meta_key Meta key.
     * @param mixed  $meta_value Meta value.
     * @return void
     */
    public function record_post_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
        unset( $meta_id, $meta_value );

        if ( ATBDP_POST_TYPE === get_post_type( $post_id ) ) {
            if ( $this->is_ignored_listing_meta( $meta_key ) ) {
                return;
            }

            $before = isset( $this->pending_posts[ $post_id ] ) ? $this->pending_posts[ $post_id ] : [];
            $this->record_listing_change( $post_id, 'meta', $before, $this->entities()->listing( $post_id ) );
            return;
        }

        $post = get_post( $post_id );

        if ( $this->entities()->is_public_directorist_page( $post ) ) {
            $this->record_page_change( $post_id, [], $this->entities()->page( $post ) );
        }
    }

    /**
     * @param int    $object_id Object ID.
     * @param array  $terms Terms supplied to WordPress.
     * @param array  $tt_ids Resulting term-taxonomy IDs.
     * @param string $taxonomy Taxonomy.
     * @param bool   $append Append mode.
     * @param array  $old_tt_ids Prior term-taxonomy IDs.
     * @return void
     */
    public function record_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
        unset( $terms, $tt_ids, $append );

        if ( ATBDP_POST_TYPE !== get_post_type( $object_id ) || ! $this->is_directorist_taxonomy( $taxonomy ) ) {
            return;
        }

        $before = isset( $this->pending_posts[ $object_id ] )
            ? $this->pending_posts[ $object_id ]
            : $this->entities()->listing_with_old_terms( get_post( $object_id ), $taxonomy, $old_tt_ids );
        $this->record_listing_change( $object_id, 'terms', $before, $this->entities()->listing( $object_id, true ) );
    }

    /**
     * @param int    $term_id Term ID.
     * @param string $taxonomy Taxonomy.
     * @param array  $args Update arguments.
     * @return void
     */
    public function capture_term_before( $term_id, $taxonomy, $args = [] ) {
        unset( $args );

        if ( $this->is_directorist_taxonomy( $taxonomy ) ) {
            $this->pending_terms[ $this->term_key( $term_id, $taxonomy ) ] = $this->entities()->term( $term_id, $taxonomy, true );
        }
    }

    /**
     * @param int    $term_id Term ID.
     * @param int    $tt_id Term-taxonomy ID.
     * @param string $taxonomy Taxonomy.
     * @param array  $args Creation arguments.
     * @return void
     */
    public function record_term_created( $term_id, $tt_id, $taxonomy, $args = [] ) {
        unset( $tt_id, $args );
        $this->record_term_change( $term_id, $taxonomy, [], $this->entities()->term( $term_id, $taxonomy, true ), 'create' );
    }

    /**
     * @param int    $term_id Term ID.
     * @param int    $tt_id Term-taxonomy ID.
     * @param string $taxonomy Taxonomy.
     * @param array  $args Update arguments.
     * @return void
     */
    public function record_term_edited( $term_id, $tt_id, $taxonomy, $args = [] ) {
        unset( $tt_id, $args );

        $key    = $this->term_key( $term_id, $taxonomy );
        $before = isset( $this->pending_terms[ $key ] ) ? $this->pending_terms[ $key ] : [];

        $this->record_term_change( $term_id, $taxonomy, $before, $this->entities()->term( $term_id, $taxonomy, true ), 'edit' );
        unset( $this->pending_terms[ $key ] );
    }

    /**
     * @param int    $term_id Term ID.
     * @param string $taxonomy Taxonomy.
     * @return void
     */
    public function capture_term_delete( $term_id, $taxonomy ) {
        if ( $this->is_directorist_taxonomy( $taxonomy ) ) {
            $this->pending_terms[ $this->term_key( $term_id, $taxonomy ) ] = $this->entities()->term( $term_id, $taxonomy, true );
        }
    }

    /**
     * @param int      $term_id Term ID.
     * @param int      $tt_id Term-taxonomy ID.
     * @param string   $taxonomy Taxonomy.
     * @param \WP_Term $deleted_term Deleted term.
     * @param int[]    $object_ids Affected object IDs.
     * @return void
     */
    public function record_term_delete( $term_id, $tt_id, $taxonomy, $deleted_term, $object_ids ) {
        unset( $tt_id, $deleted_term );

        $key    = $this->term_key( $term_id, $taxonomy );
        $before = isset( $this->pending_terms[ $key ] ) ? $this->pending_terms[ $key ] : [];

        if ( empty( $before['listing_ids'] ) ) {
            $before['listing_ids'] = array_values( array_filter( array_map( 'absint', (array) $object_ids ) ) );
        }

        $this->record_term_change( $term_id, $taxonomy, $before, [], 'delete' );
        unset( $this->pending_terms[ $key ] );
        $this->entities()->forget_term( $term_id, $taxonomy );
    }

    /**
     * @param int    $meta_id Meta ID.
     * @param int    $term_id Term ID.
     * @param string $meta_key Meta key.
     * @param mixed  $meta_value Meta value.
     * @return void
     */
    public function record_term_meta( $meta_id, $term_id, $meta_key, $meta_value ) {
        unset( $meta_id, $meta_key, $meta_value );

        $term = get_term( $term_id );

        if ( $term instanceof \WP_Term && $this->is_directorist_taxonomy( $term->taxonomy ) ) {
            $snapshot = $this->entities()->term( $term_id, $term->taxonomy );
            $this->record_term_change( $term_id, $term->taxonomy, $snapshot, $snapshot, 'meta' );
        }
    }

    /**
     * @param int         $comment_id Comment ID.
     * @param \WP_Comment $comment Comment object.
     * @return void
     */
    public function record_comment_insert( $comment_id, $comment = null ) {
        $comment = $comment instanceof \WP_Comment ? $comment : get_comment( $comment_id );
        $this->record_comment( $comment, 'comment' );
    }

    /**
     * @param int   $comment_id Comment ID.
     * @param array $data Updated data.
     * @return void
     */
    public function record_comment_edit( $comment_id, $data ) {
        unset( $data );
        $this->record_comment( get_comment( $comment_id ), 'comment' );
    }

    /**
     * @param string      $new_status New comment status.
     * @param string      $old_status Old comment status.
     * @param \WP_Comment $comment Comment object.
     * @return void
     */
    public function record_comment_status( $new_status, $old_status, $comment ) {
        if ( $new_status !== $old_status ) {
            $this->record_comment( $comment, 'status' );
        }
    }

    /**
     * @param int         $comment_id Comment ID.
     * @param \WP_Comment $comment Comment object.
     * @return void
     */
    public function record_comment_event( $comment_id, $comment ) {
        unset( $comment_id );
        $this->record_comment( $comment, 'status' );
    }

    /**
     * @param int    $meta_id Meta ID.
     * @param int    $comment_id Comment ID.
     * @param string $meta_key Meta key.
     * @param mixed  $meta_value Meta value.
     * @return void
     */
    public function record_comment_meta( $meta_id, $comment_id, $meta_key, $meta_value ) {
        unset( $meta_id, $meta_value );
        $this->record_comment( get_comment( $comment_id ), 'rating' === $meta_key ? 'rating' : 'comment-meta' );
    }

    /**
     * @param string $option Option name.
     * @param mixed  $value Option value.
     * @return void
     */
    public function record_added_option( $option, $value ) {
        unset( $value );
        $this->record_option_change( $option );
    }

    /**
     * @param string $option Option name.
     * @param mixed  $old_value Prior value.
     * @param mixed  $value New value.
     * @return void
     */
    public function record_updated_option( $option, $old_value, $value ) {
        unset( $old_value, $value );
        $this->record_option_change( $option );
    }

    /**
     * @param string $option Option name.
     * @return void
     */
    public function record_deleted_option( $option ) {
        $this->record_option_change( $option );
    }

    /** @return void */
    public function record_template_change() {
        $this->changes->record( Change_Type::TEMPLATE, 'global', [ 'reasons' => [ 'theme' ] ] );
    }

    /**
     * @param int      $user_id User ID.
     * @param \WP_User $old_user_data Prior user data.
     * @param array    $userdata Updated user data.
     * @return void
     */
    public function record_profile_update( $user_id, $old_user_data, $userdata = [] ) {
        unset( $old_user_data, $userdata );
        $this->record_author_change( $user_id );
    }

    /**
     * @param int      $user_id User ID.
     * @param int|null $reassign Reassigned user ID.
     * @param \WP_User $user User data.
     * @return void
     */
    public function capture_user_delete( $user_id, $reassign = null, $user = null ) {
        unset( $reassign );

        $user = $user instanceof \WP_User ? $user : get_userdata( $user_id );

        if ( $user instanceof \WP_User ) {
            $this->pending_users[ $user_id ] = [ 'urls' => [ $this->entities()->author_url( $user_id ) ] ];
        }
    }

    /**
     * @param int      $user_id User ID.
     * @param int|null $reassign Reassigned user ID.
     * @param \WP_User $user User data.
     * @return void
     */
    public function record_user_delete( $user_id, $reassign = null, $user = null ) {
        unset( $reassign, $user );

        $context = isset( $this->pending_users[ $user_id ] ) ? $this->pending_users[ $user_id ] : [];
        $this->changes->record( Change_Type::AUTHOR, $user_id, $context );
        unset( $this->pending_users[ $user_id ] );
    }

    /**
     * @param int $listing_id Listing ID.
     * @return void
     */
    public function record_semantic_listing( $listing_id ) {
        $listing_id = absint( $listing_id );

        if ( ! $listing_id ) {
            return;
        }

        $before = isset( $this->pending_posts[ $listing_id ] ) ? $this->pending_posts[ $listing_id ] : [];
        $after  = ATBDP_POST_TYPE === get_post_type( $listing_id ) ? $this->entities()->listing( $listing_id, true ) : [];

        $this->record_listing_change( $listing_id, 'directorist', $before, $after );
    }

    /** @return void */
    public function record_settings_change() {
        $this->changes->record(
            Change_Type::SETTINGS,
            'global',
            [
                'reasons' => [ 'directorist' ],
                'urls'    => $this->entities()->configured_public_urls(),
            ]
        );
    }

    /**
     * @param array $term Created directory term result.
     * @return void
     */
    public function record_semantic_directory_create( $term ) {
        $term_id = is_array( $term ) && isset( $term['term_id'] ) ? absint( $term['term_id'] ) : 0;
        $this->record_semantic_directory( $term_id );
    }

    /**
     * @param int $term_id Directory term ID.
     * @return void
     */
    public function record_semantic_directory( $term_id ) {
        $term_id = absint( $term_id );

        if ( $term_id ) {
            $this->changes->record(
                Change_Type::DIRECTORY,
                $term_id,
                [ 'collection' => true, 'reasons' => [ 'directorist' ] ]
            );
        }
    }

    /**
     * @param int $listing_id Listing ID.
     * @return void
     */
    public function record_semantic_review( $listing_id ) {
        $this->record_review_change( $listing_id, 'directorist' );
    }

    /** @return array */
    public function dispatch() {
        return $this->dispatcher ? $this->dispatcher->dispatch() : [ 'success' => true, 'code' => 'no_dispatcher' ];
    }

    /**
     * @param int        $listing_id Listing ID.
     * @param string     $reason Stable reason.
     * @param array|null $before Prior state.
     * @param array|null $after Current state.
     * @return void
     */
    private function record_listing_change( $listing_id, $reason, array $before = [], array $after = [] ) {
        $this->changes->record(
            Change_Type::LISTING,
            $listing_id,
            [
                'after'      => $after,
                'before'     => $before,
                'collection' => true,
                'reasons'    => [ $reason ],
            ]
        );
    }

    /**
     * @param int    $term_id Term ID.
     * @param string $taxonomy Taxonomy.
     * @param array  $before Prior state.
     * @param array  $after Current state.
     * @param string $reason Stable reason.
     * @return void
     */
    private function record_term_change( $term_id, $taxonomy, array $before, array $after, $reason ) {
        if ( ! $this->is_directorist_taxonomy( $taxonomy ) ) {
            return;
        }

        $context = [
            'collection'  => true,
            'listing_ids' => array_merge(
                isset( $before['listing_ids'] ) ? $before['listing_ids'] : [],
                isset( $after['listing_ids'] ) ? $after['listing_ids'] : []
            ),
            'reasons'     => [ $reason ],
            'taxonomy'    => $taxonomy,
        ];

        if ( ! empty( $before['url'] ) ) {
            $context['before_url'] = $before['url'];
        }

        if ( ! empty( $after['url'] ) ) {
            $context['after_url'] = $after['url'];
        }

        $type = ATBDP_DIRECTORY_TYPE === $taxonomy ? Change_Type::DIRECTORY : Change_Type::TERM;
        $this->changes->record( $type, $term_id, $context );
    }

    /**
     * @param int   $page_id Page ID.
     * @param array $before Prior state.
     * @param array $after Current state.
     * @return void
     */
    private function record_page_change( $page_id, array $before, array $after ) {
        $urls = [];

        foreach ( [ $before, $after ] as $state ) {
            if ( ! empty( $state['url'] ) ) {
                $urls[] = $state['url'];
            }
        }

        $this->changes->record( Change_Type::PAGE, $page_id, [ 'urls' => $urls ] );
        $this->changes->record( Change_Type::TEMPLATE, 'global', [ 'reasons' => [ 'page' ] ] );
    }

    /**
     * @param mixed  $comment Comment object or ID.
     * @param string $reason Stable reason.
     * @return void
     */
    private function record_comment( $comment, $reason ) {
        $comment = get_comment( $comment );

        if ( ! $comment instanceof \WP_Comment || ATBDP_POST_TYPE !== get_post_type( $comment->comment_post_ID ) ) {
            return;
        }

        $this->record_review_change( $comment->comment_post_ID, $reason );
    }

    /**
     * @param int    $listing_id Listing ID.
     * @param string $reason Stable reason.
     * @return void
     */
    private function record_review_change( $listing_id, $reason ) {
        $listing_id = absint( $listing_id );

        if ( ! $listing_id ) {
            return;
        }

        $url = get_permalink( $listing_id );

        $this->changes->record(
            Change_Type::REVIEW,
            $listing_id,
            [
                'collection' => true,
                'reasons'    => [ $reason ],
                'urls'       => $url ? [ $url ] : [],
            ]
        );
    }

    /**
     * @param string $option Option name.
     * @return void
     */
    private function record_option_change( $option ) {
        if ( 'atbdp_option' === $option ) {
            $this->record_settings_change();
            return;
        }

        if ( 'sidebars_widgets' === $option || 0 === strpos( $option, 'theme_mods_' ) || 0 === strpos( $option, 'widget_' ) ) {
            $this->changes->record( Change_Type::TEMPLATE, 'global', [ 'reasons' => [ 'option' ] ] );
        }
    }

    /**
     * @param int $user_id User ID.
     * @return void
     */
    private function record_author_change( $user_id ) {
        $url = $this->entities()->author_url( $user_id );

        $this->changes->record(
            Change_Type::AUTHOR,
            $user_id,
            [ 'urls' => $url ? [ $url ] : [] ]
        );
    }

    /** @return Mutation_Entity_Resolver */
    private function entities() {
        if ( ! $this->entities instanceof Mutation_Entity_Resolver ) {
            $this->entities = new Mutation_Entity_Resolver();
        }

        return $this->entities;
    }

    /**
     * @param string $taxonomy Taxonomy slug.
     * @return bool
     */
    private function is_directorist_taxonomy( $taxonomy ) {
        return in_array( $taxonomy, [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS, ATBDP_DIRECTORY_TYPE ], true );
    }

    /**
     * @param string $meta_key Listing meta key.
     * @return bool
     */
    private function is_ignored_listing_meta( $meta_key ) {
        $ignored = [ '_edit_lock', '_edit_last', '_directorist_imported_by_csv', '_is_migrated' ];

        if ( function_exists( 'directorist_get_listing_views_count_meta_key' ) ) {
            $ignored[] = directorist_get_listing_views_count_meta_key();
        }

        /**
         * Filters operational or volatile listing meta that should not purge page cache.
         *
         * @param string[] $ignored Ignored meta keys.
         * @param string   $meta_key Current meta key.
         */
        $ignored = apply_filters( 'directorist_page_cache_ignored_listing_meta_keys', $ignored, $meta_key );

        return in_array( $meta_key, (array) $ignored, true );
    }

    /** @return array<int,array{string,string,int,int}> */
    private function hook_definitions() {
        $definitions = [
            [ 'pre_post_update', 'capture_post_before', 10, 2 ],
            [ 'wp_after_insert_post', 'record_post_write', 10, 4 ],
            [ 'before_delete_post', 'capture_post_delete', 10, 2 ],
            [ 'deleted_post', 'record_post_delete', 10, 2 ],
            [ 'added_post_meta', 'record_post_meta', 10, 4 ],
            [ 'updated_post_meta', 'record_post_meta', 10, 4 ],
            [ 'deleted_post_meta', 'record_post_meta', 10, 4 ],
            [ 'set_object_terms', 'record_object_terms', 10, 6 ],
            [ 'edit_terms', 'capture_term_before', 10, 3 ],
            [ 'created_term', 'record_term_created', 10, 4 ],
            [ 'edited_term', 'record_term_edited', 10, 4 ],
            [ 'pre_delete_term', 'capture_term_delete', 10, 2 ],
            [ 'delete_term', 'record_term_delete', 10, 5 ],
            [ 'added_term_meta', 'record_term_meta', 10, 4 ],
            [ 'updated_term_meta', 'record_term_meta', 10, 4 ],
            [ 'deleted_term_meta', 'record_term_meta', 10, 4 ],
            [ 'wp_insert_comment', 'record_comment_insert', 10, 2 ],
            [ 'edit_comment', 'record_comment_edit', 10, 2 ],
            [ 'transition_comment_status', 'record_comment_status', 10, 3 ],
            [ 'trashed_comment', 'record_comment_event', 10, 2 ],
            [ 'untrashed_comment', 'record_comment_event', 10, 2 ],
            [ 'spammed_comment', 'record_comment_event', 10, 2 ],
            [ 'unspammed_comment', 'record_comment_event', 10, 2 ],
            [ 'deleted_comment', 'record_comment_event', 10, 2 ],
            [ 'added_comment_meta', 'record_comment_meta', 10, 4 ],
            [ 'updated_comment_meta', 'record_comment_meta', 10, 4 ],
            [ 'deleted_comment_meta', 'record_comment_meta', 10, 4 ],
            [ 'added_option', 'record_added_option', 10, 2 ],
            [ 'updated_option', 'record_updated_option', 10, 3 ],
            [ 'deleted_option', 'record_deleted_option', 10, 1 ],
            [ 'switch_theme', 'record_template_change', 10, 0 ],
            [ 'profile_update', 'record_profile_update', 10, 3 ],
            [ 'delete_user', 'capture_user_delete', 10, 3 ],
            [ 'deleted_user', 'record_user_delete', 10, 3 ],
            [ 'directorist_options_updated', 'record_settings_change', 100, 0 ],
            [ 'directorist_after_create_directory_type', 'record_semantic_directory_create', 100, 1 ],
            [ 'directorist_after_update_directory_type', 'record_semantic_directory', 100, 1 ],
            [ 'directorist_review_clear_cache', 'record_semantic_review', 100, 1 ],
        ];

        foreach ( [
            'atbdp_listing_inserted',
            'atbdp_listing_updated',
            'atbdp_after_created_listing',
            'atbdp_listing_published',
            'directorist_listing_status_updated',
            'directorist_listing_deleted',
            'directorist_listing_unfeatured',
            'directorist_listing_imported',
            'atbdp_listing_expired',
            'atbdp_after_renewal',
        ] as $hook ) {
            $definitions[] = [ $hook, 'record_semantic_listing', 100, 1 ];
        }

        return $definitions;
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
