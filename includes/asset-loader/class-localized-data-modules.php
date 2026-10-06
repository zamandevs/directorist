<?php
/**
 * Builders for request-scoped Directorist frontend data modules.
 *
 * @author wpWax
 */

namespace Directorist\Asset_Loader;

defined( 'ABSPATH' ) || exit;

class Localized_Data_Modules {
    /**
     * Build one frontend data module.
     *
     * @param string $module Module identifier.
     *
     * @return array
     */
    public static function get( $module ) {
        switch ( $module ) {
            case Localized_Data_Registry::MODULE_BASE:
                return self::base();

            case Localized_Data_Registry::MODULE_SEARCH:
                return self::search();

            case Localized_Data_Registry::MODULE_SUBMISSION:
                return [ 'add_listing_data' => Localized_Data::get_add_listings_data() ];

            case Localized_Data_Registry::MODULE_FAVORITES:
                return self::favorites();

            case Localized_Data_Registry::MODULE_REVIEWS:
                return self::reviews();

            case Localized_Data_Registry::MODULE_AUTHENTICATION:
                return self::authentication() + Localized_Data::login_data();

            case Localized_Data_Registry::MODULE_DASHBOARD:
                return self::dashboard();

            case Localized_Data_Registry::MODULE_CONTACT:
                return [ 'waiting_msg' => __( 'Sending the message, please wait...', 'directorist' ) ];

            case Localized_Data_Registry::MODULE_PAYMENT:
                return self::payment();

            case Localized_Data_Registry::MODULE_MAP:
                return self::map();
        }

        return [];
    }

    private static function base() {
        return [
            'request_headers'                 => [
                'Referer-Page-ID' => get_the_ID(),
            ],
            'nonce'                           => wp_create_nonce( 'atbdp_nonce_action_js' ),
            'directorist_nonce'               => wp_create_nonce( directorist_get_nonce_key() ),
            'ajax_nonce'                      => wp_create_nonce( 'bdas_ajax_nonce' ),
            'is_admin'                        => is_admin(),
            'ajaxurl'                         => admin_url( 'admin-ajax.php' ),
            'assets_url'                      => DIRECTORIST_ASSETS,
            'home_url'                        => home_url(),
            'rest_url'                        => rest_url(),
            'rest_nonce'                      => wp_create_nonce( 'wp_rest' ),
            'nonceName'                       => 'atbdp_nonce_js',
            'rtl'                             => is_rtl() ? 'true' : 'false',
            'plugin_url'                      => ATBDP_URL,
            'currentDate'                     => get_the_date(),
            'lazy_load_taxonomy_fields'       => false,
            'current_page_id'                 => get_the_ID(),
            'icon_markup'                     => '<i class="directorist-icon-mask ##CLASS##" aria-hidden="true" style="--directorist-icon: url(##URL##)"></i>',
            'icon_class_markup'               => '<i class="directorist-icon-mask directorist-icon--font ##CLASS##" aria-hidden="true"></i>',
            'icon_url_markup'                 => '<i class="directorist-icon-mask ##CLASS##" aria-hidden="true" style="--directorist-icon: url(##URL##)"></i>',
            'icon_render_mode'                => \Directorist\Icon_Manager::render_mode(),
            'search_form_default_label'       => __( 'Label', 'directorist' ),
            'search_form_default_placeholder' => __( 'Placeholder', 'directorist' ),
            'add_listing_url'                 => \ATBDP_Permalink::get_add_listing_page_link(),
            'enabled_multi_directory'         => directorist_is_multi_directory_enabled(),
            'site_name'                       => get_bloginfo( 'name' ),
            'dynamic_view_count_cache'        => (bool) get_directorist_option( 'dynamic_view_count_cache', false ),
            'loading_more_text'               => __( 'Loading more...', 'directorist' ),
            'script_debugging'                => get_directorist_option( 'script_debugging', DIRECTORIST_LOAD_MIN_FILES, true ),
            'ajaxnonce'                       => wp_create_nonce( 'bdas_ajax_nonce' ),
            'ajax_url'                        => admin_url( 'admin-ajax.php' ),
        ];
    }

