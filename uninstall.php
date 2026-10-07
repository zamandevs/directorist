<?php
/**
 * @package Directorist
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

include_once( "directorist-base.php" );

// The early cache must never survive removal of the core engine files.
if ( function_exists( 'directorist_page_cache_deactivate_builtin_runtime' ) ) {
    directorist_page_cache_deactivate_builtin_runtime();
}

// Clear schedules
wp_clear_scheduled_hook( 'directorist_hourly_scheduled_events' );

function directorist_uninstall() {
    global $wpdb;

    $process_prefix        = 'wp_' . get_current_blog_id() . '_';
    $listing_index_process = $process_prefix . 'directorist_listing_index';
    $warm_process          = $process_prefix . 'directorist_page_cache_warm';
    $background_processes  = [
        $listing_index_process,
        $warm_process,
        $process_prefix . 'directorist_page_cache_cleanup',
        $process_prefix . 'directorist_performance_cache_job',
        $process_prefix . 'directorist_performance_resource_catalog',
    ];

    wp_clear_scheduled_hook( 'directorist_process_listing_index' );
    wp_clear_scheduled_hook( 'directorist_page_cache_retry_warm_url' );
    wp_clear_scheduled_hook( 'directorist_page_cache_retry_warm_batch' );
    wp_clear_scheduled_hook( 'directorist_performance_cache_job_monitor' );
    wp_clear_scheduled_hook( 'directorist_page_cache_reconcile_resources' );
    wp_clear_scheduled_hook( 'directorist_page_cache_hourly_resource_reconciliation' );
    wp_clear_scheduled_hook( 'directorist_page_cache_daily_resource_reconciliation' );

    $queue_table  = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
    $queue_column = is_multisite() ? 'meta_key' : 'option_name';

    foreach ( $background_processes as $process ) {
        wp_clear_scheduled_hook( $process . '_cron' );
        delete_site_transient( $process . '_process_lock' );

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . $queue_table . ' WHERE ' . $queue_column . ' LIKE %s',
                $wpdb->esc_like( $process . '_batch_' ) . '%'
            )
        );
    }

    delete_transient( $warm_process . '_dedupe' );
    delete_transient( $warm_process . '_deferred_lock' );
    delete_option( $warm_process . '_deferred' );
    delete_option( $warm_process . '_deferred_lock' );
    delete_site_option( $warm_process . '_health' );
    delete_site_option( $warm_process . '_generation' );
    \Directorist\Cache\Performance_Job_Ledger::cleanup_all();

    // Delete selected pages
    wp_delete_post( get_directorist_option( 'add_listing_page' ), true );
    wp_delete_post( get_directorist_option( 'all_listing_page' ), true );
    wp_delete_post( get_directorist_option( 'user_dashboard' ), true );
    wp_delete_post( get_directorist_option( 'author_profile_page' ), true );
    wp_delete_post( get_directorist_option( 'all_categories_page' ), true );
    wp_delete_post( get_directorist_option( 'single_category_page' ), true );
    wp_delete_post( get_directorist_option( 'all_locations_page' ), true );
    wp_delete_post( get_directorist_option( 'single_location_page' ), true );
    wp_delete_post( get_directorist_option( 'single_tag_page' ), true );
    wp_delete_post( get_directorist_option( 'custom_registration' ), true );
    wp_delete_post( get_directorist_option( 'user_login' ), true );
    wp_delete_post( get_directorist_option( 'search_listing' ), true );
    wp_delete_post( get_directorist_option( 'search_result_page' ), true );
    wp_delete_post( get_directorist_option( 'checkout_page' ), true );
    wp_delete_post( get_directorist_option( 'payment_receipt_page' ), true );
    wp_delete_post( get_directorist_option( 'transaction_failure_page' ), true );
    wp_delete_post( get_directorist_option( 'privacy_policy' ), true );
    wp_delete_post( get_directorist_option( 'terms_conditions' ), true );

    // Delete posts and data
    $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ('at_biz_dir', 'atbdp_fields', 'atbdp_orders', 'atbdp_listing_review', 'atbdp_pricing_plans', 'dcl_claim_listing');" );

    // Delete all metabox
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts});" );

    // Delete term relationships
    $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id NOT IN (SELECT ID FROM {$wpdb->posts});" );

    // Delete all taxonomy
    $wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'at_biz_dir-location'" );
    $wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'at_biz_dir-category'" );
    $wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'at_biz_dir-tags'" );
    $wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'atbdp_listing_types'" );

    // Delete all term meta
    $wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id NOT IN (SELECT term_id FROM {$wpdb->term_taxonomy});" );
    $wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id NOT IN (SELECT term_id FROM {$wpdb->term_taxonomy});" );

    // Delete review database
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}atbdp_review" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}directorist_listing_field_index" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}directorist_listing_field_exact_index" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}directorist_listing_field_number_index" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}directorist_listing_field_date_index" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}directorist_listing_field_text_index" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}directorist_listing_index" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}directorist_listing_index_state" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}directorist_performance_resources" );

    // Delete usermeta
    $wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key LIKE '%atbdp%';" );
    $wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key = 'pro_pic';" );

    // Delete all the Plugin Options
    $atbdp_settings = [
        "{$wpdb->prefix}atbdp_review_db_version",
        'atbdp_option',
        'widget_bdpl_widget',
        'widget_bdvd_widget',
        'widget_bdco_widget',
        'widget_bdsb_widget',
        'widget_bdlf_widget',
        'widget_bdcw_widget',
        'widget_bdlw_widget',
        'widget_bdtw_widget',
        'widget_bdsw_widget',
        'widget_bdmw_widget',
        'widget_bdamw_widget',
        'widget_bdsl_widget',
        'widget_bdsi_widget',
        'widget_bdfl_widget',
        'atbdp_meta_version',
        'atbdp_pages_version',
        'atbdp_roles_mapped',
        'atbdp_roles_version',
        'at_biz_dir-location_children',
        'at_biz_dir-category_children',
        'directorist_listing_index_schema_version',
        'directorist_listing_index_data_version',
        'directorist_listing_index_status',
        'directorist_listing_index_schema_health',
        'directorist_listing_index_schema_repair_required',
        'directorist_listing_index_enabled',
        'directorist_listing_index_mutation_version',
        'directorist_listing_index_last_rebuild_mutations',
        'directorist_listing_index_ambiguous_meta',
        'directorist_listing_index_rebuild_cursor',
        'directorist_listing_index_rebuild_phase',
        'directorist_listing_index_rebuild_verification',
        'directorist_listing_index_rebuild_started_mutation',
        'directorist_listing_index_rebuild_deployment',
        'directorist_listing_index_trusted_deployment',
        'directorist_listing_index_process_lock',
        'directorist_listing_index_retry_after',
        'directorist_page_cache_performance',
        'directorist_page_cache_events',
        'directorist_performance_cache_job',
        'directorist_performance_resource_schema_version',
        'directorist_performance_resource_catalog_status',
        'directorist_page_cache_wpsc_compatibility_v1',
    ];

    foreach ( $atbdp_settings as $settings ) {
        delete_option( $settings );
    }

    delete_site_option( 'directorist_listing_index_deployment_token' );
    delete_site_option( 'directorist_page_cache_lifecycle_state' );
    delete_site_option( 'directorist_page_cache_lifecycle_pending' );
    delete_site_option( 'directorist_page_cache_dropin_owner_v1' );
    delete_site_option( 'directorist_page_cache_cleanup_pending' );
}

if ( is_multisite() ) {
    $original_blog_id = get_current_blog_id();
    $sites = get_sites();

    foreach ( $sites as $site ) {
        switch_to_blog( $site->blog_id );
        
        if ( ! get_directorist_option( 'enable_uninstall',0 ) ) {
            continue;
        }

        directorist_uninstall();
        restore_current_blog();
    }

    switch_to_blog( $original_blog_id );
} else {
    if ( ! get_directorist_option( 'enable_uninstall',0 ) ) {
        return;
    }
    
    directorist_uninstall();
}
