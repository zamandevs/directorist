<?php
/**
 * Directorist Core Functions
 *
 * @package Directorist\Functions
 * @version 8.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function directorist_is_multi_directory_enabled() {
    return (bool) get_directorist_option( 'enable_multi_directory', false );
}

/**
 * Return published listing statistics for one author.
 *
 * The derived listing index is used when it is ready. Canonical WordPress
 * posts and metadata remain the fail-open source in every other state.
 *
 * @since 8.9.4
 *
 * @param int $author_id Author user ID.
 * @return array{listing_count:int,rated_listing_count:int,average_rating:float,source:string}
 */
function directorist_get_author_listing_statistics( $author_id ) {
    return \Directorist\database\Listing_Author_Statistics::get( $author_id );
}

function directorist_is_guest_submission_enabled() {
    return (bool) get_directorist_option( 'guest_listings', false );
}

function directorist_is_featured_listing_enabled( array $context = [] ) {
    return (bool) apply_filters( 'directorist_is_featured_listing_enabled', get_directorist_option( 'enable_featured_listing' ), $context );
}

function directorist_is_monetization_enabled() {
    return (bool) apply_filters( 'directorist_is_monetization_enabled', get_directorist_option( 'enable_monetization' ) );
}

function directorist_get_currency() {
    return get_directorist_option( 'g_currency', 'USD' );
}

function directorist_get_currency_position() {
    return get_directorist_option( 'g_currency_position' );
}

function directorist_can_user_renew_listings() {
    return (bool) get_directorist_option( 'can_renew_listing', true );
}

function directorist_get_owner_notifiable_events() {
    return (array) get_directorist_option( 'notify_user', [] );
}

function directorist_get_admin_notifiable_events() {
    return (array) get_directorist_option( 'notify_admin', [] );
}

function directorist_is_owner_notifiable_event( $event ) {
    return in_array( $event, directorist_get_owner_notifiable_events(), true );
}

function directorist_is_admin_notifiable_event( $event ) {
    return in_array( $event, directorist_get_admin_notifiable_events(), true );
}

function directorist_get_user_types() {
    $user_types = array(
        'general' => __( 'User', 'directorist' ),
        'author'  => __( 'Author', 'directorist' ),
        'guest'   => __( 'Guest', 'directorist' ),
    );

    return apply_filters( 'directorist_get_user_types', $user_types );
}
