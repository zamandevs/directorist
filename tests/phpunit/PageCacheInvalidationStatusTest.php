<?php

use Directorist\Cache\Abstract_Cache_Provider;
use Directorist\Cache\Performance_Settings;
use Directorist\Cache\Performance_Status;

final class Directorist_Status_Contract_Test_Provider extends Abstract_Cache_Provider {
    public $throws = false;
    public function __construct( array $operations, $available = true ) {
        $this->configure( 'test-status', '1', $operations, $available );
    }
    public function get_status() {
        if ( $this->throws ) { throw new RuntimeException( 'provider unavailable' ); }
        return parent::get_status();
    }
}

final class Directorist_Page_Cache_Invalidation_Status_Test extends WP_UnitTestCase {
    public function tear_down() {
        delete_option( Performance_Settings::OPTION_NAME );
        parent::tear_down();
    }
    public static function contracts() {
        return [
            [ [ 'purge_dependencies', 'purge_generations' ], true, true ],
            [ [ 'purge_site' ], true, true ],
            [ [ 'delete_url' ], true, true ],
            [ [ 'delete_urls' ], true, true ],
            [ [ 'purge_dependencies' ], true, false ],
            [ [ 'purge_generations' ], true, false ],
            [ [ 'warm_urls' ], true, false ],
            [ [ 'purge_site' ], false, false ],
        ];
    }

    /** @dataProvider contracts */
    public function test_status_reports_supported_invalidation_contract( $names, $available, $expected ) {
        $operations = array_fill_keys( $names, '__return_true' );
        $settings = new Performance_Settings();
        $status = new Performance_Status( new Directorist_Status_Contract_Test_Provider( $operations, $available ), $settings );
        $this->assertSame( $expected, $status->snapshot( [ 'include_inventory' => false ] )['automation']['invalidation'] );
    }

    public function test_provider_exception_never_advertises_automation() {
        $provider = new Directorist_Status_Contract_Test_Provider( [ 'purge_site' => '__return_true' ] );
        $provider->throws = true;
        $status = new Performance_Status( $provider );
        $this->assertFalse( $status->snapshot( [ 'include_inventory' => false ] )['automation']['invalidation'] );
    }
}
