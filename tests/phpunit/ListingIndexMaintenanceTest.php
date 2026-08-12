<?php
/**
 * Automatic per-directory listing-index maintenance tests.
 */

use Directorist\database\Listing_Index;
use Directorist\database\Listing_Index_Directory_State;
use Directorist\database\Listing_Index_Maintenance;
use Directorist\database\Listing_Index_Query;
use Directorist\database\Listing_Index_Schema;

class Directorist_Listing_Index_Maintenance_Test extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();

        global $wpdb;

        Listing_Index_Schema::create();
        Listing_Index::register_hooks();
        Listing_Index_Maintenance::register_hooks();

        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::field_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::listing_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::state_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_READY );
        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, Listing_Index_Schema::DATA_VERSION, false );
        Listing_Index::set_enabled( true );
        Listing_Index_Maintenance::background_process()->reset();
        delete_option( Listing_Index_Maintenance::LOCK_OPTION );
        delete_option( Listing_Index_Maintenance::RETRY_AFTER_OPTION );
        delete_option( Listing_Index::REBUILD_PHASE_OPTION );
        delete_option( Listing_Index::REBUILD_VERIFICATION_OPTION );
        wp_cache_flush();
    }

    public function test_empty_directory_configuration_becomes_ready_without_a_rebuild() {
        $directory_id = $this->create_directory( 'Empty Directory' );

        update_term_meta( $directory_id, 'search_form_fields', $this->search_fields( [ 'select_1' => 'select' ] ) );
        update_term_meta( $directory_id, 'submission_form_fields', $this->submission_fields( [ 'select_1' => 'select' ] ) );

        $state = Listing_Index_Directory_State::get( $directory_id, true );

        $this->assertSame( Listing_Index_Directory_State::STATUS_READY, $state['status'] );
        $this->assertGreaterThan( 0, (int) $state['active_generation'] );
        $this->assertSame( 0, (int) $state['pending_generation'] );
        $this->assertFalse( Listing_Index_Maintenance::background_process()->has_queued_work() );
    }

    public function test_populated_directory_change_queues_only_that_directory() {
        $directory_id = $this->create_directory( 'Changed Directory' );
        $other_id     = $this->create_directory( 'Stable Directory' );

        $this->configure_directory( $directory_id, [ 'select_1' => 'select' ] );
        $this->configure_directory( $other_id, [ 'select_1' => 'select' ] );

        $listing_id = $this->create_listing( $directory_id, [ '_custom-select-1' => 'gold' ] );
        $this->create_listing( $other_id, [ '_custom-select-1' => 'silver' ] );

        Listing_Index::sync_listing( $listing_id );
        $before = Listing_Index_Directory_State::get( $directory_id, true );

        update_term_meta(
            $directory_id,
            'search_form_fields',
            $this->search_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] )
        );
        update_term_meta(
            $directory_id,
            'submission_form_fields',
            $this->submission_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] )
        );

        $changed = Listing_Index_Directory_State::get( $directory_id, true );
        $stable  = Listing_Index_Directory_State::get( $other_id, true );

        $this->assertSame( Listing_Index_Directory_State::STATUS_PENDING, $changed['status'] );
        $this->assertGreaterThan( (int) $before['active_generation'], (int) $changed['pending_generation'] );
        $this->assertSame( Listing_Index_Directory_State::STATUS_READY, $stable['status'] );
        $this->assertTrue( Listing_Index::is_enabled() );
        $this->assertTrue( Listing_Index_Maintenance::background_process()->has_queued_work() );
    }

    public function test_queued_directory_rebuild_switches_generation_after_verification() {
        global $wpdb;

        $directory_id = $this->create_directory( 'Generation Directory' );
        $this->configure_directory( $directory_id, [ 'select_1' => 'select' ] );
        $listing_id = $this->create_listing( $directory_id, [ '_custom-select-1' => 'gold', '_custom-number-1' => 25 ] );

        Listing_Index::sync_listing( $listing_id );
        $before = Listing_Index_Directory_State::get( $directory_id, true );

        update_term_meta(
            $directory_id,
            'search_form_fields',
            $this->search_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] )
        );
        update_term_meta(
            $directory_id,
            'submission_form_fields',
            $this->submission_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] )
        );

        Listing_Index_Maintenance::process_next_batch();
        $after = Listing_Index_Directory_State::get( $directory_id, true );

        $this->assertSame( Listing_Index_Directory_State::STATUS_READY, $after['status'] );
        $this->assertGreaterThan( (int) $before['active_generation'], (int) $after['active_generation'] );
        $this->assertSame( 0, (int) $after['pending_generation'] );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT field_key, generation FROM ' . Listing_Index_Schema::field_table() . ' WHERE listing_id = %d ORDER BY field_key',
                $listing_id
            ),
            ARRAY_A
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        $this->assertSame( [ '_custom-number-1', '_custom-select-1' ], wp_list_pluck( $rows, 'field_key' ) );
        $this->assertSame( [ (string) $after['active_generation'] ], array_values( array_unique( wp_list_pluck( $rows, 'generation' ) ) ) );
    }

    public function test_query_generation_changes_only_after_atomic_activation() {
        $directory_id = $this->create_directory( 'Cache Generation Directory' );
        $this->configure_directory( $directory_id, [ 'select_1' => 'select' ] );
        $this->create_listing( $directory_id, [ '_custom-select-1' => 'gold', '_custom-number-1' => 25 ] );

        $before = Listing_Index_Directory_State::query_generation( $directory_id );

        update_term_meta(
            $directory_id,
            'search_form_fields',
            $this->search_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] )
        );
        update_term_meta(
            $directory_id,
            'submission_form_fields',
            $this->submission_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] )
        );

        $this->assertSame( $before, Listing_Index_Directory_State::query_generation( $directory_id ) );

        Listing_Index_Maintenance::process_next_batch();

        $this->assertNotSame( $before, Listing_Index_Directory_State::query_generation( $directory_id ) );
    }

    public function test_listing_mutations_update_active_and_pending_generations() {
        global $wpdb;

        $directory_id = $this->create_directory( 'Dual Write Directory' );
        $this->configure_directory( $directory_id, [ 'select_1' => 'select' ] );
        $listing_id = $this->create_listing( $directory_id, [ '_custom-select-1' => 'gold' ] );
        Listing_Index::sync_listing( $listing_id );

        update_term_meta(
            $directory_id,
            'search_form_fields',
            $this->search_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] )
        );
        update_term_meta(
            $directory_id,
            'submission_form_fields',
            $this->submission_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] )
        );

        update_post_meta( $listing_id, '_custom-select-1', 'platinum' );
        update_post_meta( $listing_id, '_custom-number-1', 37 );

        $state = Listing_Index_Directory_State::get( $directory_id, true );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT generation, field_key, value_string FROM ' . Listing_Index_Schema::field_table() . ' WHERE listing_id = %d ORDER BY generation, field_key',
                $listing_id
            ),
            ARRAY_A
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        $this->assertSame(
            [
                [ 'generation' => (string) $state['active_generation'], 'field_key' => '_custom-select-1', 'value_string' => 'platinum' ],
                [ 'generation' => (string) $state['pending_generation'], 'field_key' => '_custom-number-1', 'value_string' => '37' ],
                [ 'generation' => (string) $state['pending_generation'], 'field_key' => '_custom-select-1', 'value_string' => 'platinum' ],
            ],
            $rows
        );

        Listing_Index_Maintenance::process_next_batch();

        $this->assertTrue( Listing_Index::listing_generation_matches( $listing_id, $directory_id, Listing_Index_Directory_State::query_generation( $directory_id ) ) );
    }

    public function test_pending_configuration_uses_the_active_manifest_until_activation() {
        $directory_id = $this->create_directory( 'Planner Generation Directory' );
        $this->configure_directory( $directory_id, [ 'select_1' => 'select' ] );
        $this->create_listing( $directory_id, [ '_custom-select-1' => 'gold', '_custom-number-1' => 25 ] );

        $active_generation = Listing_Index_Directory_State::query_generation( $directory_id );

        update_term_meta(
            $directory_id,
            'search_form_fields',
            $this->search_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] )
        );
        update_term_meta(
            $directory_id,
            'submission_form_fields',
            $this->submission_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] )
        );

        $select_args = Listing_Index_Query::prepare_args( $this->query_args( $directory_id, '_custom-select-1', 'gold' ) );
        $number_args = Listing_Index_Query::prepare_args( $this->query_args( $directory_id, '_custom-number-1', 25, 'NUMERIC' ) );

        $this->assertSame( $active_generation, $select_args['directorist_listing_index_plan']['field_predicates'][0]['generation'] );
        $this->assertSame( 'partial', $number_args['directorist_listing_index_decision']['status'] );
        $this->assertSame( $directory_id . ':' . $active_generation, $number_args['directorist_listing_index_generation'] );

        Listing_Index_Maintenance::process_next_batch();
        $activated_generation = Listing_Index_Directory_State::query_generation( $directory_id );
        $number_args          = Listing_Index_Query::prepare_args( $this->query_args( $directory_id, '_custom-number-1', 25, 'NUMERIC' ) );

        $this->assertGreaterThan( $active_generation, $activated_generation );
        $this->assertSame( 'optimized', $number_args['directorist_listing_index_decision']['status'] );
        $this->assertSame( $activated_generation, $number_args['directorist_listing_index_plan']['field_predicates'][0]['generation'] );
        $this->assertSame( $directory_id . ':' . $activated_generation, $number_args['directorist_listing_index_generation'] );
    }

    public function test_directory_state_reads_are_object_cached() {
        global $wpdb;

        $directory_id = $this->create_directory( 'Cached State Directory' );
        $this->configure_directory( $directory_id, [ 'select_1' => 'select' ] );

        Listing_Index_Directory_State::get( $directory_id, true );
        $queries = $wpdb->num_queries;
        $state   = Listing_Index_Directory_State::get( $directory_id );

        $this->assertSame( $queries, $wpdb->num_queries );
        $this->assertSame( Listing_Index_Directory_State::STATUS_READY, $state['status'] );
    }

    public function test_large_directory_rebuild_resumes_until_verified() {
        $directory_id = $this->create_directory( 'Batched Directory' );
        $this->configure_directory( $directory_id, [ 'select_1' => 'select' ] );

        for ( $index = 0; $index < 25; ++$index ) {
            $this->create_listing( $directory_id, [ '_custom-select-1' => 'value-' . $index, '_custom-number-1' => $index ] );
        }

        update_term_meta(
            $directory_id,
            'search_form_fields',
            $this->search_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] )
        );
        update_term_meta(
            $directory_id,
            'submission_form_fields',
            $this->submission_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] )
        );

        add_filter( 'directorist_listing_index_maintenance_batch_size', [ $this, 'ten_item_batch' ] );

        for ( $pass = 0; $pass < 10; ++$pass ) {
            Listing_Index_Maintenance::process_next_batch();
            $state = Listing_Index_Directory_State::get( $directory_id, true );

            if ( Listing_Index_Directory_State::STATUS_READY === $state['status'] ) {
                break;
            }

            $this->assertTrue( Listing_Index_Maintenance::background_process()->has_queued_work() );
        }

        remove_filter( 'directorist_listing_index_maintenance_batch_size', [ $this, 'ten_item_batch' ] );

        $this->assertLessThan( 10, $pass );
        $this->assertSame( Listing_Index_Directory_State::STATUS_READY, $state['status'] );
        $this->assertSame( 0, (int) $state['pending_generation'] );
    }

    public function test_global_schema_rebuild_runs_automatically_without_cli() {
        global $wpdb;

        $directory_id = $this->create_directory( 'Automatic Global Directory' );
        $this->configure_directory( $directory_id, [ 'select_1' => 'select' ] );
        $listing_id = $this->create_listing( $directory_id, [ '_custom-select-1' => 'gold' ] );

        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::field_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::listing_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::state_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        wp_cache_flush();
        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, 'stale', false );
        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_NEEDS_REBUILD );

        Listing_Index_Maintenance::schedule_if_needed();

        $this->assertTrue( Listing_Index_Maintenance::background_process()->has_queued_work() );

        for ( $pass = 0; $pass < 5; ++$pass ) {
            Listing_Index_Maintenance::process_next_batch();

            if ( Listing_Index_Schema::is_ready() ) {
                break;
            }

            $this->assertTrue( Listing_Index_Maintenance::background_process()->has_queued_work() );
        }

        $this->assertGreaterThan( 0, $pass );
        $this->assertTrue( Listing_Index_Schema::is_ready() );
        $this->assertNotNull( Listing_Index::get_listing_row( $listing_id ) );
        $this->assertGreaterThan( 0, Listing_Index_Directory_State::query_generation( $directory_id ) );
        $this->assertTrue( Listing_Index::listing_generation_matches( $listing_id, $directory_id, Listing_Index_Directory_State::query_generation( $directory_id ) ) );
    }

    public function test_global_verification_is_bounded_and_resumable() {
        $directory_id = $this->create_directory( 'Bounded Global Verification' );
        $this->configure_directory( $directory_id, [ 'select_1' => 'select' ] );
        $listing_ids = [];

        for ( $index = 0; $index < 25; ++$index ) {
            $listing_ids[] = $this->create_listing( $directory_id, [ '_custom-select-1' => 'value-' . $index ] );
        }

        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, 'stale', false );
        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_NEEDS_REBUILD );
        add_filter( 'directorist_listing_index_maintenance_batch_size', [ $this, 'ten_item_batch' ] );

        Listing_Index_Maintenance::process_next_batch();
        Listing_Index_Maintenance::process_next_batch();
        Listing_Index_Maintenance::process_next_batch();

        $this->assertSame( Listing_Index_Schema::STATUS_BUILDING, Listing_Index_Schema::status() );
        $this->assertSame( 'verify', get_option( Listing_Index::REBUILD_PHASE_OPTION ) );
        $this->assertSame( 0, (int) get_option( 'directorist_listing_index_rebuild_cursor', 0 ) );
        $this->assertTrue( Listing_Index_Maintenance::has_pending_work() );

        Listing_Index_Maintenance::process_next_batch();

        $verification_cursor = (int) get_option( 'directorist_listing_index_rebuild_cursor', 0 );
        $this->assertGreaterThanOrEqual( (int) $listing_ids[9], $verification_cursor );
        $this->assertLessThan( (int) end( $listing_ids ), $verification_cursor );
        $this->assertSame( Listing_Index_Schema::STATUS_BUILDING, Listing_Index_Schema::status() );
        $this->assertTrue( Listing_Index_Maintenance::has_pending_work() );

        for ( $pass = 0; $pass < 5; ++$pass ) {
            Listing_Index_Maintenance::process_next_batch();

            if ( Listing_Index_Schema::is_ready() ) {
                break;
            }
        }

        remove_filter( 'directorist_listing_index_maintenance_batch_size', [ $this, 'ten_item_batch' ] );

        $this->assertTrue( Listing_Index_Schema::is_ready() );
        $this->assertFalse( get_option( Listing_Index::REBUILD_PHASE_OPTION, false ) );
        $this->assertFalse( get_option( Listing_Index::REBUILD_VERIFICATION_OPTION, false ) );
    }

    public function test_global_verification_never_activates_incomplete_derived_data() {
        global $wpdb;

        $directory_id = $this->create_directory( 'Incomplete Global Verification' );
        $this->configure_directory( $directory_id, [ 'select_1' => 'select' ] );
        $listing_id = $this->create_listing( $directory_id, [ '_custom-select-1' => 'gold' ] );

        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, 'stale', false );
        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_NEEDS_REBUILD );
        add_filter( 'directorist_listing_index_maintenance_batch_size', [ $this, 'ten_item_batch' ] );

        Listing_Index_Maintenance::process_next_batch();

        $this->assertSame( 'verify', get_option( Listing_Index::REBUILD_PHASE_OPTION ) );

        $wpdb->delete( Listing_Index_Schema::listing_table(), [ 'listing_id' => $listing_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        Listing_Index_Maintenance::process_next_batch();
        remove_filter( 'directorist_listing_index_maintenance_batch_size', [ $this, 'ten_item_batch' ] );

        $this->assertSame( Listing_Index_Schema::STATUS_FAILED, Listing_Index_Schema::status() );
        $this->assertFalse( Listing_Index::is_enabled() );
        $this->assertNotSame( Listing_Index_Schema::DATA_VERSION, get_option( Listing_Index_Schema::DATA_VERSION_OPTION ) );
    }

    public function test_new_configuration_supersedes_and_cleans_an_older_pending_generation() {
        global $wpdb;

        $directory_id = $this->create_directory( 'Superseded Directory' );
        $this->configure_directory( $directory_id, [ 'select_1' => 'select' ] );
        $listing_id = $this->create_listing(
            $directory_id,
            [
                '_custom-select-1' => 'gold',
                '_custom-number-1' => 10,
                '_custom-date-1'   => '2026-08-03',
            ]
        );
        Listing_Index::sync_listing( $listing_id );

        update_term_meta( $directory_id, 'search_form_fields', $this->search_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] ) );
        update_term_meta( $directory_id, 'submission_form_fields', $this->submission_fields( [ 'select_1' => 'select', 'number_1' => 'number' ] ) );
        Listing_Index::sync_listing( $listing_id );
        $first_pending = Listing_Index_Directory_State::get( $directory_id, true );

        update_term_meta( $directory_id, 'search_form_fields', $this->search_fields( [ 'select_1' => 'select', 'date_1' => 'date' ] ) );
        update_term_meta( $directory_id, 'submission_form_fields', $this->submission_fields( [ 'select_1' => 'select', 'date_1' => 'date' ] ) );
        $second_pending = Listing_Index_Directory_State::get( $directory_id, true );

        $old_pending_rows = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Listing_Index_Schema::field_table() . ' WHERE directory_id = %d AND generation = %d',
                $directory_id,
                $first_pending['pending_generation']
            )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        $this->assertGreaterThan( $first_pending['pending_generation'], $second_pending['pending_generation'] );
        $this->assertSame( 0, $old_pending_rows );
        $this->assertSame( [ '_custom-date-1', '_custom-select-1' ], array_keys( Listing_Index_Directory_State::pending_manifest( $directory_id ) ) );

        Listing_Index_Maintenance::process_next_batch();

        $this->assertSame( [ '_custom-date-1', '_custom-select-1' ], array_keys( Listing_Index_Directory_State::active_manifest( $directory_id ) ) );
    }

    public function test_deleting_a_directory_removes_only_its_derived_state_and_fields() {
        global $wpdb;

        $directory_id = $this->create_directory( 'Deleted Directory' );
        $other_id     = $this->create_directory( 'Retained Directory' );
        $this->configure_directory( $directory_id, [ 'select_1' => 'select' ] );
        $this->configure_directory( $other_id, [ 'select_1' => 'select' ] );
        $this->create_listing( $directory_id, [ '_custom-select-1' => 'gold' ] );
        $this->create_listing( $other_id, [ '_custom-select-1' => 'silver' ] );

        wp_delete_term( $directory_id, ATBDP_DIRECTORY_TYPE );

        $deleted_fields = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Listing_Index_Schema::field_table() . ' WHERE directory_id = %d',
                $directory_id
            )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        $this->assertSame( 'missing', Listing_Index_Directory_State::get( $directory_id, true )['status'] );
        $this->assertSame( 0, $deleted_fields );
        $this->assertSame( Listing_Index_Directory_State::STATUS_READY, Listing_Index_Directory_State::get( $other_id, true )['status'] );
    }

    public function ten_item_batch() {
        return 10;
    }

    private function create_directory( $name ) {
        return self::factory()->term->create(
            [
                'taxonomy' => ATBDP_DIRECTORY_TYPE,
                'name'     => $name,
            ]
        );
    }

    private function configure_directory( $directory_id, array $fields ) {
        update_term_meta( $directory_id, 'search_form_fields', $this->search_fields( $fields ) );
        update_term_meta( $directory_id, 'submission_form_fields', $this->submission_fields( $fields ) );
        Listing_Index_Directory_State::activate_current_configuration( $directory_id );
    }

    private function search_fields( array $fields ) {
        $config = [ 'fields' => [] ];

        foreach ( $fields as $key => $type ) {
            $config['fields'][ $key ] = [
                'widget_name'         => $type,
                'widget_key'          => $key,
                'original_widget_key' => $key,
            ];
        }

        return $config;
    }

    private function submission_fields( array $fields ) {
        $config = [ 'fields' => [] ];

        foreach ( $fields as $key => $type ) {
            $config['fields'][ $key ] = [
                'widget_name' => $type,
                'widget_key'  => $key,
                'field_key'   => 'custom-' . str_replace( '_', '-', $key ),
            ];
        }

        return $config;
    }

    private function create_listing( $directory_id, array $meta = [] ) {
        $listing_id = self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => 'publish',
            ]
        );

        update_post_meta( $listing_id, '_directory_type', $directory_id );
        wp_set_object_terms( $listing_id, $directory_id, ATBDP_DIRECTORY_TYPE );

        foreach ( $meta as $key => $value ) {
            update_post_meta( $listing_id, $key, $value );
        }

        return $listing_id;
    }

    private function query_args( $directory_id, $field_key, $value, $type = '' ) {
        $field_clause = [
            'key'   => $field_key,
            'value' => $value,
        ];

        if ( $type ) {
            $field_clause['type'] = $type;
        }

        return [
            'post_type'   => ATBDP_POST_TYPE,
            'post_status' => 'publish',
            'meta_query'  => [
                'relation'       => 'AND',
                'directory_type' => [
                    'key'   => '_directory_type',
                    'value' => $directory_id,
                ],
                'field'          => $field_clause,
            ],
        ];
    }
}
