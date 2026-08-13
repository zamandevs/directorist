<?php
/**
 * Exact class-map loader for legacy Directorist classes.
 */

namespace Directorist;

defined( 'ABSPATH' ) || exit;

final class Class_Autoloader {
    private static $class_map = [];

    private static $registered = false;

    public static function register( array $class_map ) {
        self::$class_map = array_merge( self::$class_map, $class_map );

        if ( self::$registered ) {
            return;
        }

        spl_autoload_register( [ __CLASS__, 'autoload' ] );
        self::$registered = true;
    }

    public static function autoload( $class_name ) {
        $class_name = ltrim( $class_name, '\\' );

        if ( ! isset( self::$class_map[ $class_name ] ) ) {
            return;
        }

        require_once self::$class_map[ $class_name ];
    }
}