    private static function search() {
        $directory_type  = directorist_get_default_directory();
        $submission_form = get_term_meta( $directory_type, 'submission_form_fields', true );
        $category_field  = ! empty( $submission_form['fields']['category'] ) ? $submission_form['fields']['category'] : [];
        $location_field  = ! empty( $submission_form['fields']['location'] ) ? $submission_form['fields']['location'] : [];

        return [
            'i18n_text' => [
                'category_selection' => ! empty( $category_field['placeholder'] ) ? $category_field['placeholder'] : __( 'Select a category', 'directorist' ),
                'location_selection' => ! empty( $location_field['placeholder'] ) ? $location_field['placeholder'] : __( 'Select a location', 'directorist' ),
                'show_more'          => __( 'Show More', 'directorist' ),
                'show_less'          => __( 'Show Less', 'directorist' ),
            ],
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
        ];
    }

    private static function favorites() {
        return [
            'i18n_text' => [
                'added_favourite' => __( 'Added to favorite', 'directorist' ),
                'please_login'    => __( 'Please login first', 'directorist' ),
            ],
        ];
    }

    private static function reviews() {
        return [
            'warning'                    => __( 'WARNING!', 'directorist' ),
            'success'                    => __( 'SUCCESS!', 'directorist' ),
            'not_add_more_than_one'      => __( 'You can not add more than one review. Refresh the page to edit or delete your review!,', 'directorist' ),
            'duplicate_review_error'     => __( 'Sorry! your review already in process.', 'directorist' ),
            'review_success'             => __( 'Reviews Saved Successfully!', 'directorist' ),
            'review_approval_text'       => get_directorist_option( 'review_approval_text', __( 'Your review has been received. It requires admin approval to publish.', 'directorist' ) ),
            'review_error'               => __( 'Something went wrong. Check the form and try again!!!', 'directorist' ),
            'review_loaded'              => __( 'Reviews Loaded!', 'directorist' ),
            'review_not_available'       => __( 'NO MORE REVIEWS AVAILABLE!,', 'directorist' ),
            'review_have_not_for_delete' => __( 'You do not have any review to delete. Refresh the page to submit new review!!!,', 'directorist' ),
            'review_sure_msg'            => __( 'Are you sure?', 'directorist' ),
            'review_want_to_remove'      => __( 'Do you really want to remove this review!', 'directorist' ),
            'review_delete_msg'          => __( 'Yes, Delete it!', 'directorist' ),
            'review_cancel_btn_text'     => __( 'Cancel', 'directorist' ),
            'review_wrong_msg'           => __( 'Something went wrong!, Try again', 'directorist' ),
            'enable_reviewer_content'    => get_directorist_option( 'enable_reviewer_content', 1 ),
        ];
    }

    private static function authentication() {
        return [
            'login_alert_message' => __( 'Sorry, you need to login first.', 'directorist' ),
        ];
    }

    private static function dashboard() {
        return [
            'listing_remove_title'        => __( 'Are you sure?', 'directorist' ),
            'listing_remove_text'         => __( 'Do you really want to delete this item?!', 'directorist' ),
            'listing_remove_confirm_text' => __( 'Yes, Delete it!', 'directorist' ),
            'listing_delete'              => __( 'Deleted!!', 'directorist' ),
            'listing_error_title'         => __( 'ERROR!!', 'directorist' ),
            'listing_error_text'          => __( 'Something went wrong!!!, Try again', 'directorist' ),
            'upload_pro_pic_title'        => __( 'Select or Upload a profile picture', 'directorist' ),
            'upload_pro_pic_text'         => __( 'Use this Image', 'directorist' ),
            'review_cancel_btn_text'      => __( 'Cancel', 'directorist' ),
        ];
    }

    private static function payment() {
        return [
            'payNow'             => __( 'Pay Now', 'directorist' ),
            'completeSubmission' => __( 'Complete Submission', 'directorist' ),
        ];
    }

    private static function map() {
        return [
            'i18n_text'            => [
                'select_listing_map' => get_directorist_option( 'select_listing_map', 'openstreet' ),
            ],
            'countryRestriction'   => get_directorist_option( 'country_restriction' ),
            'restricted_countries' => get_directorist_option( 'restricted_countries' ),
            'use_def_lat_long'     => get_directorist_option( 'use_def_lat_long' ),
        ];
    }
}
