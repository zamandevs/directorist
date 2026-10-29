<?php
/**
 * Shared visibility for native listing pages, queries and REST metadata.
 *
 * @package Directorist
 */

namespace Directorist;

defined( 'ABSPATH' ) || exit;

/** Keeps public query and metadata access independent from editor flags. */
final class Listing_Visibility {
    /**
     * Whether a listing belongs to a public bulk response.
     *
     * @param int|\WP_Post $listing Listing identity or object.
     * @return bool
     */
    public static function is_public( $listing ) {
        $post = self::post( $listing );
        return $post && ATBDP_POST_TYPE === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password;
    }

    /**
     * Validate a native nonce and permission to edit the exact listing.
     *
     * @param int|\WP_Post       $listing Listing identity or object.
     * @param \WP_REST_Request|null $request Native REST request when available.
     * @return bool
     */
    public static function can_preview( $listing, $request = null ) {
        $post = self::post( $listing );
        if ( ! $post || ATBDP_POST_TYPE !== $post->post_type || ! is_user_logged_in() || ! current_user_can( 'edit_post', $post->ID ) ) {
            return false;
        }

        $header_nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) && is_string( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
        $nonce        = $request instanceof \WP_REST_Request ? $request->get_header( 'X-WP-Nonce' ) : $header_nonce;
        $nonce        = $nonce ? $nonce : self::request_value( '_wpnonce', $request );
        if ( self::valid_nonce( $nonce, 'wp_rest' ) ) {
            return true;
        }
        if ( self::valid_nonce( self::request_value( 'directorist_nonce', $request ), directorist_get_nonce_key() ) || self::valid_nonce( self::request_value( 'nonce', $request ), 'directorist_divi_nonce' ) ) {
            return true;
        }
        $preview_id = self::request_value( 'preview_id', $request );
        if ( is_scalar( $preview_id ) && (string) $post->ID === (string) $preview_id && self::valid_nonce( self::request_value( 'preview_nonce', $request ), 'post_preview_' . $post->ID ) ) {
            return true;
        }
        if ( wp_doing_ajax() ) {
            $action = self::request_value( 'action', $request );
            if ( 'elementor_ajax' === $action && self::valid_nonce( self::request_value( '_nonce', $request ), 'elementor_ajax' ) ) {
                return true;
            }
            $previews = [ 'loop', 'card_template', 'loop_utility', 'single_map', 'related_listings', 'taxonomy_archive', 'pricing_plans', 'author_profile', 'search_composition' ];
            foreach ( $previews as $preview ) {
                if ( 'directorist_elementor_v4_preview_' . $preview === $action && self::valid_nonce( self::request_value( 'nonce', $request ), 'directorist_elementor_v4_preview' ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * REST metadata never accepts a visitor postpass cookie as a bulk grant.
     *
     * @param int|\WP_Post       $listing Listing identity or object.
     * @param \WP_REST_Request|null $request Native REST request when available.
     * @return bool
     */
    public static function can_read_rest( $listing, $request = null ) {
        return self::is_public( $listing ) || self::can_preview( $listing, $request );
    }

    /**
     * Ordinary pages retain native WordPress password and private-post access.
     *
     * @param int|\WP_Post $listing Listing identity or object.
     * @return bool
     */
    public static function can_read_single( $listing ) {
        $post = self::post( $listing );
        if ( ! $post || ATBDP_POST_TYPE !== $post->post_type ) {
            return false;
        }
        if ( self::is_public( $post ) || self::can_preview( $post ) ) {
            return true;
        }
        $ordinary_single = ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! wp_doing_ajax() && is_singular( ATBDP_POST_TYPE ) && (int) get_queried_object_id() === (int) $post->ID;
        return $ordinary_single && ( 'publish' === $post->post_status || current_user_can( 'read_post', $post->ID ) ) && ! post_password_required( $post );
    }

    /**
     * Display WordPress's password form without rendering protected fields.
     *
     * @param int|\WP_Post $listing Listing identity or object.
     * @return string
     */
    public static function denied_single_content( $listing ) {
        $post = self::post( $listing );
        return $post && 'publish' === $post->post_status && post_password_required( $post ) ? get_the_password_form( $post ) : '';
    }

    /**
     * Apply public visibility before SQL counts and pagination are calculated.
     *
     * @param array $args Native listing query arguments.
     * @param bool  $allow_preview Allow an explicitly selected exact edit preview.
     * @return array
     */
    public static function query_args( array $args, $allow_preview = true ) {
        $ids     = isset( $args['post__in'] ) && is_array( $args['post__in'] ) ? $args['post__in'] : [];
        $preview = $allow_preview && ! empty( $ids ) && count( $ids ) <= 100;
        foreach ( $ids as $id ) {
            if ( ! is_scalar( $id ) || ! self::can_preview( absint( $id ) ) ) {
                $preview = false;
                break;
            }
        }
        if ( ! $preview ) {
            $args['post_status']  = 'publish';
            $args['has_password'] = false;
            unset( $args['post_password'] );
        }
        return $args;
    }

    /**
     * Return one generic response for absent and inaccessible listing metadata.
     *
     * @return \WP_Error
     */
    public static function unavailable() {
        return new \WP_Error( 'directorist_rest_listing_unavailable', __( 'Listing not available.', 'directorist' ), [ 'status' => 404 ] );
    }

    /**
     * Resolve only explicit identities, never WordPress's implicit global post.
     *
     * @param int|\WP_Post $listing Listing identity or object.
     * @return \WP_Post|null
     */
    private static function post( $listing ) {
        if ( $listing instanceof \WP_Post ) {
            return $listing;
        }
        return is_scalar( $listing ) && absint( $listing ) > 0 ? get_post( absint( $listing ) ) : null;
    }

    /**
     * Read a native request value without interpreting flags as authorization.
     *
     * @param string                $key Request key.
     * @param \WP_REST_Request|null $request Native REST request.
     * @return mixed
     */
    private static function request_value( $key, $request ) {
        if ( $request instanceof \WP_REST_Request ) {
            $value = $request->get_param( $key );
            return is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '';
        }
        return isset( $_REQUEST[ $key ] ) && is_scalar( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Only read as input to exact native nonce verification.
    }

    /**
     * Verify a scalar nonce for a fixed native action.
     *
     * @param mixed  $nonce Native nonce.
     * @param string $action Fixed native action.
     * @return bool
     */
    private static function valid_nonce( $nonce, $action ) {
        return is_string( $nonce ) && '' !== $nonce && (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), $action );
    }
}
