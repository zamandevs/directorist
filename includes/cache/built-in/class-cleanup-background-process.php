<?php

namespace Directorist\Cache\Built_In;

if ( ! class_exists( 'Directorist\\Background_Process', false ) ) {
    include_once ATBDP_INC_DIR . 'classes/class-abstract-background-process.php';
}

/**
 * Runs cursor-bounded cache cleanup outside public rendering requests.
 */
final class Cleanup_Background_Process extends \Directorist\Background_Process {
    const ACTION         = 'directorist_page_cache_cleanup';
    const PENDING_OPTION = 'directorist_page_cache_cleanup_pending';
    const MAX_ATTEMPTS   = 3;
    const BATCH_LIMIT    = 100;

    /** @var callable */
    private $runner;

    /** @var callable|null */
    private $dispatcher;

    /**
     * @param array $options Testable cleanup and dispatch boundaries.
     */
    public function __construct( array $options = [] ) {
        $this->prefix     = 'wp_' . get_current_blog_id();
        $this->action     = self::ACTION;
        $this->runner     = isset( $options['runner'] ) && is_callable( $options['runner'] )
            ? $options['runner']
            : static function ( $limit ) {
                $root = WP_CONTENT_DIR . '/cache/directorist-page-cache';

                return ( new Cache_Cleaner( $root ) )->run( $limit );
            };
        $this->dispatcher = isset( $options['dispatcher'] ) && is_callable( $options['dispatcher'] ) ? $options['dispatcher'] : null;

        parent::__construct();
    }

    /** @return array */
    public function enqueue() {
        if ( get_site_option( self::PENDING_OPTION, false ) && ! $this->is_queue_empty() ) {
            return $this->result( true, 'already_queued', [ 'queued' => 0 ] );
        }

        if ( $this->is_queue_empty() ) {
            delete_site_option( self::PENDING_OPTION );
        }

        update_site_option( self::PENDING_OPTION, [ 'queued_at' => time() ] );
        $this->push_to_queue( [ 'type' => 'cleanup', 'attempts' => 0 ] )->save();
        $dispatched = $this->dispatch();
        $success    = ! is_wp_error( $dispatched ) && false !== $dispatched;

        return $this->result( $success, $success ? 'queued' : 'dispatch_failed', [ 'queued' => 1 ] );
    }

    /** @return mixed */
    public function dispatch() {
        if ( $this->dispatcher ) {
            return call_user_func( $this->dispatcher );
        }

        return parent::dispatch();
    }

    /**
     * @param array $item Queue item.
     * @return array
     */
    public function process_item( $item ) {
        if ( ! is_array( $item ) || 'cleanup' !== ( isset( $item['type'] ) ? $item['type'] : '' ) ) {
            return $this->result( false, 'invalid_item', [ 'complete' => true ] );
        }

        try {
            $result = call_user_func( $this->runner, self::BATCH_LIMIT );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->result( false, 'cleanup_exception', [ 'complete' => false ] );
        }

        if ( ! is_array( $result ) || ! isset( $result['success'], $result['code'] ) ) {
            return $this->result( false, 'invalid_cleanup_result', [ 'complete' => false ] );
        }

        return array_merge( $result, [ 'complete' => ! empty( $result['complete'] ) ] );
    }

    /** @return array */
    public function status() {
        return [
            'pending' => (bool) get_site_option( self::PENDING_OPTION, false ),
            'running' => (bool) get_site_transient( $this->identifier . '_process_lock' ),
            'queued'  => ! $this->is_queue_empty(),
        ];
    }

    /** @return void */
    public function reset() {
        $this->delete_all_batches();
        delete_site_transient( $this->identifier . '_process_lock' );
        delete_site_option( self::PENDING_OPTION );
        wp_clear_scheduled_hook( $this->cron_hook_identifier );
    }

    /**
     * @param mixed $item Queue item.
     * @return array|false
     */
    protected function task( $item ) {
        $result = $this->process_item( $item );

        if ( ! empty( $result['success'] ) && empty( $result['complete'] ) ) {
            return $item;
        }

        if ( empty( $result['success'] ) ) {
            $item['attempts'] = isset( $item['attempts'] ) ? (int) $item['attempts'] + 1 : 1;

            if ( self::MAX_ATTEMPTS > $item['attempts'] ) {
                return $item;
            }
        }

        delete_site_option( self::PENDING_OPTION );
        do_action( 'directorist_page_cache_cleanup_completed', $result );

        return false;
    }

    /** @return void */
    protected function complete() {
        delete_site_option( self::PENDING_OPTION );
        parent::complete();
    }

    /**
     * @param bool   $success Result state.
     * @param string $code Stable result code.
     * @param array  $context Additional bounded values.
     * @return array
     */
    private function result( $success, $code, array $context = [] ) {
        return array_merge( [ 'success' => (bool) $success, 'code' => (string) $code ], $context );
    }
}
