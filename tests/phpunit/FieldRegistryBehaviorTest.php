<?php
/**
 * Behavior locks for the public field registry API.
 */

class Directorist_Field_Registry_Behavior_Test extends WP_UnitTestCase {
    private $field_types = [
        'text',
        'textarea',
        'html',
        'button',
        'number',
        'url',
        'date',
        'time',
        'color_picker',
        'select',
        'checkbox',
        'radio',
        'file',
        'description',
        'view_count',
        'email',
        'video',
        'switch',
        'locations',
        'categories',
        'tags',
        'pricing',
        'map',
        'social_info',
        'image_upload',
    ];

    public function test_core_registration_does_not_construct_field_prototypes() {
        $reflection = new ReflectionClass( Directorist\Fields\Fields::class );
        $fields     = $reflection->getProperty( 'fields' );
        $classes    = $reflection->getProperty( 'field_classes' );

        $fields->setAccessible( true );
        $classes->setAccessible( true );

        $this->assertSame( [], $fields->getValue() );
        $this->assertCount( count( $this->field_types ), $classes->getValue() );
    }

    public function test_all_core_field_types_are_registered() {
        foreach ( $this->field_types as $field_type ) {
            $this->assertTrue( Directorist\Fields\Fields::exists( $field_type ), $field_type );
            $this->assertInstanceOf( Directorist\Fields\Base_Field::class, Directorist\Fields\Fields::get( $field_type ) );
        }
    }

    public function test_get_all_returns_field_objects_keyed_by_type() {
        $fields = Directorist\Fields\Fields::get_all();

        foreach ( $this->field_types as $field_type ) {
            $this->assertArrayHasKey( $field_type, $fields );
            $this->assertSame( $field_type, $fields[ $field_type ]->type );
        }
    }

    public function test_create_returns_concrete_field_with_supplied_properties() {
        $field = Directorist\Fields\Fields::create(
            [
                'widget_name' => 'text',
                'field_key'   => 'company_name',
                'widget_key'  => 'company_name_widget',
                'required'    => true,
            ]
        );

        $this->assertInstanceOf( Directorist\Fields\Text_Field::class, $field );
        $this->assertSame( 'company_name', $field->get_key() );
        $this->assertSame( 'company_name_widget', $field->get_internal_key() );
        $this->assertTrue( $field->is_required() );
    }

    public function test_legacy_field_mapping_remains_filterable() {
        $filter = static function ( $map ) {
            $map['extension_fixture'] = 'number';
            return $map;
        };

        add_filter( 'directorist_listing_form_fields_class_map', $filter );
        $field = Directorist\Fields\Fields::create(
            [
                'widget_name' => 'extension_fixture',
                'field_key'   => 'fixture',
            ]
        );
        remove_filter( 'directorist_listing_form_fields_class_map', $filter );

        $this->assertInstanceOf( Directorist\Fields\Number_Field::class, $field );
    }

    public function test_unknown_field_falls_back_to_base_field() {
        $field = Directorist\Fields\Fields::create(
            [
                'widget_name' => 'unknown_extension_field',
                'field_key'   => 'fixture',
            ]
        );

        $this->assertSame( Directorist\Fields\Base_Field::class, get_class( $field ) );
    }

    public function test_lazy_fields_preserve_sanitization_and_validation_behavior() {
        $text   = Directorist\Fields\Fields::create(
            [
                'widget_name' => 'text',
                'field_key'   => 'company_name',
            ]
        );
        $number = Directorist\Fields\Fields::create(
            [
                'widget_name' => 'number',
                'field_key'   => 'employee_count',
                'min_value'   => 2,
                'max_value'   => 10,
                'widget_key'  => 'employee_count_widget',
            ]
        );

        $this->assertSame( 'Example Company', $text->sanitize( [ 'company_name' => '<b>Example</b> Company' ] ) );
        $this->assertSame( 4.57, $number->sanitize( [ 'employee_count' => '4.567' ] ) );
        $this->assertTrue( $number->validate( [ 'employee_count' => 5 ] ) );
        $this->assertFalse( $number->validate( [ 'employee_count' => 11 ] ) );
    }

    public function test_extension_object_registration_remains_supported() {
        $field = new class() extends Directorist\Fields\Base_Field {
            public $type = 'extension_object_fixture';
        };

        Directorist\Fields\Fields::register( $field );

        $this->assertTrue( Directorist\Fields\Fields::exists( $field->type ) );
        $this->assertSame( $field, Directorist\Fields\Fields::get( $field->type ) );
        $this->assertInstanceOf(
            get_class( $field ),
            Directorist\Fields\Fields::create(
                [
                    'widget_name' => $field->type,
                    'field_key'   => 'extension_value',
                ]
            )
        );
    }
}
