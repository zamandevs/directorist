<?php
/**
 * Behavior locks for legacy include and class-loading boundaries.
 */

class Directorist_Include_Bootstrap_Behavior_Test extends WP_UnitTestCase {
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_default_bootstrap_defers_request_owned_domain_symbols() {
        $bootstrap_symbols = [
            'Directorist\\Contracts\\PaymentInterface',
            'Directorist\\database\\Listing_Index',
            'Directorist\\database\\Listing_Index_Directory_State',
            'Directorist\\database\\Listing_Index_Lifecycle',
            'Directorist\\database\\Listing_Index_Maintenance',
            'Directorist\\database\\Listing_Index_Schema',
            'Directorist\\Enums\\Order\\DiscountType',
            'Directorist\\Enums\\Order\\Status',
            'Directorist\\Enums\\Order\\TaxType',
            'Directorist\\Enums\\Payment\\Status',
            'Directorist\\Enums\\Refund\\Status',
        ];

        foreach ( array_keys( $this->public_symbol_files() ) as $symbol ) {
            $declared = interface_exists( $symbol, false ) || class_exists( $symbol, false );

            if ( in_array( $symbol, $bootstrap_symbols, true ) ) {
                $this->assertTrue( $declared, $symbol . ' owns a bootstrap registration.' );
                continue;
            }

            $this->assertFalse( $declared, $symbol . ' should load only when its request uses it.' );
        }
    }

    public function test_public_domain_symbols_resolve_to_their_existing_files() {
        foreach ( $this->public_symbol_files() as $symbol => $relative_file ) {
            $available = interface_exists( $symbol ) || class_exists( $symbol );

            $this->assertTrue( $available, $symbol . ' must remain publicly resolvable.' );

            $reflection = new ReflectionClass( $symbol );
            $this->assertStringEndsWith( $relative_file, wp_normalize_path( $reflection->getFileName() ) );
        }
    }

    public function test_global_payment_checkout_and_deprecated_functions_remain_eager() {
        $this->assertTrue( function_exists( 'atbdp_get_payment_status' ) );
        $this->assertTrue( function_exists( 'atbdp_is_checkout' ) );
        $this->assertTrue( function_exists( 'atbdp_get_shortcode_template_paths' ) );
    }

