<?php
/**
 * Singleton class for handling widgets.
 * 
 * @author wpWax
 */

namespace Directorist\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

class Init {
    protected static $instance = null;

    private function __construct() {
        spl_autoload_register( [ __CLASS__, 'autoload_widget' ] );
        add_action( 'widgets_init', [ $this, 'register_widgets' ] );

        Widget_Fields::init();
    }

    public static function instance() {
        if ( null == self::$instance ) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public function register_widgets() {
        foreach ( self::get_widget_definitions() as $widget ) {
            register_widget( $widget['class'] );
        }
    }

    public static function get_widget_definitions() {
        return apply_filters(
            'directorist_widget_definitions',
            [
                'bdpl_widget' => [ 'class' => __NAMESPACE__ . '\\Popular_Listings', 'file' => 'popular-listings.php' ],
                'bdvd_widget' => [ 'class' => __NAMESPACE__ . '\\Listing_Video', 'file' => 'listing-video.php' ],
                'bdco_widget' => [ 'class' => __NAMESPACE__ . '\\Contact_Form', 'file' => 'contact-form.php' ],
                'bdsb_widget' => [ 'class' => __NAMESPACE__ . '\\Submit_Listing', 'file' => 'submit-listing.php' ],
                'bdlf_widget' => [ 'class' => __NAMESPACE__ . '\\Login_Form', 'file' => 'login-form.php' ],
                'bdcw_widget' => [ 'class' => __NAMESPACE__ . '\\All_Categories', 'file' => 'all-categories.php' ],
                'bdlw_widget' => [ 'class' => __NAMESPACE__ . '\\All_Locations', 'file' => 'all-locations.php' ],
                'bdtw_widget' => [ 'class' => __NAMESPACE__ . '\\All_Tags', 'file' => 'all-tags.php' ],
                'bdsw_widget' => [ 'class' => __NAMESPACE__ . '\\Search_Form', 'file' => 'search-form.php' ],
                'bdmw_widget' => [ 'class' => __NAMESPACE__ . '\\Single_Map', 'file' => 'single-map.php' ],
                'bdsl_widget' => [ 'class' => __NAMESPACE__ . '\\Similar_Listing', 'file' => 'similar-listing.php' ],
                'bdsi_widget' => [ 'class' => __NAMESPACE__ . '\\Author_Info', 'file' => 'author-info.php' ],
                'bdfl_widget' => [ 'class' => __NAMESPACE__ . '\\Featured_Listing', 'file' => 'featured-listing.php' ],
            ]
        );
    }

    public static function autoload_widget( $class_name ) {
        foreach ( self::get_widget_definitions() as $widget ) {
            if ( $widget['class'] !== $class_name ) {
                continue;
            }

            require_once __DIR__ . '/' . $widget['file'];
            return;
        }
    }
}
