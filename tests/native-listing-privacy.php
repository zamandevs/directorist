<?php
/**
 * Native listing privacy regression on authorized local synthetic fixtures.
 *
 * @package Directorist
 */

use Directorist\Listing_Visibility;
use Directorist\Directorist_Listings;
use Directorist\Directorist_Single_Listing;
use Directorist\Helper;
use Directorist\Schema;
use Directorist\database\DB;
use Directorist_Divi\App\Helpers\ListingVisibility as DiviVisibility;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'DAI_CORE_PRIVACY_QA' ) || 'http://dirwp7.test' !== home_url() || 22 !== get_current_user_id() ) {
    throw new RuntimeException( 'Use only the authorized local synthetic listing fixtures.' );
}

$has_password_form = static function ( $html ) { return false !== strpos( $html, 'action=postpass' ) && preg_match( '/name=[\"\']post_password[\"\']/', $html ); };
$checks = [];
$assert = static function ( $ok, $label ) use ( &$checks ) {
    if ( ! $ok ) { throw new RuntimeException( $label ); }
    $checks[] = $label;
};
$facts_file = getenv( 'DAI_CORE_PRIVACY_FACTS_FILE' ) ?: WP_PLUGIN_DIR . '/directorist-ai-pro/.qa/release-scenarios-20261026/core/source-facts.private.json';
$fixtures = json_decode( file_get_contents( $facts_file ), true );
$native_before = [];
foreach ( $fixtures as $id => $fixture ) {
    $post = get_post( $id );
    $assert( $post->post_status === $fixture['status'] && wp_strip_all_tags( $post->post_content ) === $fixture['description'] && $post->post_password === $fixture['password'] && get_post_meta( $id, '_address', true ) === $fixture['address'], 'Current owned fixture facts and visibility exact ' . $id );
    $native_before[$id] = hash( 'sha256', serialize( [ (array) $post, get_post_meta( $id ) ] ) );
}
$original_actor = get_current_user_id(); $original_get = $_GET; $original_request = $_REQUEST; $original_cookie = $_COOKIE; $original_nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? null;
$original_query = $GLOBALS['wp_query']; $original_main = $GLOBALS['wp_the_query']; $original_post = $GLOBALS['post'] ?? null;
$make_single = static function ( $id ) {
    $query = new WP_Query(); $query->is_single = true; $query->is_singular = true; $query->queried_object = get_post( $id ); $query->queried_object_id = $id;
    $GLOBALS['wp_query'] = $query; $GLOBALS['wp_the_query'] = $query; $GLOBALS['post'] = get_post( $id );
};
try {
    wp_set_current_user( 0 ); $_GET = []; $_REQUEST = []; $_COOKIE = []; unset( $_SERVER['HTTP_X_WP_NONCE'] );
    $assert( class_exists( Listing_Visibility::class ), 'New helper loads through the existing native helpers convention' );
    $GLOBALS['post'] = get_post( 6102 );
    $assert( ! Listing_Visibility::is_public( null ) && ! Listing_Visibility::can_read_rest( 0 ), 'Missing identities never fall back to a public global post' );
    foreach ( [ 6107, 6108, 6109, 999999999 ] as $id ) {
        $assert( ! Listing_Visibility::can_read_rest( $id ), 'Anonymous native metadata withheld ' . $id );
    }
    $assert( Listing_Visibility::is_public( 6102 ), 'Public native listing remains eligible' );

    $direct = DB::get_listings_data( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'any', 'post__in' => [ 6102, 6107, 6108, 6109 ], 'posts_per_page' => 100 ] );
    $assert( [ 6102 ] === $direct->ids && 1 === $direct->total && 1 === $direct->total_pages, 'Shared native SQL boundary protects results, count and pagination' );
    $cache_found = false;
    $user_cache = wp_cache_get( 22, 'user_meta', false, $cache_found );
    $saved_favorites = get_user_meta( 22, 'atbdp_favourites', false );
    $favorite_actor = get_current_user_id();
    $favorite_nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? null;
    $favorite_filter = static function ( $favorites, $user_id ) { return 22 === (int) $user_id ? [ 6102, 6107, 6108, 6109 ] : $favorites; };
    add_filter( 'directorist_user_favorites', $favorite_filter, 10, 2 );
    try {
        $assert( [ 6102, 6107, 6108, 6109 ] === directorist_get_user_favorites( 22 ), 'Owned favorite fixture contains genuine public/private/draft/password IDs without saved changes' );
        $favorites = DB::favorite_listings_query( 22 );
        $assert( [ 6102 ] === array_map( 'intval', wp_list_pluck( $favorites->posts, 'ID' ) ), 'Public favorites excludes private/draft/password entries before rendering' );
        $assert( 'publish' === $favorites->get( 'post_status' ) && false === $favorites->get( 'has_password' ) && 1 === (int) $favorites->found_posts, 'Favorite membership and count use the explicit public query rule' );
        wp_set_current_user( 22 ); $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
        $favorites = DB::favorite_listings_query( 22 );
        $assert( [ 6102 ] === array_map( 'intval', wp_list_pluck( $favorites->posts, 'ID' ) ), 'Even an authorized edit-preview nonce cannot widen public favorites' );
        $items = \Directorist\Directorist_Listing_Dashboard::instance()->fav_listing_items();
        $assert( [ 6102 ] === array_map( static function ( $item ) { return (int) $item['obj']->ID; }, $items ), 'Actual native Favorites dashboard caller renders only the public fixture' );
    } finally {
        remove_filter( 'directorist_user_favorites', $favorite_filter, 10 );
        wp_set_current_user( $favorite_actor );
        if ( null === $favorite_nonce ) { unset( $_SERVER['HTTP_X_WP_NONCE'] ); } else { $_SERVER['HTTP_X_WP_NONCE'] = $favorite_nonce; }
        $favorites_unchanged = $saved_favorites === get_user_meta( 22, 'atbdp_favourites', false );
        if ( $cache_found ) { wp_cache_set( 22, $user_cache, 'user_meta' ); } else { wp_cache_delete( 22, 'user_meta' ); }
    }
    $cache_after_found = false; $cache_after = wp_cache_get( 22, 'user_meta', false, $cache_after_found );
    $assert( $favorites_unchanged, 'Exact saved favorite metadata remains unchanged' );
    $assert( $cache_found === $cache_after_found && ( ! $cache_found || $user_cache === $cache_after ), 'Exact user metadata process-cache presence and value restored' );
    foreach ( [ [], [ 'q' => 'Scenario' ], [ 'in_cat' => 1108 ] ] as $search ) {
        $_GET = $search; $_REQUEST = $search;
        $native = new Directorist_Listings( [ 'directory_type' => 'general', 'listings_per_page' => 100 ] );
        $assert( ! array_intersect( [ 6107, 6108, 6109 ], $native->post_ids() ), 'Ordinary native blank/search/category query excludes withheld fixtures' );
        $assert( in_array( 6102, $native->post_ids(), true ), 'Ordinary native query keeps matching public positive' );
        $assert( (int) $native->query_results->total === count( $native->post_ids() ), 'Native full-page membership and reported count match' );
    }
    $_GET = []; $_REQUEST = [];
    $make_single( 6109 );
    $assert( post_password_required( 6109 ) && ! Listing_Visibility::can_read_single( 6109 ), 'Locked ordinary singular remains protected' );
    $root = Helper::get_template_contents( 'single-contents' );
    $assert( $has_password_form( $root ) && false === strpos( $root, $fixtures[6109]['description'] ), 'Full native single template returns the WordPress password form only' );
    $assert( '' === Helper::get_template_contents( 'single/header' ), 'Nested native single templates cannot expose protected header metadata' );
    $reflection = new ReflectionClass( Directorist_Single_Listing::class ); $model = $reflection->newInstanceWithoutConstructor(); $constructor = $reflection->getConstructor(); $constructor->setAccessible( true ); $constructor->invoke( $model, 6109 );
    foreach ( [ 'get_contents', 'single_page_content', 'render_shortcode_single_listing' ] as $method ) {
        $content = $model->$method();
        $assert( $has_password_form( $content ) && false === strpos( $content, $fixtures[6109]['description'] ), 'Native single entry point withholds protected content ' . $method );
    }
    $assert( [] === Schema::get_schema(), 'Native JSON-LD emits no locked listing facts' );
    $seo = new ATBDP_SEO(); $meta = $seo->get_single_listing_page_seo_meta( [ 'description' => 'prior', 'image' => 'prior' ] );
    $assert( '' === $meta['description'] && '' === $meta['image'], 'Native SEO withholds locked description and imagery' );

    require_once ABSPATH . WPINC . '/class-phpass.php'; $hasher = new PasswordHash( 8, true );
    $_COOKIE['wp-postpass_' . COOKIEHASH] = $hasher->HashPassword( $fixtures[6109]['password'] );
    $assert( ! post_password_required( 6109 ) && Listing_Visibility::can_read_single( 6109 ), 'Actual WordPress postpass grants the exact ordinary singular page' );
    $assert( false !== strpos( $model->get_contents(), $fixtures[6109]['description'] ), 'Unlocked native single content retains the genuine source description' );
    $assert( ! Listing_Visibility::can_read_rest( 6109 ), 'A postpass cookie never grants native REST metadata access' );
    $public_bulk = DB::get_listings_data( [ 'post_type' => ATBDP_POST_TYPE, 'post__in' => [ 6102, 6109 ], 'posts_per_page' => 100 ] );
    $assert( [ 6102 ] === $public_bulk->ids, 'Postpass cannot widen public bulk listing membership' );
    $assert( DiviVisibility::can_render( 6109 ), 'Divi fields preserve the same legitimate ordinary-single postpass access' );
    $request = new WP_REST_Request( 'GET', '/directorist/divi/listings-card/address' ); $request->set_param( 'listing_id', 6109 );
    $assert( ! DiviVisibility::can_render( 6109, $request ), 'Divi explicit REST read cannot use a postpass cookie' );
    $make_single( 6102 );
    $assert( ! Listing_Visibility::can_read_single( 6109 ) && ! DiviVisibility::can_render( 6109 ), 'A foreign queried singular never grants protected source access' );
    $make_single( 6109 ); $ajax = static function () { return true; }; add_filter( 'wp_doing_ajax', $ajax );
    try { $assert( ! Listing_Visibility::can_read_single( 6109 ) && ! DiviVisibility::can_render( 6109 ), 'AJAX cannot borrow ordinary singular postpass authority' ); }
    finally { remove_filter( 'wp_doing_ajax', $ajax ); }

    wp_set_current_user( 22 ); $_COOKIE = []; $_GET = []; $_REQUEST = [ 'preview' => 'true', 'directorist_divi_admin_preview' => 1 ];
    $assert( ! Listing_Visibility::can_preview( 6109 ), 'Login and preview flags alone grant no native edit-preview authority' );
    $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
    foreach ( [ 6107, 6108, 6109 ] as $id ) { $assert( Listing_Visibility::can_preview( $id ) && Listing_Visibility::can_read_rest( $id ), 'Exact native nonce and edit capability preserve preview ' . $id ); }
    $args = Listing_Visibility::query_args( [ 'post__in' => [ 6109 ], 'post_status' => 'publish' ] );
    $assert( ! isset( $args['has_password'] ), 'An explicitly selected authorized native edit preview is retained' );
    $args = Listing_Visibility::query_args( [ 'post__in' => [ 6109 ], 'post_status' => 'publish' ], false );
    $assert( false === $args['has_password'], 'Public REST bulk remains password-free even for editor identity' );
    $deny = static function ( $caps, $cap, $user_id, $arguments ) { return 'edit_post' === $cap && 6109 === (int) ( $arguments[0] ?? 0 ) ? [ 'do_not_allow' ] : $caps; };
    add_filter( 'map_meta_cap', $deny, 10, 4 );
    try { $assert( ! Listing_Visibility::can_preview( 6109 ) && ! Listing_Visibility::can_read_rest( 6109 ), 'A nonce never substitutes for exact listing edit permission' ); }
    finally { remove_filter( 'map_meta_cap', $deny, 10 ); }
    unset( $_SERVER['HTTP_X_WP_NONCE'] ); $_REQUEST = [ 'preview_id' => '6109', 'preview_nonce' => wp_create_nonce( 'post_preview_6109' ) ];
    $assert( Listing_Visibility::can_preview( 6109 ) && ! Listing_Visibility::can_preview( 6107 ), 'Native WordPress preview nonce is bound to the exact post ID' );
    $_REQUEST = [ 'preview_id' => [ 6109 ], 'preview_nonce' => 'invalid' ];
    $assert( ! Listing_Visibility::can_preview( 6109 ), 'Malformed native preview input grants no authority' );
} finally {
    wp_set_current_user( $original_actor ); $_GET = $original_get; $_REQUEST = $original_request; $_COOKIE = $original_cookie;
    $GLOBALS['wp_query'] = $original_query; $GLOBALS['wp_the_query'] = $original_main; $GLOBALS['post'] = $original_post;
    if ( null === $original_nonce ) { unset( $_SERVER['HTTP_X_WP_NONCE'] ); } else { $_SERVER['HTTP_X_WP_NONCE'] = $original_nonce; }
}
foreach ( $native_before as $id => $hash ) { $assert( $hash === hash( 'sha256', serialize( [ (array) get_post( $id ), get_post_meta( $id ) ] ) ), 'Owned listing row and metadata byte-preserved during native suite ' . $id ); }
echo wp_json_encode( [ 'result' => 'PASS', 'checks' => count( $checks ), 'details' => $checks, 'fixtures_unchanged' => true, 'new_helper_existing_autoload' => true, 'provider_calls' => 0, 'source_writes' => 0 ] ) . "\n";