    public function test_class_map_covers_every_deferred_declaration_file() {
        $class_map    = require DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/class-map.php';
        $mapped_files = array_values( $class_map );
        $source_files = [];

        foreach ( $this->deferred_directories() as $directory ) {
            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory ) );

            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) || 'functions.php' === $file->getFilename() ) {
                    continue;
                }

                $source_files[] = $file->getPathname();
            }
        }

        sort( $mapped_files );
        sort( $source_files );

        $this->assertSame( $source_files, $mapped_files );
        $this->assertSame( count( $mapped_files ), count( array_unique( $mapped_files ) ) );
    }

    private function public_symbol_files() {
        return [
            'Directorist\\Contracts\\PaymentInterface'             => '/includes/contracts/payment-interface.php',
            'Directorist\\DBModels\\Order'                         => '/includes/db-models/order.php',
            'Directorist\\DBModels\\Payment'                       => '/includes/db-models/payment.php',
            'Directorist\\DBModels\\Post'                          => '/includes/db-models/post.php',
            'Directorist\\DBModels\\Refund'                        => '/includes/db-models/refund.php',
            'Directorist\\DBModels\\Subscription'                  => '/includes/db-models/subscription.php',
            'Directorist\\DBModels\\User'                          => '/includes/db-models/user.php',
            'Directorist\\DTO\\Order\\DTO'                         => '/includes/dto/order/dto.php',
            'Directorist\\DTO\\Order\\Read'                        => '/includes/dto/order/read.php',
            'Directorist\\DTO\\Payment\\DTO'                       => '/includes/dto/payment/dto.php',
            'Directorist\\DTO\\Refund\\DTO'                        => '/includes/dto/refund/dto.php',
            'Directorist\\DTO\\Refund\\Read'                       => '/includes/dto/refund/read.php',
            'Directorist\\DTO\\Subscription\\DTO'                  => '/includes/dto/subscription/dto.php',
            'Directorist\\Enums\\Order\\DiscountType'              => '/includes/enums/order/discount-type.php',
            'Directorist\\Enums\\Order\\Status'                    => '/includes/enums/order/status.php',
            'Directorist\\Enums\\Order\\TaxType'                   => '/includes/enums/order/tax-type.php',
            'Directorist\\Enums\\Payment\\Status'                  => '/includes/enums/payment/status.php',
            'Directorist\\Enums\\Refund\\Status'                   => '/includes/enums/refund/status.php',
            'Directorist\\Repositories\\ListingRepository'         => '/includes/repositories/listing-repository.php',
            'Directorist\\Repositories\\OrderRepository'           => '/includes/repositories/order-repository.php',
            'Directorist\\Repositories\\PaymentRepository'         => '/includes/repositories/payment-repository.php',
            'Directorist\\Repositories\\RefundRepository'          => '/includes/repositories/refund-repository.php',
            'Directorist\\Repositories\\SubscriptionRepository'    => '/includes/repositories/subscription-repository.php',
            'Directorist\\database\\DB'                            => '/includes/database/db.php',
            'Directorist\\database\\Listing_Index'                 => '/includes/database/class-listing-index.php',
            'Directorist\\database\\Listing_Index_CLI'             => '/includes/database/class-listing-index-cli.php',
            'Directorist\\database\\Listing_Index_Directory_State' => '/includes/database/class-listing-index-directory-state.php',
            'Directorist\\database\\Listing_Index_Lifecycle'       => '/includes/database/class-listing-index-lifecycle.php',
            'Directorist\\database\\Listing_Index_Maintenance'     => '/includes/database/class-listing-index-maintenance.php',
            'Directorist\\database\\Listing_Index_Query'           => '/includes/database/class-listing-index-query.php',
            'Directorist\\database\\Listing_Index_Schema'          => '/includes/database/class-listing-index-schema.php',
            'Directorist\\database\\Listing_Index_Text'            => '/includes/database/class-listing-index-text.php',
            'ATBDP_Terms_Data_Store'                               => '/includes/data-store/class-atbdp-terms-store.php',
            'Directorist\\Directorist_Account'                     => '/includes/model/Account.php',
            'Directorist\\Directorist_All_Authors'                 => '/includes/model/All_Authors.php',
            'Directorist\\Directorist_Listing_Author'              => '/includes/model/ListingAuthor.php',
            'Directorist\\Directorist_Listing_Dashboard'           => '/includes/model/ListingDashboard.php',
            'Directorist\\Directorist_Listing_Form'                => '/includes/model/ListingForm.php',
            'Directorist\\Directorist_Listing_Search_Form'         => '/includes/model/SearchForm.php',
            'Directorist\\Directorist_Listing_Taxonomy'            => '/includes/model/ListingTaxonomy.php',
            'Directorist\\Directorist_Listings'                    => '/includes/model/Listings.php',
            'Directorist\\Directorist_Single_Listing'              => '/includes/model/SingleListing.php',
            'ATBDP_Gateway'                                        => '/includes/gateways/class-gateway.php',
            'ATBDP_Offline_Gateway'                                => '/includes/gateways/class-offline-gateway.php',
            'ATBDP_Order'                                          => '/includes/payments/class-order.php',
            'ATBDP_Listings_Data_Store'                            => '/includes/deprecated/class-atbdp-listing-store.php',
            'Directorist\\Script_Helper'                           => '/includes/deprecated/class-script-helper.php',
        ];
    }

    private function deferred_directories() {
        $includes = DIRECTORIST_TESTS_PLUGIN_DIR . '/includes/';

        return [
            $includes . 'contracts',
            $includes . 'db-models',
            $includes . 'dto',
            $includes . 'enums',
            $includes . 'repositories',
            $includes . 'database',
            $includes . 'data-store',
            $includes . 'model',
            $includes . 'gateways',
            $includes . 'payments',
            $includes . 'deprecated',
        ];
    }
}
