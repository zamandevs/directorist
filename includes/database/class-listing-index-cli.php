<?php
/**
 * WP-CLI operations for rebuilding and verifying listing indexes.
 *
 * @since 8.9.0
 */

namespace Directorist\database;

defined( 'ABSPATH' ) || exit;

class Listing_Index_CLI {
    private static $registered = false;

    public static function register() {
        if ( self::$registered || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }

        self::$registered = true;
        \WP_CLI::add_command( 'directorist index', __CLASS__ );
    }

    /**
     * Rebuild all derived listing index rows.
     *
     * ## OPTIONS
     *
     * [--batch-size=<number>]
     * : Number of listings per batch. Default: 500.
     *
     * [--resume]
     * : Continue from the stored rebuild cursor.
     *
     * [--enable]
     * : Enable indexed reads after successful verification.
     */
    public function rebuild( $args, $assoc_args ) {
        unset( $args );

        $batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 500;
        $resume     = isset( $assoc_args['resume'] );
        $cursor     = $resume ? (int) get_option( 'directorist_listing_index_rebuild_cursor', 0 ) : 0;

        if ( $resume && Listing_Index_Schema::STATUS_BUILDING !== Listing_Index_Schema::status() ) {
            \WP_CLI::error( 'No interrupted listing index rebuild is available to resume.' );
        }

        if ( ! $resume && ! Listing_Index::rebuild_start() ) {
            \WP_CLI::error( 'The listing index schema could not be created.' );
        }

        do {
            $batch  = Listing_Index::rebuild_batch( $cursor, $batch_size );
            $cursor = $batch['next'];
            \WP_CLI::log( sprintf( 'Indexed %d listings; cursor %d.', $batch['processed'], $cursor ) );
        } while ( ! $batch['done'] );

        $verification = Listing_Index::rebuild_finish();

        if ( Listing_Index::has_verification_errors( $verification ) ) {
            \WP_CLI::error( 'Rebuild verification failed: ' . wp_json_encode( $verification ) );
        }

        if ( $verification['ambiguous_core_meta'] ) {
            \WP_CLI::warning( 'Conflicting core meta keys will remain on the canonical postmeta query path.' );
        }

        if ( Listing_Index::get_last_rebuild_mutations() ) {
            \WP_CLI::warning(
                sprintf(
                    '%d frontend mutations occurred during the rebuild and were included in verification.',
                    Listing_Index::get_last_rebuild_mutations()
                )
            );
        }

        if ( isset( $assoc_args['enable'] ) ) {
            Listing_Index::set_enabled( true );
        }

        \WP_CLI::success( 'Listing index rebuilt and verified.' );
    }

    /**
     * Verify derived rows against canonical WordPress listing data.
     */
    public function verify( $args = [], $assoc_args = [] ) {
        unset( $args, $assoc_args );
        $verification = Listing_Index::verify();

        \WP_CLI::log( 'Missing rows: ' . $verification['missing'] );
        \WP_CLI::log( 'Orphaned rows: ' . $verification['orphaned'] );
        \WP_CLI::log( 'Mismatched rows: ' . $verification['mismatched'] );
        \WP_CLI::log( 'Orphaned field rows: ' . $verification['orphaned_fields'] );
        \WP_CLI::log( 'Listings with mismatched field rows: ' . $verification['mismatched_fields'] );
        \WP_CLI::log( 'Conflicting core meta groups: ' . $verification['ambiguous_core_meta'] );

        if ( Listing_Index::has_verification_errors( $verification ) ) {
            \WP_CLI::error( 'Listing index verification failed.' );
        }

        if ( $verification['ambiguous_core_meta'] ) {
            \WP_CLI::warning( 'Conflicting core meta keys remain on the canonical postmeta query path.' );
        }

        \WP_CLI::success( 'Listing index matches canonical data.' );
    }

    /**
     * Rebuild derived rows to repair all reported mismatches.
     *
     * ## OPTIONS
     *
     * [--batch-size=<number>]
     * : Number of listings per batch. Default: 500.
     */
    public function repair( $args, $assoc_args ) {
        $this->rebuild( $args, $assoc_args );
    }

    /**
     * Enable lookup-backed reads after a successful rebuild.
     */
    public function enable( $args = [], $assoc_args = [] ) {
        unset( $args, $assoc_args );
        if ( ! Listing_Index_Schema::is_ready() ) {
            \WP_CLI::error( 'The listing index is not ready. Run `wp directorist index rebuild` first.' );
        }

        Listing_Index::set_enabled( true );
        \WP_CLI::success( 'Listing index reads enabled.' );
    }

    /**
     * Disable lookup-backed reads without deleting derived data.
     */
    public function disable( $args = [], $assoc_args = [] ) {
        unset( $args, $assoc_args );
        Listing_Index::set_enabled( false );
        \WP_CLI::success( 'Listing index reads disabled; canonical data and index rows were retained.' );
    }

    /**
     * Show schema, rebuild, and read-path status.
     */
    public function status( $args = [], $assoc_args = [] ) {
        unset( $args, $assoc_args );
        $background = Listing_Index_Maintenance::background_process();
        $data       = [
            'schema_version'     => get_option( Listing_Index_Schema::VERSION_OPTION, 'missing' ),
            'data_version'       => get_option( Listing_Index_Schema::DATA_VERSION_OPTION, 'missing' ),
            'schema_valid'       => Listing_Index_Schema::verify_schema() ? 'yes' : 'no',
            'repair_required'    => Listing_Index_Schema::repair_required() ? 'yes' : 'no',
            'status'             => Listing_Index_Schema::status(),
            'reads_enabled'      => Listing_Index::is_enabled() ? 'yes' : 'no',
            'phase'              => get_option( Listing_Index::REBUILD_PHASE_OPTION, '' ),
            'cursor'             => (int) get_option( 'directorist_listing_index_rebuild_cursor', 0 ),
            'rebuild_writes'     => Listing_Index::get_last_rebuild_mutations(),
            'background_queued'  => $background->has_queued_work() ? 'yes' : 'no',
            'background_running' => $background->is_running() ? 'yes' : 'no',
            'watchdog_scheduled' => wp_next_scheduled( $background->get_cron_hook_identifier() ) ? 'yes' : 'no',
            'retry_after'        => (int) get_option( Listing_Index_Maintenance::RETRY_AFTER_OPTION, 0 ),
        ];

        \WP_CLI\Utils\format_items( 'table', [ $data ], array_keys( $data ) );
    }
}
