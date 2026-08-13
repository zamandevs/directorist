<?php

defined( 'ABSPATH' ) || die( 'Direct access is not allowed.' );

if ( ! class_exists( 'Directorist_Multilingual' ) ) :

    class Directorist_Multilingual {
        protected static $hooks_registered = false;

        protected static $adapter_autoloader_registered = false;

        public function __construct() {
            self::register_hooks();
        }

        public static function register_hooks() {
            self::register_adapter_autoloader();

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

        protected static function register_adapter_autoloader() {
            if ( self::$adapter_autoloader_registered || class_exists( 'Directorist_Multilingual_Polylang', false ) ) {
                return;
            }

            spl_autoload_register( [ self::class, 'autoload_adapter' ] );
            self::$adapter_autoloader_registered = true;
        }

        public static function autoload_adapter( $class_name ) {
            if ( 'Directorist_Multilingual_Polylang' !== ltrim( $class_name, '\\' ) ) {
                return;
            }

            require_once ATBDP_CLASS_DIR . 'class-multilingual-polylang.php';
        }
    }

endif;
