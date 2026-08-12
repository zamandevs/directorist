<?php
/**
 * Asynchronous listing-index maintenance worker.
 *
 * @since 8.9.0
 * @package Directorist
 */

namespace Directorist;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\Background_Process', false ) ) {
    include_once ATBDP_INC_DIR . 'classes/class-abstract-background-process.php';
}

use Directorist\database\Listing_Index_Maintenance;

class Listing_Index_Background_Process extends Background_Process {
    const ACTION = 'directorist_listing_index';

    protected $cron_interval = 1;

    private $stop_current_request = false;

    public function __construct() {
        $this->prefix = 'wp_' . get_current_blog_id();
        $this->action = self::ACTION;

        parent::__construct();
    }

    /**
     * Queue one durable cursor job and start it asynchronously.
     *
     * @return bool Whether work is queued or running.
     */
    public function start() {
        if ( ! Listing_Index_Maintenance::has_pending_work() ) {
            return false;
        }

        if ( ! $this->is_queue_empty() ) {
            $this->schedule_event();
            return true;
        }

        $this->push_to_queue(
            [
                'job'     => 'listing-index',
                'blog_id' => get_current_blog_id(),
            ]
        )->save();

        $dispatched = $this->dispatch();

        return ! is_wp_error( $dispatched );
    }

    /**
     * Dispatch now unless maintenance requested a retry delay.
     *
     * @return array|\WP_Error|bool
     */
    public function dispatch() {
        if ( Listing_Index_Maintenance::is_deferred() ) {
            $this->schedule_event();
            return true;
        }

        return parent::dispatch();
    }

    public function has_queued_work() {
        return ! $this->is_queue_empty();
    }

    public function is_running() {
        return $this->is_process_running();
    }

    public function get_identifier() {
        return $this->identifier;
    }

    public function get_cron_hook_identifier() {
        return $this->cron_hook_identifier;
    }

    /**
     * Remove worker transport state without touching canonical or derived data.
     */
    public function reset() {
        $this->delete_all_batches();
        delete_site_transient( $this->identifier . '_process_lock' );
        wp_clear_scheduled_hook( $this->cron_hook_identifier );
        $this->stop_current_request = false;
    }

    /**
     * Recover a queue whose asynchronous continuation was interrupted.
     */
    public function handle_cron_healthcheck() {
        if ( $this->is_process_running() || Listing_Index_Maintenance::is_deferred() ) {
            return;
        }

        if ( $this->is_queue_empty() ) {
            $this->clear_scheduled_event();
            return;
        }

        $this->handle();
    }

    protected function task( $item ) {
        $has_more_work = Listing_Index_Maintenance::process_next_batch();

        if ( ! $has_more_work ) {
            return false;
        }

        if ( Listing_Index_Maintenance::is_deferred() ) {
            $this->stop_current_request = true;
        }

        return $item;
    }

    protected function time_exceeded() {
        return $this->stop_current_request || parent::time_exceeded();
    }

    protected function schedule_event() {
        if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, $this->cron_interval_identifier, $this->cron_hook_identifier );
        }
    }

    protected function complete() {
        if ( Listing_Index_Maintenance::has_pending_work() ) {
            if ( $this->is_queue_empty() ) {
                $this->push_to_queue(
                    [
                        'job'     => 'listing-index',
                        'blog_id' => get_current_blog_id(),
                    ]
                )->save();
            }

            $this->dispatch();
            return;
        }

        parent::complete();
    }
}
