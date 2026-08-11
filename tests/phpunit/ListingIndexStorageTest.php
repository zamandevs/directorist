<?php
/**
 * Derived listing-index schema and synchronization tests.
 */

use Directorist\database\Listing_Index;
use Directorist\database\Listing_Index_Directory_State;
use Directorist\database\Listing_Index_Maintenance;
use Directorist\database\Listing_Index_Schema;

class Directorist_Listing_Index_Storage_Test extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();

        global $wpdb;

        delete_option( Listing_Index_Schema::VERSION_OPTION );
        delete_option( Listing_Index_Schema::STATUS_OPTION );
        delete_option( Listing_Index::ENABLED_OPTION );
        delete_option( Listing_Index::AMBIGUOUS_META_OPTION );

        Listing_Index_Schema::create();
        Listing_Index::register_hooks();
        Listing_Index_Maintenance::register_hooks();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::field_table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::listing_table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::state_table() );
        delete_option( Listing_Index_Maintenance::LOCK_OPTION );
        delete_option( Listing_Index_Maintenance::RETRY_AFTER_OPTION );
        Listing_Index_Maintenance::background_process()->reset();
        wp_cache_flush();

        Listing_Index::verify();
        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_READY );
        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, Listing_Index_Schema::DATA_VERSION );
    }

    public function test_schema_is_idempotent_and_contains_query_indexes() {
        global $wpdb;

        $this->assertTrue( Listing_Index_Schema::create() );
        $this->assertTrue( Listing_Index_Schema::create() );
        $this->assertTrue( Listing_Index_Schema::tables_exist() );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $indexes = $wpdb->get_col( 'SHOW INDEX FROM ' . Listing_Index_Schema::listing_table(), 2 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $field_indexes = $wpdb->get_col( 'SHOW INDEX FROM ' . Listing_Index_Schema::field_table(), 2 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $field_index_rows = $wpdb->get_results( 'SHOW INDEX FROM ' . Listing_Index_Schema::field_table(), ARRAY_A );

        $this->assertContains( 'PRIMARY', $indexes );
        $this->assertContains( 'directory_listing', $indexes );
        $this->assertContains( 'status_price', $indexes );
        $this->assertContains( 'directory_price', $indexes );
        $this->assertContains( 'directory_price_signed', $indexes );
        $this->assertContains( 'directory_rating', $indexes );
        $this->assertContains( 'directory_generation_field_exact', $field_indexes );
        $this->assertContains( 'directory_generation_field_signed', $field_indexes );
        $this->assertContains( 'directory_generation_field_search', $field_indexes );
        $this->assertNotContains( 'directory_field_string', $field_indexes );
        $this->assertNotContains( 'directory_field_hash', $field_indexes );

        $search_index = array_values(
            array_filter(
                $field_index_rows,
                static function( $index ) {
                    return 'directory_generation_field_search' === $index['Key_name'];
                }
            )
        );

        $this->assertNotEmpty( $search_index );
        $this->assertSame( 'FULLTEXT', $search_index[0]['Index_type'] );
        $this->assertSame( [ 'search_scope', 'search_document' ], wp_list_pluck( $search_index, 'Column_name' ) );

        $exact_parts = [];
        foreach ( $field_index_rows as $index ) {
            if ( 'directory_generation_field_exact' === $index['Key_name'] ) {
                $exact_parts[ (int) $index['Seq_in_index'] ] = [ $index['Column_name'], $index['Sub_part'] ];
            }
        }

        $this->assertSame(
            [
                1 => [ 'directory_id', null ],
                2 => [ 'generation', null ],
                3 => [ 'field_key', '64' ],
                4 => [ 'value_string', '64' ],
                5 => [ 'listing_id', null ],
            ],
            $exact_parts
        );
    }

    public function test_fresh_schema_health_does_not_write_on_read_requests() {
        global $wpdb;

        update_option(
            Listing_Index_Schema::HEALTH_OPTION,
            [
                'version' => Listing_Index_Schema::VERSION,
                'prefix'  => $wpdb->prefix,
                'checked' => time() - 30,
            ],
            false
        );

        Listing_Index_Schema::reset_request_cache();

        $writes            = 0;
        $table_checks      = 0;
        $count_write       = static function( $value ) use ( &$writes ) {
            ++$writes;
            return $value;
        };
        $count_table_check = static function( $sql ) use ( &$table_checks ) {
            if ( false !== strpos( $sql, 'information_schema.tables' ) ) {
                ++$table_checks;
            }

            return $sql;
        };

        add_filter( 'pre_update_option_' . Listing_Index_Schema::HEALTH_OPTION, $count_write );
        add_filter( 'query', $count_table_check );
        $this->assertTrue( Listing_Index_Schema::is_compatible() );
        $this->assertTrue( Listing_Index_Schema::is_compatible() );
        remove_filter( 'query', $count_table_check );
        remove_filter( 'pre_update_option_' . Listing_Index_Schema::HEALTH_OPTION, $count_write );

        $this->assertSame( 0, $writes );
        $this->assertSame( 1, $table_checks );
    }

    public function test_schema_verification_rejects_and_repairs_a_missing_required_index() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'ALTER TABLE ' . Listing_Index_Schema::listing_table() . ' DROP INDEX directory_price_signed' );

        $this->assertFalse( Listing_Index_Schema::verify_schema() );
        $this->assertTrue( Listing_Index_Schema::create() );
        $this->assertTrue( Listing_Index_Schema::verify_schema() );
    }

    public function test_fulltext_schema_upgrade_requires_derived_data_rebuild() {
        global $wpdb;

        $listing_id = $this->create_listing( 'Schema Upgrade Listing' );
        Listing_Index::sync_listing( $listing_id );

        // Simulate the immediately preceding field-index schema.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query( 'ALTER TABLE ' . Listing_Index_Schema::field_table() . ' DROP INDEX directory_generation_field_search, DROP COLUMN search_scope, DROP COLUMN search_document' );
        update_option( Listing_Index_Schema::VERSION_OPTION, '2.0.0', false );
        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, '2.0.0', false );
        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_READY );
        Listing_Index_Schema::reset_request_cache();

        $this->assertTrue( Listing_Index_Schema::create(), $wpdb->last_error );
        $this->assertTrue( Listing_Index_Schema::verify_schema() );
        $this->assertSame( Listing_Index_Schema::VERSION, get_option( Listing_Index_Schema::VERSION_OPTION ) );
        $this->assertSame( '2.0.0', get_option( Listing_Index_Schema::DATA_VERSION_OPTION ) );
        $this->assertSame( Listing_Index_Schema::STATUS_NEEDS_REBUILD, Listing_Index_Schema::status() );
        $this->assertFalse( Listing_Index_Schema::is_ready() );
    }

    public function test_listing_sync_stores_typed_values_and_presence_flags() {
        $listing_id = $this->create_listing();

        update_post_meta( $listing_id, '_directory_type', 77 );
        update_post_meta( $listing_id, '_featured', 1 );
        update_post_meta( $listing_id, '_price', '199.95' );
        update_post_meta( $listing_id, '_manual_lat', '23.8103000' );
        update_post_meta( $listing_id, '_manual_lng', '90.4125000' );
        update_post_meta( $listing_id, directorist_get_rating_field_meta_key(), '4.75' );
        update_post_meta( $listing_id, directorist_get_listing_views_count_meta_key(), 91 );

        Listing_Index::sync_listing( $listing_id );
        $row = Listing_Index::get_listing_row( $listing_id );

        $this->assertSame( '77', $row['directory_id'] );
        $this->assertSame( '1', $row['featured'] );
        $this->assertSame( '1', $row['featured_set'] );
        $this->assertSame( '199.950000', $row['price'] );
        $this->assertSame( '199', $row['price_signed'] );
        $this->assertSame( '1', $row['price_set'] );
        $this->assertSame( '23.8103000', $row['latitude'] );
        $this->assertSame( '90.4125000', $row['longitude'] );
        $this->assertSame( '4.7500', $row['rating'] );
        $this->assertSame( '4', $row['rating_signed'] );
        $this->assertSame( '91', $row['view_count'] );
        $this->assertSame( '91', $row['view_count_signed'] );
        $this->assertSame( 'publish', $row['post_status'] );
    }

    public function test_decimal_storage_uses_the_declared_column_precision_during_sync_and_verification() {
        $listing_id = $this->create_listing();

        update_post_meta( $listing_id, '_price', '199.1234567' );
        update_post_meta( $listing_id, '_manual_lat', '36.65061088117347' );
        update_post_meta( $listing_id, '_manual_lng', '25.12345678901234' );
        update_post_meta( $listing_id, directorist_get_rating_field_meta_key(), '4.75555' );

        Listing_Index::sync_listing( $listing_id );
        $row          = Listing_Index::get_listing_row( $listing_id );
        $verification = Listing_Index::verify();

        $this->assertSame( '199.123457', $row['price'] );
        $this->assertSame( '36.6506109', $row['latitude'] );
        $this->assertSame( '25.1234568', $row['longitude'] );
        $this->assertSame( '4.7556', $row['rating'] );
        $this->assertSame( 0, $verification['mismatched'] );

        update_post_meta( $listing_id, '_manual_lat', '37.12345678901234' );
        update_post_meta( $listing_id, '_manual_lng', '26.98765432109876' );
        update_post_meta( $listing_id, directorist_get_rating_field_meta_key(), '4.12345' );

        $row          = Listing_Index::get_listing_row( $listing_id );
        $verification = Listing_Index::verify();

        $this->assertSame( '37.1234568', $row['latitude'] );
        $this->assertSame( '26.9876543', $row['longitude'] );
        $this->assertSame( '4.1235', $row['rating'] );
        $this->assertSame( 0, $verification['mismatched'] );
    }

    public function test_direct_meta_updates_and_deletes_keep_core_row_current() {
        $listing_id = $this->create_listing();
        Listing_Index::sync_listing( $listing_id );

        update_post_meta( $listing_id, '_price', 42 );
        update_post_meta( $listing_id, '_featured', 1 );

        $row = Listing_Index::get_listing_row( $listing_id );
        $this->assertSame( '42.000000', $row['price'] );
        $this->assertSame( '1', $row['price_set'] );
        $this->assertSame( '1', $row['featured'] );

        delete_post_meta( $listing_id, '_price' );
        delete_post_meta( $listing_id, '_featured' );

        $row = Listing_Index::get_listing_row( $listing_id );
        $this->assertNull( $row['price'] );
        $this->assertSame( '0', $row['price_set'] );
        $this->assertSame( '0', $row['featured'] );
        $this->assertSame( '0', $row['featured_set'] );
    }

    public function test_conflicting_core_meta_is_reported_without_disabling_safe_keys() {
        $listing_id = $this->create_listing();

        add_post_meta( $listing_id, '_price', 10 );
        add_post_meta( $listing_id, '_price', 20 );
        Listing_Index::set_enabled( true );

        $this->assertSame( Listing_Index_Schema::STATUS_READY, Listing_Index_Schema::status() );
        $this->assertTrue( Listing_Index::is_enabled() );
        $this->assertTrue( Listing_Index::is_core_meta_ambiguous( '_price' ) );
        $verification = Listing_Index::verify();
        $row          = Listing_Index::get_listing_row( $listing_id );

        $this->assertSame( '10.000000', $row['price'] );
        $this->assertSame( 0, $verification['mismatched'] );
        $this->assertSame( 1, $verification['ambiguous_core_meta'] );
    }

    public function test_custom_field_sync_uses_search_builder_definitions() {
        global $wpdb;

        $directory_id = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Indexed Directory',
            ]
        );

        update_term_meta(
            $directory_id,
            'search_form_fields',
            [
                'fields' => [
                    'select_1'   => [
                        'widget_name' => 'select',
                        'widget_key'  => 'select_1',
                    ],
                    'checkbox_1' => [
                        'widget_name' => 'checkbox',
                        'widget_key'  => 'checkbox_1',
                    ],
                    'number_1'   => [
                        'widget_name' => 'number',
                        'widget_key'  => 'number_1',
                    ],
                    'text_1'     => [
                        'widget_name' => 'text',
                        'widget_key'  => 'text_1',
                    ],
                    'textarea_1' => [
                        'widget_name' => 'textarea',
                        'widget_key'  => 'textarea_1',
                    ],
                    'url_1'      => [
                        'widget_name' => 'url',
                        'widget_key'  => 'url_1',
                    ],
                ],
            ]
        );
        update_term_meta(
            $directory_id,
            'submission_form_fields',
            [
                'fields' => [
                    'select_1'   => [ 'widget_name' => 'select', 'widget_key' => 'select_1', 'field_key' => 'custom-select-1' ],
                    'checkbox_1' => [ 'widget_name' => 'checkbox', 'widget_key' => 'checkbox_1', 'field_key' => 'custom-checkbox-1' ],
                    'number_1'   => [ 'widget_name' => 'number', 'widget_key' => 'number_1', 'field_key' => 'custom-number-1' ],
                    'text_1'     => [ 'widget_name' => 'text', 'widget_key' => 'text_1', 'field_key' => 'custom-text-1' ],
                    'textarea_1' => [ 'widget_name' => 'textarea', 'widget_key' => 'textarea_1', 'field_key' => 'custom-textarea-1' ],
                    'url_1'      => [ 'widget_name' => 'url', 'widget_key' => 'url_1', 'field_key' => 'custom-url-1' ],
                    'display_1'  => [ 'widget_name' => 'text', 'widget_key' => 'display_1', 'field_key' => 'display-only' ],
                ],
            ]
        );

        $listing_id = $this->create_listing();
        update_post_meta( $listing_id, '_directory_type', $directory_id );
        update_post_meta( $listing_id, '_custom-select-1', 'gold' );
        update_post_meta( $listing_id, '_custom-checkbox-1', [ 'wifi', 'parking', 'wifi' ] );
        update_post_meta( $listing_id, '_custom-number-1', '25.12345678904' );
        update_post_meta( $listing_id, '_custom-text-1', str_repeat( 'Long searchable description ', 20 ) );
        update_post_meta( $listing_id, '_custom-textarea-1', 'Quiet riverside workspace' );
        update_post_meta( $listing_id, '_custom-url-1', 'https://example.test/quiet-workspace' );
        update_post_meta( $listing_id, '_display-only', 'must not be indexed' );

        Listing_Index::sync_listing( $listing_id );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT field_key, field_type, value_string, value_num, search_scope, search_document FROM ' . Listing_Index_Schema::field_table() . ' WHERE listing_id = %d ORDER BY field_key, value_string',
                $listing_id
            ),
            ARRAY_A
        );

        $checkbox_rows = array_values(
            array_filter(
                $rows,
                static function( $row ) {
                    return '_custom-checkbox-1' === $row['field_key'];
                }
            )
        );
        $number_rows   = array_values(
            array_filter(
                $rows,
                static function( $row ) {
                    return '_custom-number-1' === $row['field_key'];
                }
            )
        );

        $this->assertCount( 7, $rows );
        $this->assertSame( [ 'parking', 'wifi' ], wp_list_pluck( $checkbox_rows, 'value_string' ) );
        $this->assertSame( '25.1234567890', $number_rows[0]['value_num'] );
        $this->assertNotContains( '_display-only', wp_list_pluck( $rows, 'field_key' ) );

        foreach ( [ '_custom-text-1', '_custom-textarea-1', '_custom-url-1' ] as $field_key ) {
            $text_rows = array_values(
                array_filter(
                    $rows,
                    static function( $row ) use ( $field_key ) {
                        return $field_key === $row['field_key'];
                    }
                )
            );

            $this->assertCount( 1, $text_rows );
            $this->assertNotEmpty( $text_rows[0]['search_scope'] );
            $this->assertNotEmpty( $text_rows[0]['search_document'] );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            Listing_Index_Schema::field_table(),
            [ 'value_hash' => md5( 'tampered' ) ],
            [
                'listing_id' => $listing_id,
                'field_key'  => '_custom-select-1',
            ],
            [ '%s' ],
            [ '%d', '%s' ]
        );

        $this->assertSame( 1, Listing_Index::verify()['mismatched_fields'] );
        Listing_Index::sync_listing( $listing_id );
        $this->assertSame( 0, Listing_Index::verify()['mismatched_fields'] );

        $original_text_document = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT search_document FROM ' . Listing_Index_Schema::field_table() . ' WHERE listing_id = %d AND field_key = %s',
                $listing_id,
                '_custom-text-1'
            )
        );

        update_post_meta( $listing_id, '_custom-checkbox-1', [ 'wifi', 'pool' ] );
        update_post_meta( $listing_id, '_custom-text-1', 'Updated searchable content' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated_checkbox_values = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT value_string FROM ' . Listing_Index_Schema::field_table() . ' WHERE listing_id = %d AND field_key = %s ORDER BY value_string',
                $listing_id,
                '_custom-checkbox-1'
            )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated_text_document = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT search_document FROM ' . Listing_Index_Schema::field_table() . ' WHERE listing_id = %d AND field_key = %s',
                $listing_id,
                '_custom-text-1'
            )
        );

        $this->assertSame( [ 'pool', 'wifi' ], $updated_checkbox_values );
        $this->assertNotSame( $original_text_document, $updated_text_document );
        $this->assertSame( 0, Listing_Index::verify()['mismatched_fields'] );

        delete_post_meta( $listing_id, '_custom-text-1' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->assertSame(
            '0',
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . Listing_Index_Schema::field_table() . ' WHERE listing_id = %d AND field_key = %s',
                    $listing_id,
                    '_custom-text-1'
                )
            )
        );
    }

    public function test_directory_taxonomy_mismatch_keeps_meta_as_index_source() {
        $directory_meta = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Meta Directory',
            ]
        );
        $directory_tax  = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Taxonomy Directory',
            ]
        );
        $listing_id     = $this->create_listing();

        update_post_meta( $listing_id, '_directory_type', $directory_meta );
        wp_set_object_terms( $listing_id, $directory_tax, ATBDP_DIRECTORY_TYPE );
        Listing_Index::sync_listing( $listing_id );

        $row = Listing_Index::get_listing_row( $listing_id );
        $this->assertSame( (string) $directory_meta, $row['directory_id'] );
    }

    public function test_post_status_author_and_date_changes_sync_through_save_post() {
        $listing_id = $this->create_listing();
        $author_id  = self::factory()->user->create();

        Listing_Index::sync_listing( $listing_id );
        $full_syncs = 0;
        $count_sync = static function() use ( &$full_syncs ) {
            ++$full_syncs;
        };
        add_action( 'directorist_listing_index_synced', $count_sync );
        wp_update_post(
            [
                'ID'            => $listing_id,
                'post_status'   => 'pending',
                'post_author'   => $author_id,
                'post_modified' => '2026-08-02 12:30:00',
            ]
        );
        remove_action( 'directorist_listing_index_synced', $count_sync );

        $row = Listing_Index::get_listing_row( $listing_id );
        $this->assertSame( 'pending', $row['post_status'] );
        $this->assertSame( (string) $author_id, $row['author_id'] );
        $this->assertSame( get_post( $listing_id )->post_modified, $row['post_modified'] );
        $this->assertSame( 0, $full_syncs );
    }

    public function test_search_builder_change_queues_only_the_affected_directory() {
        $directory_id = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Changed Builder Directory',
            ]
        );
        $listing_id   = $this->create_listing();
        update_post_meta( $listing_id, '_directory_type', $directory_id );

        Listing_Index::set_enabled( true );
        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_READY );
        update_term_meta( $directory_id, 'search_form_fields', [ 'fields' => [] ] );

        $state = Listing_Index_Directory_State::get( $directory_id, true );

        $this->assertSame( Listing_Index_Schema::STATUS_READY, Listing_Index_Schema::status() );
        $this->assertSame( Listing_Index_Directory_State::STATUS_PENDING, $state['status'] );
        $this->assertTrue( Listing_Index::is_enabled() );
        $this->assertTrue( Listing_Index_Maintenance::background_process()->has_queued_work() );
    }

    public function test_builder_change_for_an_empty_directory_does_not_disable_ready_reads() {
        $directory_id = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Empty Changed Directory',
            ]
        );

        Listing_Index::set_enabled( true );
        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_READY );
        update_term_meta( $directory_id, 'search_form_fields', [ 'fields' => [] ] );

        $this->assertSame( Listing_Index_Schema::STATUS_READY, Listing_Index_Schema::status() );
        $this->assertTrue( Listing_Index::is_enabled() );
    }

    public function test_multisite_tables_and_schema_health_use_current_blog_prefix() {
        if ( ! is_multisite() ) {
            $this->markTestSkipped( 'Multisite-only prefix isolation test.' );
        }

        global $wpdb;

        $primary_table = Listing_Index_Schema::listing_table();
        $blog_id       = self::factory()->blog->create();

        // InnoDB does not support FULLTEXT indexes on the temporary tables that
        // wp-phpunit normally substitutes for test-created tables.
        remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
        remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
        switch_to_blog( $blog_id );
        $secondary_tables = [
            Listing_Index_Schema::listing_table(),
            Listing_Index_Schema::field_table(),
            Listing_Index_Schema::state_table(),
        ];

        try {
            $created         = Listing_Index_Schema::create();
            $secondary_table = Listing_Index_Schema::listing_table();

            $this->assertTrue( $created, $wpdb->last_error );
            $this->assertNotSame( $primary_table, $secondary_table );
            $this->assertTrue( Listing_Index_Schema::is_compatible() );
            $this->assertStringStartsWith( $wpdb->prefix, $secondary_table );
        } finally {
            foreach ( $secondary_tables as $table ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
            }

            restore_current_blog();
            add_filter( 'query', [ $this, '_create_temporary_tables' ] );
            add_filter( 'query', [ $this, '_drop_temporary_tables' ] );
        }

        $this->assertSame( $primary_table, Listing_Index_Schema::listing_table() );
        $this->assertTrue( Listing_Index_Schema::is_compatible() );
    }

    public function test_delete_and_rebuild_are_idempotent_and_verifiable() {
        $listing_one = $this->create_listing( 'First' );
        $listing_two = $this->create_listing( 'Second' );

        update_post_meta( $listing_one, '_price', 25 );

        $this->assertTrue( Listing_Index::rebuild_start() );
        $batch = Listing_Index::rebuild_batch( 0, 1 );
        $this->assertSame( 1, $batch['processed'] );
        $this->assertFalse( $batch['done'] );

        $batch = Listing_Index::rebuild_batch( $batch['next'], 10 );
        $this->assertTrue( $batch['done'] );

        $verification = Listing_Index::rebuild_finish();
        $this->assertSame(
            [
                'missing'               => 0,
                'orphaned'              => 0,
                'mismatched'            => 0,
                'orphaned_fields'       => 0,
                'mismatched_fields'     => 0,
                'ambiguous_core_meta'   => 0,
                'configuration_changed' => 0,
                'deployment_changed'    => 0,
            ],
            $verification
        );
        $this->assertTrue( Listing_Index_Schema::is_ready() );

        wp_delete_post( $listing_one, true );
        $this->assertNull( Listing_Index::get_listing_row( $listing_one ) );
        $this->assertNotNull( Listing_Index::get_listing_row( $listing_two ) );
    }

    public function test_global_rebuild_stays_usable_while_a_changed_directory_is_queued() {
        $directory_id = self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => 'Concurrent Builder Change',
            ]
        );

        $listing_id = $this->create_listing();
        update_post_meta( $listing_id, '_directory_type', $directory_id );
        $this->assertTrue( Listing_Index::rebuild_start() );
        Listing_Index::rebuild_batch( 0, 10 );
        update_term_meta(
            $directory_id,
            'search_form_fields',
            [
                'fields' => [
                    'select_1' => [ 'widget_name' => 'select', 'widget_key' => 'select_1' ],
                ],
            ]
        );

        $verification = Listing_Index::rebuild_finish();

        $state = Listing_Index_Directory_State::get( $directory_id, true );

        $this->assertSame( 0, $verification['configuration_changed'] );
        $this->assertSame( Listing_Index_Schema::STATUS_READY, Listing_Index_Schema::status() );
        $this->assertSame( Listing_Index_Directory_State::STATUS_PENDING, $state['status'] );
        $this->assertTrue( Listing_Index::is_enabled() );
    }

    public function test_rebuild_and_verify_release_per_listing_runtime_caches() {
        $listing_id = $this->create_listing( 'Cache Bound Listing' );

        $this->assertTrue( Listing_Index::rebuild_start() );
        Listing_Index::rebuild_batch( 0, 10 );
        $this->assertFalse( wp_cache_get( $listing_id, 'posts' ) );
        $this->assertFalse( wp_cache_get( $listing_id, 'post_meta' ) );

        get_post( $listing_id );
        get_post_meta( $listing_id );
        Listing_Index::verify();
        $this->assertFalse( wp_cache_get( $listing_id, 'posts' ) );
        $this->assertFalse( wp_cache_get( $listing_id, 'post_meta' ) );
    }

    private function create_listing( $title = 'Indexed Listing' ) {
        return self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => $title,
            ]
        );
    }
}
