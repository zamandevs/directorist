<?php
/**
 * Listing-index deployment lifecycle tests.
 */

use Directorist\database\Listing_Index;
use Directorist\database\Listing_Index_Lifecycle;
use Directorist\database\Listing_Index_Maintenance;
use Directorist\database\Listing_Index_Schema;

class Directorist_Listing_Index_Lifecycle_Test extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();

        global $wpdb;

        delete_site_option( Listing_Index_Lifecycle::DEPLOYMENT_TOKEN_OPTION );
        delete_option( Listing_Index_Lifecycle::TRUSTED_TOKEN_OPTION );
        Listing_Index_Schema::create();
        Listing_Index::register_hooks();
        Listing_Index_Maintenance::register_hooks();

        foreach ( Listing_Index_Schema::field_tables() as $field_table ) {
            $wpdb->query( "DELETE FROM {$field_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        }
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::listing_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'DELETE FROM ' . Listing_Index_Schema::state_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        Listing_Index_Schema::mark_status( Listing_Index_Schema::STATUS_READY );
        update_option( Listing_Index_Schema::DATA_VERSION_OPTION, Listing_Index_Schema::DATA_VERSION, false );
        Listing_Index::set_enabled( true );
        delete_option( Listing_Index_Maintenance::LOCK_OPTION );
        delete_option( Listing_Index_Maintenance::RETRY_AFTER_OPTION );
        Listing_Index_Maintenance::background_process()->reset();
    }

    public function tear_down() {
        Listing_Index_Maintenance::background_process()->reset();
        delete_option( Listing_Index_Maintenance::LOCK_OPTION );
        delete_option( Listing_Index_Maintenance::RETRY_AFTER_OPTION );
        delete_site_option( Listing_Index_Lifecycle::DEPLOYMENT_TOKEN_OPTION );
        delete_option( Listing_Index_Lifecycle::TRUSTED_TOKEN_OPTION );

        parent::tear_down();
    }

    public function test_deactivation_falls_back_until_canonical_changes_are_rebuilt() {
        $listing_id = self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Lifecycle Listing',
            ]
        );
        $views_key  = directorist_get_listing_views_count_meta_key();

        update_post_meta( $listing_id, $views_key, 41 );
        Listing_Index::sync_listing( $listing_id );

        $this->assertSame( '41', Listing_Index::get_listing_row( $listing_id )['view_count'] );
        $this->assertTrue( Listing_Index::is_enabled() );

        do_action( 'deactivate_plugin', plugin_basename( ATBDP_DIR . 'directorist-base.php' ), false );

        remove_action( 'updated_post_meta', [ Listing_Index::class, 'sync_added_or_updated_meta' ], 100 );
        update_post_meta( $listing_id, $views_key, 87 );
        add_action( 'updated_post_meta', [ Listing_Index::class, 'sync_added_or_updated_meta' ], 100, 4 );

        $this->assertSame( '87', get_post_meta( $listing_id, $views_key, true ) );
        $this->assertSame( '41', Listing_Index::get_listing_row( $listing_id )['view_count'] );
        $this->assertFalse( Listing_Index::is_enabled() );
        $this->assertSame( Listing_Index_Schema::STATUS_NEEDS_REBUILD, Listing_Index_Schema::status() );

        for ( $attempt = 0; $attempt < 10 && Listing_Index_Maintenance::has_pending_work(); ++$attempt ) {
            Listing_Index_Maintenance::process_next_batch();
        }

        $this->assertTrue( Listing_Index_Schema::is_ready() );
        $this->assertTrue( Listing_Index::is_enabled() );
        $this->assertSame( '87', Listing_Index::get_listing_row( $listing_id )['view_count'] );
    }

    public function test_unrelated_plugin_lifecycle_does_not_invalidate_ready_data() {
        do_action( 'deactivate_plugin', 'hello-dolly/hello.php', false );
        apply_filters(
            'upgrader_pre_install',
            true,
            [
                'type'   => 'plugin',
                'plugin' => 'hello-dolly/hello.php',
            ]
        );

        $this->assertTrue( Listing_Index_Schema::is_ready() );
        $this->assertTrue( Listing_Index::is_enabled() );
    }

    public function test_directorist_upgrader_invalidates_data_without_iterating_sites() {
        Listing_Index_Lifecycle::trust_current_deployment();
        $trusted = Listing_Index_Lifecycle::deployment_token();

        $response = apply_filters(
            'upgrader_pre_install',
            true,
            [
                'type'   => 'plugin',
                'plugin' => plugin_basename( ATBDP_DIR . 'directorist-base.php' ),
            ]
        );

        $this->assertTrue( $response );
        $this->assertNotSame( $trusted, Listing_Index_Lifecycle::deployment_token() );
        $this->assertFalse( Listing_Index_Lifecycle::is_current_deployment_trusted() );
        $this->assertFalse( Listing_Index::is_enabled() );
        $this->assertSame( Listing_Index_Schema::STATUS_NEEDS_REBUILD, Listing_Index_Schema::status() );
    }

    public function test_deployment_change_during_rebuild_cannot_mark_data_ready() {
        self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => 'publish',
            ]
        );

        $this->assertTrue( Listing_Index::rebuild_start() );
        Listing_Index::rebuild_batch( 0, 10 );

        apply_filters(
            'upgrader_pre_install',
            true,
            [
                'type'   => 'plugin',
                'plugin' => plugin_basename( ATBDP_DIR . 'directorist-base.php' ),
            ]
        );

        $verification = Listing_Index::rebuild_finish();

        $this->assertSame( 1, $verification['deployment_changed'] );
        $this->assertSame( Listing_Index_Schema::STATUS_NEEDS_REBUILD, Listing_Index_Schema::status() );
        $this->assertFalse( Listing_Index::is_enabled() );
    }

    public function test_read_eligibility_does_not_restart_an_in_progress_rebuild() {
        self::factory()->post->create(
            [
                'post_type'   => ATBDP_POST_TYPE,
                'post_status' => 'publish',
            ]
        );

        $this->assertTrue( Listing_Index::rebuild_start() );
        update_site_option( Listing_Index_Lifecycle::DEPLOYMENT_TOKEN_OPTION, 'changed-during-rebuild' );

        $this->assertFalse( Listing_Index::is_enabled() );
        $this->assertSame( Listing_Index_Schema::STATUS_BUILDING, Listing_Index_Schema::status() );
        $this->assertSame( 'build', get_option( Listing_Index::REBUILD_PHASE_OPTION ) );
    }

    public function test_uninstall_contract_covers_all_deployment_lifecycle_options() {
        $uninstall = file_get_contents( ATBDP_DIR . 'uninstall.php' );

        $this->assertStringContainsString( Listing_Index::REBUILD_DEPLOYMENT_OPTION, $uninstall );
        $this->assertStringContainsString( Listing_Index_Lifecycle::TRUSTED_TOKEN_OPTION, $uninstall );
        $this->assertStringContainsString( Listing_Index_Lifecycle::DEPLOYMENT_TOKEN_OPTION, $uninstall );
    }
}
