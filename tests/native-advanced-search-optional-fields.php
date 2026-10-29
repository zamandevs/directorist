<?php
/** Pure actual-native-class/template checks. No WP bootstrap, database, or provider. */
namespace {
    if ( 'cli' !== PHP_SAPI || defined( 'ABSPATH' ) ) { exit( 2 ); }
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
    function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
    function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
    function esc_html__( $value, $domain ) { return $value; }
    function atbdp_search_result_page_link() { echo '/native-search-results/'; }
    function directorist_icon( $value ) {}
    require dirname( __DIR__ ) . '/includes/model/SearchForm.php';
}
namespace Directorist {
    final class NativeAdvancedFieldsFixture extends Directorist_Listing_Search_Form {
        public $rendered = array();
        public function field_template( $field ) {
            $this->rendered[] = $field;
            echo '<span>' . \esc_html( $field['label'] ?? '' ) . '</span>';
        }
    }
}
namespace {
    $checks = 0;
    function check( $condition, $message ) {
        global $checks;
        if ( ! $condition ) { throw new \RuntimeException( $message ); }
        ++$checks;
    }
    function render_case( $path, $groups ) {
        $searchform = ( new \ReflectionClass( \Directorist\NativeAdvancedFieldsFixture::class ) )->newInstanceWithoutConstructor();
        $searchform->form_data = $groups;
        $searchform->options = array();
        $listings = new class {
            public $options = array();
            public function get_directory_type_slug() { return 'owned-cafes'; }
            public function display_search_button() { return false; }
        };
        set_error_handler( static function ( $severity, $message ) { throw new \ErrorException( $message, 0, $severity ); } );
        ob_start();
        try {
            include $path;
            return array( 'html' => ob_get_contents(), 'fields' => $searchform->rendered );
        } finally {
            ob_end_clean();
            restore_error_handler();
        }
    }
    $stage = dirname( __DIR__ ) . '/templates/archive/advance-search-form.php';
    foreach ( array( array(), null, array( array( 'fields' => array() ) ), array( array( 'fields' => array() ), array() ), array( array( 'fields' => array() ), array( 'fields' => 'invalid' ) ) ) as $groups ) {
        $result = render_case( $stage, $groups );
        check( array() === $result['fields'], 'Absent or malformed optional groups render no invented fields.' );
        check( false !== strpos( $result['html'], 'value=\'owned-cafes\'' ), 'Native directory and form shell remain present.' );
    }
    $fields = array(
        'first' => array( 'widget_name' => 'price', 'label' => 'Native price' ),
        'second' => array( 'widget_name' => 'custom_field', 'label' => '<native>' ),
    );
    $groups = array( array( 'fields' => array( array( 'widget_name' => 'title', 'label' => 'Basic field' ) ) ), array( 'fields' => $fields ) );
    $updated = render_case( $stage, $groups );
    check( array_values( $fields ) === $updated['fields'], 'Advanced field values and order are passed unchanged.' );
    check( false !== strpos( $updated['html'], '&lt;native&gt;' ), 'Native field output escaping remains active.' );
    check( false === strpos( $updated['html'], 'Basic field' ), 'Basic fields are not copied into the advanced group.' );
    echo json_encode( array( 'status' => 'PASS', 'checks' => $checks, 'native_class' => 'Directorist\\Directorist_Listing_Search_Form', 'wordpress_bootstrap' => false, 'database_provider_http_calls' => 0 ) ) . "\n";
}
