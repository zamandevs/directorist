<?php

namespace Directorist\Cache;

if ( ! class_exists( 'Directorist\\Background_Process', false ) ) {
    include_once ATBDP_INC_DIR . 'classes/class-abstract-background-process.php';
}

/**
 * Directorist background-process adapter for resource-catalog reconciliation.
 */
final class Performance_Resource_Process extends \Directorist\Background_Process {
    const ACTION = 'directorist_performance_resource_catalog';

    /** @var callable */
    private $reconciler;

    public function __construct( $reconciler = null ) {
        $this->prefix     = 'wp_' . get_current_blog_id();
        $this->action     = self::ACTION;
        $this->reconciler = is_callable( $reconciler ) ? $reconciler : 'directorist_page_cache_performance_resource_reconciler';
        parent::__construct();
    }

    public function enqueue( $generation ) {
        $generation = max( 1, (int) $generation );
        $result     = $this->push_to_queue( $generation )->save()->dispatch();

        return ! is_wp_error( $result ) && false !== $result;
    }

    public function reset() {
        $this->delete_all_batches();
        delete_site_transient( $this->identifier . '_process_lock' );
        wp_clear_scheduled_hook( $this->cron_hook_identifier );
    }

    protected function task( $generation ) {
        $reconciler = call_user_func( $this->reconciler );

        if ( ! $reconciler instanceof Performance_Resource_Reconciler ) {
            return false;
        }

        $result = $reconciler->process( $generation );

        return ! empty( $result['success'] ) && 'processing' === $result['code'] ? (int) $generation : false;
    }
}
