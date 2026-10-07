<?php

namespace Directorist\Cache;

if ( ! class_exists( 'Directorist\\Background_Process', false ) ) {
    include_once ATBDP_INC_DIR . 'classes/class-abstract-background-process.php';
}

/**
 * Directorist background-process adapter for Performance bulk jobs.
 */
final class Performance_Job_Process extends \Directorist\Background_Process {
    const ACTION = 'directorist_performance_cache_job';

    /** @var callable */
    private $manager;

    public function __construct( $manager = null ) {
        $this->prefix  = 'wp_' . get_current_blog_id();
        $this->action  = self::ACTION;
        $this->manager = is_callable( $manager ) ? $manager : 'directorist_page_cache_performance_job_manager';
        parent::__construct();
    }

    /**
     * @param string $job_id Job identity.
     * @return bool
     */
    public function enqueue( $job_id ) {
        if ( ! is_string( $job_id ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $job_id ) ) {
            return false;
        }

        $result = $this->push_to_queue( $job_id )->save()->dispatch();

        return ! is_wp_error( $result ) && false !== $result;
    }

    /**
     * Bind the request-scoped manager used by a signed successor request.
     *
     * @param callable $manager Performance job manager resolver.
     * @return bool
     */
    public function set_manager( $manager ) {
        if ( ! is_callable( $manager ) ) {
            return false;
        }

        $this->manager = $manager;

        return true;
    }

    /** @return void */
    public function reset() {
        $this->delete_all_batches();
        delete_site_transient( $this->identifier . '_process_lock' );
        wp_clear_scheduled_hook( $this->cron_hook_identifier );
    }

    /**
     * @param mixed $job_id Job identity.
     * @return string|false
     */
    protected function task( $job_id ) {
        $manager = call_user_func( $this->manager );

        if ( ! $manager instanceof Performance_Job_Manager ) {
            return false;
        }

        $job = $manager->process( (string) $job_id );

        return in_array( $job['state'], [ 'queued', 'running' ], true ) ? (string) $job_id : false;
    }
}
