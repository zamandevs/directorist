<?php
/**
 * Directorist Fields manager class.
 *
 */
namespace Directorist\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Exception;

class Fields {
    private static $fields = [];

    private static $field_classes = [];

    public static function register( $field ) {
        try {
            if ( ! is_subclass_of( $field, Base_Field::class ) ) {
                throw new Exception( 'Must be a subclass of <code>' . Base_Field::class . '</code>' );
            }

            if ( empty( $field->type ) ) {
                throw new Exception( 'The type must be set' );
            }

            if ( self::exists( $field->type ) ) {
                throw new Exception( 'Field type already registered: ' . $field->type );
            }

            self::$fields[ $field->type ]        = $field;
            self::$field_classes[ $field->type ] = get_class( $field );
        } catch ( Exception $e ) {
            wp_die( esc_html( $e->getMessage() ) );
        }
    }

    /**
     * Register a field class without constructing its prototype.
     *
     * @param string $field_type Field type.
     * @param string $class_name Field class name.
     * @return void
     */
    public static function register_class( $field_type, $class_name ) {
        try {
            if ( ! is_subclass_of( $class_name, Base_Field::class ) ) {
                throw new Exception( 'Must be a subclass of <code>' . Base_Field::class . '</code>' );
            }

            if ( empty( $field_type ) ) {
                throw new Exception( 'The type must be set' );
            }

            if ( self::exists( $field_type ) ) {
                throw new Exception( 'Field type already registered: ' . $field_type );
            }

            self::$field_classes[ $field_type ] = $class_name;
        } catch ( Exception $e ) {
            wp_die( esc_html( $e->getMessage() ) );
        }
    }

    public static function exists( $field_type ) {
        return isset( self::$fields[ $field_type ] ) || isset( self::$field_classes[ $field_type ] );
    }

    /**
     * @param $field_type
     *
     * @return Base_Field
     */
    public static function get( $field_type ) {
        if ( isset( self::$fields[ $field_type ] ) ) {
            return self::$fields[ $field_type ];
        }

        if ( ! isset( self::$field_classes[ $field_type ] ) ) {
            return false;
        }

        $class_name                  = self::$field_classes[ $field_type ];
        self::$fields[ $field_type ] = new $class_name();

        return self::$fields[ $field_type ];
    }

    /**
     * Return all the registered field types.
     *
     * @return Base_Field[]
     */
    public static function get_all() {
        foreach ( array_keys( self::$field_classes ) as $field_type ) {
            self::get( $field_type );
        }

        return self::$fields;
    }

    /**
     * Creates a Field object from an array of field properties.
     *
     * @param array|Base_Field $properties
     *
     * @return Base_Field | bool
     */
    public static function create( $properties ) {
        if ( $properties instanceof Base_Field ) {
            $type = $properties->type;
        } else {
            $type = isset( $properties['widget_name'] ) ? $properties['widget_name'] : '';
        }

        $type = self::translate_key_to_field( $type );

        if ( empty( $type ) || ! self::exists( $type ) ) {
            return new Base_Field( $properties );
        }

        $class_name = self::$field_classes[ $type ];
        $field      = new $class_name( $properties );

        return $field;
    }

    public static function translate_key_to_field( $type ) {
        $map = [
            'address'            => 'text',
            'category'           => 'categories',
            'color'              => 'color_picker',
            'excerpt'            => 'textarea',
            'fax'                => 'text',
            'hide_contact_owner' => 'switch',
            'title'              => 'text',
            'location'           => 'locations',
            'phone'              => 'text',
            'phone2'             => 'text',
            'tag'                => 'tags',
            'tagline'            => 'text',
            'website'            => 'url',
            'zip'                => 'text',
            // Custom fields in themes
            'ddoctors-accept-new-patient' => 'text',
            'ddoctors-gender'             => 'text',
            'dlawyers_free_consultation'  => 'text',
            'drestaurant_sale_badge'      => 'text',
            'dcar-mileage'                => 'number',
            'dcar-year'                   => 'number',
            'ddoctors-age'                => 'number',
            'dhotels-number'              => 'number',
            'dhotels-area'                => 'number',
            'dlawyers_age'                => 'number',
            'dlawyers_gender'             => 'radio',
            'drealestate-number'          => 'number',
            'drealestate-area'            => 'number',
            'dclassified-bullet-list'      => 'textarea',
            'ddoctors-bullet-list'        => 'textarea',
            'djobs_bullet_list'           => 'textarea',
            'dlawyers_bullet_list'        => 'textarea',
            'dplace_bullet_list'          => 'textarea',
            'dplace_table'                => 'textarea',
            'drestaurant_feature_list'    => 'textarea',
            'onelisting_feature_list'     => 'textarea',
            'pricing-period'              => 'checkbox',
        ];

        $map = apply_filters( 'directorist_listing_form_fields_class_map', $map );

        return isset( $map[ $type ] ) ? $map[ $type ] : $type;
    }
}
