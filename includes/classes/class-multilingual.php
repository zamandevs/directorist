<?php

defined( 'ABSPATH' ) || die( 'Direct access is not allowed.' );

if ( ! class_exists( 'Directorist_Multilingual' ) ) :

    class Directorist_Multilingual {
        protected static $hooks_registered = false;

        public function __construct() {
            self::register_hooks();
        }

        public static function register_hooks() {
            if ( self::$hooks_registered ) {
                return;
            }

            self::$hooks_registered = true;
            add_action( 'plugins_loaded', [ self::class, 'init' ], 20 );
        }

        public static function init() {
            if ( ! function_exists( 'PLL' ) ) {
                return;
            }

            new Directorist_Multilingual_Polylang();
        }
    }

endif;
