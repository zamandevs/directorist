<?php

namespace Directorist\Cache;

/**
 * One bounded, resumable bulk cache operation for the Performance screen.
 */
final class Performance_Job_Manager {
    const OPTION_NAME          = 'directorist_performance_cache_job';
    const LOCK_OPTION          = 'directorist_performance_cache_job_lock';
    const BATCH_SIZE           = 50;
    const OPERATION_URL_LIMIT  = 50;
    const LOCK_TTL             = 30;
    const LOCK_WAIT_SECONDS    = 5;
    const STALL_GRACE          = 30;
    const MAX_STALL_RECOVERIES = 2;
    const RECOVERY_BATCH_SIZE  = 50;
    const MAX_FAILURE_CODES    = 12;
    const MAX_FAILURE_ITEMS    = 50;
    const MONITOR_HOOK         = 'directorist_performance_cache_job_monitor';

    /** @var Performance_Resource_Catalog */
    private $resources;

    /** @var Performance_Operations */
    private $operations;

    /** @var callable */
    private $dispatcher;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $warm_queue_canceller;

    /** @var callable */
    private $warm_queue_status;

    /** @var callable */
    private $warm_queue_recoverer;

    /** @var Performance_Job_Ledger */
    private $ledger;

    public function __construct( Performance_Resource_Catalog $resources, Performance_Operations $operations, $dispatcher = null, $clock = null, $warm_queue_canceller = null, $warm_queue_status = null, $warm_queue_recoverer = null, Performance_Job_Ledger $ledger = null ) {
        $this->resources            = $resources;
        $this->operations           = $operations;
        $this->dispatcher           = is_callable( $dispatcher ) ? $dispatcher : static function ( $job_id ) {
            return function_exists( 'directorist_page_cache_performance_job_process' )
                && directorist_page_cache_performance_job_process()->enqueue( $job_id );
        };
        $this->clock                = is_callable( $clock ) ? $clock : 'time';
        $this->warm_queue_canceller = is_callable( $warm_queue_canceller ) ? $warm_queue_canceller : static function () {
            if ( ! function_exists( 'directorist_page_cache_warm_background_process' ) ) {
                return false;
            }

            try {
                $worker = directorist_page_cache_warm_background_process();
                $status = is_object( $worker ) && is_callable( [ $worker, 'status' ] ) ? $worker->status() : [];

                if ( ! is_array( $status ) || ( empty( $status['running'] ) && empty( $status['queued'] ) ) ) {
                    return false;
                }

                if ( is_callable( [ $worker, 'cancel' ] ) ) {
                    $result = $worker->cancel();

                    return is_array( $result ) && ! empty( $result['success'] );
                }

                if ( is_callable( [ $worker, 'reset' ] ) ) {
                    $worker->reset();

                    return true;
                }
            } catch ( \Throwable $exception ) {
                unset( $exception );
            }

            return false;
        };
        $this->warm_queue_status    = is_callable( $warm_queue_status ) ? $warm_queue_status : static function () {
            if ( ! function_exists( 'directorist_page_cache_warm_background_process' ) ) {
                return [];
            }

            try {
                return directorist_page_cache_warm_background_process()->status();
            } catch ( \Throwable $exception ) {
                unset( $exception );

                return [];
            }
        };
        $this->warm_queue_recoverer = is_callable( $warm_queue_recoverer ) ? $warm_queue_recoverer : static function ( array $urls, $job_id ) {
            if ( ! function_exists( 'directorist_page_cache_warm_background_process' ) ) {
                return [ 'success' => false, 'code' => 'worker_unavailable', 'queued' => 0 ];
            }

            try {
                return directorist_page_cache_warm_background_process()->recover( $urls, $job_id );
            } catch ( \Throwable $exception ) {
                unset( $exception );

                return [ 'success' => false, 'code' => 'recovery_exception', 'queued' => 0 ];
            }
        };
        $this->ledger               = $ledger ?: new Performance_Job_Ledger();
    }

    /**
     * @param string $action Warm or purge canonical URLs.
     * @param array  $scope Resource filters.
     * @return array
     */
    public function start( $action, array $scope = [] ) {
        $action = sanitize_key( (string) $action );

        if ( ! in_array( $action, [ 'warm', 'purge' ], true ) ) {
            return $this->result( false, 'invalid-action' );
        }

        $scope     = $this->normalize_scope( $scope );
        $signature = hash( 'sha256', $action . '|' . wp_json_encode( $scope ) );
        $current   = $this->stored_job();

        if ( ! empty( $current ) && in_array( $current['state'], [ 'queued', 'running', 'verifying' ], true ) ) {
            return $this->result(
                $current['signature'] === $signature,
                $current['signature'] === $signature ? 'already-running' : 'job-conflict',
                $current
            );
        }

        try {
            $preview = $this->resources->get_urls(
                array_merge(
                    $scope,
                    [ 'page' => 1, 'per_page' => 1 ]
                )
            );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->result( false, 'resource-discovery-failed' );
        }

        if ( empty( $preview['total'] ) || empty( $preview['resources'] ) ) {
            $lock = $this->acquire_job_lock();

            if ( '' === $lock ) {
                return $this->result( false, 'job-busy', $this->stored_job() );
            }

            try {
                $current = $this->fresh_stored_job();

                if ( ! empty( $current ) && in_array( isset( $current['state'] ) ? $current['state'] : '', [ 'queued', 'running', 'verifying' ], true ) ) {
                    return $this->result(
                        isset( $current['signature'] ) && $current['signature'] === $signature,
                        isset( $current['signature'] ) && $current['signature'] === $signature ? 'already-running' : 'job-conflict',
                        $current
                    );
                }

                if ( ! empty( $current['id'] ) ) {
                    $this->ledger->cleanup( $current['id'] );
                }

                delete_option( self::OPTION_NAME );
            } finally {
                $this->release_job_lock( $lock );
            }

            return $this->result( false, 'no-resources' );
        }

        $now     = (int) call_user_func( $this->clock );
        $total   = max( 0, (int) $preview['total'] );
        $reverse = 'purge' === $action || '' !== $scope['cache_state'];
        $job     = [
            'id'                 => wp_generate_uuid4(),
            'action'             => $action,
            'scope'              => $scope,
            'signature'          => $signature,
            'state'              => 'queued',
            'cursor'             => $reverse ? max( 1, (int) ceil( $total / self::BATCH_SIZE ) ) : 1,
            'processed'          => 0,
            'discovered'         => 0,
            'queued_urls'        => 0,
            'warmed_urls'        => 0,
            'failed_urls'        => 0,
            'failure_codes'      => [],
            'failure_items'      => [],
            'discovery_complete' => false,
            'url_results'        => [],
            'total'              => $total,
            'failures'           => 0,
            'recovery_rounds'    => 0,
            'code'               => 'queued',
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $lock = $this->acquire_job_lock();

        if ( '' === $lock ) {
            return $this->result( false, 'job-busy', $this->stored_job() );
        }

        try {
            $current = $this->fresh_stored_job();

            if ( ! empty( $current ) && in_array( isset( $current['state'] ) ? $current['state'] : '', [ 'queued', 'running', 'verifying' ], true ) ) {
                return $this->result(
                    isset( $current['signature'] ) && $current['signature'] === $signature,
                    isset( $current['signature'] ) && $current['signature'] === $signature ? 'already-running' : 'job-conflict',
                    $current
                );
            }

            if ( ! empty( $current['id'] ) ) {
                $this->ledger->cleanup( $current['id'] );
            }

            update_option( self::OPTION_NAME, $job, false );
        } finally {
            $this->release_job_lock( $lock );
        }

        try {
            $dispatched = call_user_func( $this->dispatcher, $job['id'] );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $dispatched = false;
        }

        if ( ! $dispatched ) {
            $lock = $this->acquire_job_lock();

            if ( '' !== $lock ) {
                try {
                    $stored = $this->fresh_stored_job();

                    if ( isset( $stored['id'] ) && hash_equals( $stored['id'], $job['id'] ) ) {
                        $job               = $stored;
                        $job['state']      = 'failed';
                        $job['code']       = 'dispatch-failed';
                        $job['updated_at'] = (int) call_user_func( $this->clock );
                        update_option( self::OPTION_NAME, $job, false );
                    }
                } finally {
                    $this->release_job_lock( $lock );
                }
            }

            return $this->result( false, 'dispatch-failed', $job );
        }

        return $this->result( true, 'queued', $job );
    }

    /**
     * Process one bounded resource page.
     *
     * @param string $job_id Job identity.
     * @return array Public job state.
     */
    public function process( $job_id ) {
        $job = $this->stored_job();

        if ( empty( $job ) || ! hash_equals( $job['id'], (string) $job_id ) ) {
            return $this->public_job( [ 'state' => 'failed', 'code' => 'job-not-found' ] );
        }

        if ( in_array( $job['state'], [ 'completed', 'cancelled', 'failed', 'verifying' ], true ) ) {
            return $this->public_job( $job );
        }

        $batch     = $this->resources->get_urls(
            array_merge(
                $job['scope'],
                [ 'page' => $job['cursor'], 'per_page' => self::BATCH_SIZE ]
            )
        );
        $operation = 'warm' === $job['action'] ? 'warm_urls' : 'purge_urls';
        $result    = $this->execute_url_batch(
            $operation,
            isset( $batch['urls'] ) ? $batch['urls'] : [],
            $job['id'],
            isset( $batch['contexts'] ) && is_array( $batch['contexts'] ) ? $batch['contexts'] : []
        );

        $lock = $this->acquire_job_lock();

        if ( '' === $lock ) {
            return $this->public_job( $this->stored_job() );
        }

        try {
            $stored = $this->fresh_stored_job();

            if ( empty( $stored ) || empty( $stored['id'] ) || ! hash_equals( $stored['id'], (string) $job_id ) ) {
                return $this->public_job( [ 'state' => 'failed', 'code' => 'job-not-found' ] );
            }

            if ( in_array( $stored['state'], [ 'completed', 'cancelled', 'failed' ], true ) ) {
                $terminal = $this->public_job( $stored );
                $this->release_job_lock( $lock );
                $lock = '';

                // A batch may have enqueued URLs after cancel() reset the warm
                // queue but before it could observe the terminal job state.
                if ( 'cancelled' === $stored['state'] && 'warm' === $stored['action'] ) {
                    try {
                        call_user_func( $this->warm_queue_canceller );
                    } catch ( \Throwable $exception ) {
                        unset( $exception );
                    }
                }

                return $terminal;
            }

            // Worker callbacks can finish during the provider call. Extend their
            // latest counters instead of replacing them with the earlier snapshot.
            $job          = $stored;
            $job['total'] = max( isset( $job['total'] ) ? (int) $job['total'] : 0, (int) $batch['total'] );
            $accepted     = 'warm' === $job['action'] && isset( $result['accepted_urls'] ) && is_array( $result['accepted_urls'] )
                ? $result['accepted_urls']
                : [];

            if ( ! empty( $accepted ) ) {
                $accepted_count = $this->ledger->append( $job['id'], $accepted );

                if ( false === $accepted_count ) {
                    $result = [ 'success' => false, 'code' => 'ledger-write-failed', 'queued' => count( $accepted ), 'accepted_urls' => $accepted ];
                } else {
                    $job['queued_urls'] += $accepted_count;
                }
            }

            if ( empty( $result['success'] ) ) {
                $job['failures'] = isset( $job['failures'] ) ? max( 0, (int) $job['failures'] ) + 1 : 1;
                $job['state']    = 3 <= $job['failures'] ? 'failed' : 'running';
                $job['code']     = isset( $result['code'] ) ? sanitize_key( (string) $result['code'] ) : 'operation-failed';
            } else {
                $job['processed'] += isset( $batch['resources'] ) ? max( 0, (int) $batch['resources'] ) : count( $batch['urls'] );
                $job['discovered'] = $job['processed'];
                $job['failures']   = 0;

                // Mutating filtered results can shift offsets, so traverse those stable pages backwards.
                $reverse   = 'purge' === $job['action'] || ! empty( $job['scope']['cache_state'] );
                $last_page = $reverse
                    ? $job['cursor'] <= 1
                    : $job['cursor'] >= $batch['pages'];

                if ( $last_page || $job['processed'] >= $job['total'] ) {
                    $job['discovery_complete'] = true;

                    if ( 'warm' === $job['action'] ) {
                        $job['state'] = 0 < $job['queued_urls'] ? 'verifying' : 'failed';
                        $job['code']  = 0 < $job['queued_urls'] ? 'verifying' : 'no-urls-queued';
                        $job          = $this->complete_warm_job_if_ready( $job );
                    } else {
                        $job['state'] = 'completed';
                        $job['code']  = 'completed';
                    }
                } else {
                    if ( $reverse ) {
                        --$job['cursor'];
                    } else {
                        ++$job['cursor'];
                    }

                    $job['state'] = 'running';
                    $job['code']  = 'processing';
                }
            }

            if ( 'failed' === $job['state'] && ! empty( $job['id'] ) ) {
                $this->ledger->cleanup( $job['id'] );
            }

            $job['updated_at'] = (int) call_user_func( $this->clock );
            update_option( self::OPTION_NAME, $job, false );
        } finally {
            $this->release_job_lock( $lock );
        }

        $this->sync_monitor( $job );

        return $this->public_job( $job );
    }

    /**
     * Record one terminal URL outcome from the anonymous warm worker.
     *
     * @param string $job_id Job identity.
     * @param string $url Warmed URL.
     * @param array  $result Verified worker result.
     * @return array Public job state.
     */
    public function record_warm_result( $job_id, $url, array $result ) {
        $lock = $this->acquire_job_lock();

        if ( '' === $lock ) {
            return $this->public_job( $this->stored_job() );
        }

        try {
            $job = $this->fresh_stored_job();

            if ( empty( $job ) || empty( $job['id'] ) || ! hash_equals( $job['id'], (string) $job_id ) || 'warm' !== $job['action'] ) {
                return $this->public_job( [ 'state' => 'failed', 'code' => 'job-not-found' ] );
            }

            if ( in_array( $job['state'], [ 'completed', 'cancelled', 'failed' ], true ) ) {
                return $this->public_job( $job );
            }

            $hash = hash( 'sha256', esc_url_raw( (string) $url ) );

            if ( isset( $job['url_results'][ $hash ] ) ) {
                return $this->public_job( $job );
            }

            $success                     = ! empty( $result['success'] );
            $job['url_results'][ $hash ] = $success ? 'warmed' : 'failed';
            $job['warmed_urls']          = isset( $job['warmed_urls'] ) ? max( 0, (int) $job['warmed_urls'] ) : 0;
            $job['failed_urls']          = isset( $job['failed_urls'] ) ? max( 0, (int) $job['failed_urls'] ) : 0;

            if ( $success ) {
                ++$job['warmed_urls'];
            } else {
                ++$job['failed_urls'];
                $job['code'] = isset( $result['code'] ) ? sanitize_key( (string) $result['code'] ) : 'warm-failed';
                $job         = $this->add_failure_code( $job, $job['code'] );
                $job         = $this->add_failure_item( $job, $url, $job['code'], $result );
            }

            $job = $this->complete_warm_job_if_ready( $job );

            if ( ! in_array( $job['state'], [ 'completed', 'failed' ], true ) && $this->is_discovery_complete( $job ) ) {
                $job['state'] = 'verifying';

                if ( $success ) {
                    $job['code'] = 'verifying';
                }
            }

            $job['updated_at'] = (int) call_user_func( $this->clock );
            update_option( self::OPTION_NAME, $job, false );
        } finally {
            $this->release_job_lock( $lock );
        }

        $this->sync_monitor( $job );

        return $this->public_job( $job );
    }

    /**
     * Execute every expanded URL without exceeding the operations boundary.
     *
     * @param string   $operation Warm or purge operation.
     * @param string[] $urls Public URLs.
     * @param string   $job_id Performance job identity.
     * @param array    $contexts Resource context keyed by URL hash.
     * @return array
     */
    private function execute_url_batch( $operation, array $urls, $job_id = '', array $contexts = [] ) {
        if ( empty( $urls ) ) {
            return [ 'success' => true, 'code' => 'no-urls', 'queued' => 0 ];
        }

        $queued        = 0;
        $accepted_urls = [];

        foreach ( array_chunk( $urls, self::OPERATION_URL_LIMIT ) as $chunk ) {
            $tagger = static function ( $item ) use ( $job_id, $contexts ) {
                if ( is_array( $item ) && '' !== $job_id ) {
                    $item['performance_job_id'] = $job_id;

                    $url     = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '';
                    $hash    = '' !== $url ? hash( 'sha256', $url ) : '';
                    $context = '' !== $hash && isset( $contexts[ $hash ] ) && is_array( $contexts[ $hash ] )
                        ? $contexts[ $hash ]
                        : [];

                    if ( ! empty( $context['title'] ) ) {
                        $item['performance_resource_title'] = substr( sanitize_text_field( (string) $context['title'] ), 0, 160 );
                    }

                    if ( ! empty( $context['type'] ) ) {
                        $item['performance_resource_type'] = substr( sanitize_key( (string) $context['type'] ), 0, 32 );
                    }
                }

                return $item;
            };

            if ( 'warm_urls' === $operation ) {
                add_filter( 'directorist_page_cache_warm_queue_item', $tagger );
            }

            try {
                $result = $this->operations->execute( $operation, [ 'urls' => $chunk ] );
            } finally {
                if ( 'warm_urls' === $operation ) {
                    remove_filter( 'directorist_page_cache_warm_queue_item', $tagger );
                }
            }

            if ( empty( $result['success'] ) ) {
                return array_merge( $result, [ 'queued' => $queued, 'accepted_urls' => $accepted_urls ] );
            }

            $chunk_queued   = isset( $result['queued'] ) ? max( 0, (int) $result['queued'] ) : count( $chunk );
            $chunk_accepted = isset( $result['accepted_urls'] ) && is_array( $result['accepted_urls'] )
                ? array_values( array_filter( array_map( 'esc_url_raw', $result['accepted_urls'] ) ) )
                : array_slice( $chunk, 0, $chunk_queued );
            $queued        += $chunk_queued;
            $accepted_urls  = array_merge( $accepted_urls, $chunk_accepted );
        }

        return [ 'success' => true, 'code' => 'processed', 'queued' => $queued, 'accepted_urls' => $accepted_urls ];
    }

    /** @return array */
    public function get_current() {
        $lock = $this->acquire_job_lock();

        if ( '' === $lock ) {
            return $this->public_job( $this->stored_job() );
        }

        try {
            $job       = $this->fresh_stored_job();
            $recovered = $this->complete_warm_job_if_ready( $job );

            if ( $recovered === $job ) {
                $recovered = $this->recover_stalled_warm_job( $job );
            }

            $recovered = $this->clear_obsolete_terminal_failures( $recovered );

            if ( $recovered !== $job ) {
                $recovered['updated_at'] = (int) call_user_func( $this->clock );
                update_option( self::OPTION_NAME, $recovered, false );
            }

            $this->sync_monitor( $recovered );

            return $this->public_job( $recovered );
        } finally {
            $this->release_job_lock( $lock );
        }
    }

    /**
     * @param string $job_id Job identity.
     * @return array
     */
    public function cancel( $job_id ) {
        $lock = $this->acquire_job_lock();

        if ( '' === $lock ) {
            return $this->result( false, 'job-busy', $this->stored_job() );
        }

        try {
            $job = $this->fresh_stored_job();

            if ( empty( $job ) || empty( $job['id'] ) || ! hash_equals( $job['id'], (string) $job_id ) ) {
                return $this->result( false, 'job-not-found' );
            }

            $active = in_array( $job['state'], [ 'queued', 'running', 'verifying' ], true );

            // Persist cancellation before touching the provider queue. Any late
            // worker callback will then observe the terminal state and do nothing.
            if ( $active ) {
                $job['state']      = 'cancelled';
                $job['code']       = 'cancelled';
                $job['updated_at'] = (int) call_user_func( $this->clock );
                update_option( self::OPTION_NAME, $job, false );
            }
        } finally {
            $this->release_job_lock( $lock );
        }

        $queue_cancelled = false;

        if ( 'warm' === $job['action'] && ( $active || 'completed' === $job['state'] ) ) {
            try {
                $queue_cancelled = (bool) call_user_func( $this->warm_queue_canceller );
            } catch ( \Throwable $exception ) {
                unset( $exception );
            }
        }

        if ( ! $active && ! $queue_cancelled ) {
            return $this->result( false, 'job-not-active', $job );
        }

        if ( ! $active ) {
            $lock = $this->acquire_job_lock();

            if ( '' === $lock ) {
                return $this->result( false, 'job-busy', $this->stored_job() );
            }

            try {
                $stored = $this->fresh_stored_job();

                if ( empty( $stored ) || empty( $stored['id'] ) || ! hash_equals( $stored['id'], (string) $job_id ) ) {
                    return $this->result( false, 'job-not-found' );
                }

                $job               = $stored;
                $job['state']      = 'cancelled';
                $job['code']       = 'cancelled';
                $job['updated_at'] = (int) call_user_func( $this->clock );
                update_option( self::OPTION_NAME, $job, false );
            } finally {
                $this->release_job_lock( $lock );
            }
        }

        if ( ! empty( $job['id'] ) ) {
            $this->ledger->cleanup( $job['id'] );
        }

        $this->sync_monitor( $job );

        return $this->result( true, 'cancelled', $job );
    }

    /**
     * Reconcile an active warm job without depending on an open admin screen.
     *
     * @param string $job_id Job identity.
     * @return array Public job state.
     */
    public function monitor( $job_id ) {
        $job = $this->get_current();

        if ( empty( $job['id'] ) || ! hash_equals( $job['id'], (string) $job_id ) ) {
            return $this->public_job( [ 'state' => 'failed', 'code' => 'job-not-found' ] );
        }

        return $job;
    }

    /** @return array */
    public function cancel_current() {
        $job = $this->stored_job();

        if ( empty( $job['id'] ) || ! in_array( isset( $job['state'] ) ? $job['state'] : '', [ 'queued', 'running', 'verifying' ], true ) ) {
            return $this->result( false, 'job-not-active', $job );
        }

        return $this->cancel( $job['id'] );
    }

    private function acquire_job_lock() {
        $token    = wp_generate_uuid4();
        $deadline = microtime( true ) + self::LOCK_WAIT_SECONDS;

        do {
            $existing = get_option( self::LOCK_OPTION, [] );

            if ( is_array( $existing ) && ! empty( $existing['acquired_at'] ) && (int) $existing['acquired_at'] < time() - self::LOCK_TTL ) {
                delete_option( self::LOCK_OPTION );
            }

            if ( add_option( self::LOCK_OPTION, [ 'token' => $token, 'acquired_at' => time() ], '', false ) ) {
                return $token;
            }

            usleep( 20000 );
        } while ( microtime( true ) < $deadline );

        return '';
    }

    private function release_job_lock( $token ) {
        $lock = get_option( self::LOCK_OPTION, [] );

        if ( is_array( $lock ) && ! empty( $lock['token'] ) && is_string( $token ) && hash_equals( (string) $lock['token'], $token ) ) {
            delete_option( self::LOCK_OPTION );
        }
    }

    private function stored_job() {
        $job = get_option( self::OPTION_NAME, [] );

        return is_array( $job ) ? $job : [];
    }

    private function fresh_stored_job() {
        // A concurrent request can update the option while this request is
        // warming URLs. Discard its request-local option copy after acquiring
        // the mutex so the merge decision uses the latest database state.
        wp_cache_delete( self::OPTION_NAME, 'options' );

        return $this->stored_job();
    }

    private function complete_warm_job_if_ready( array $job ) {
        if ( 'warm' !== ( isset( $job['action'] ) ? $job['action'] : '' ) || ! $this->is_discovery_complete( $job ) ) {
            return $job;
        }

        if ( in_array( isset( $job['state'] ) ? $job['state'] : '', [ 'completed', 'cancelled', 'failed' ], true ) ) {
            return $job;
        }

        $queued   = isset( $job['queued_urls'] ) ? max( 0, (int) $job['queued_urls'] ) : 0;
        $warmed   = isset( $job['warmed_urls'] ) ? max( 0, (int) $job['warmed_urls'] ) : 0;
        $failed   = isset( $job['failed_urls'] ) ? max( 0, (int) $job['failed_urls'] ) : 0;
        $terminal = $warmed + $failed;

        if ( 0 === $queued || $terminal < $queued ) {
            return $job;
        }

        $job['discovery_complete'] = true;
        $job['state']              = 0 < $failed ? 'failed' : 'completed';
        $job['code']               = 0 < $failed ? 'completed-with-failures' : 'completed';
        unset( $job['url_results'] );

        if ( ! empty( $job['id'] ) ) {
            $this->ledger->cleanup( $job['id'] );
        }

        return $job;
    }

    private function recover_stalled_warm_job( array $job ) {
        if ( 'warm' !== ( isset( $job['action'] ) ? $job['action'] : '' ) || 'verifying' !== ( isset( $job['state'] ) ? $job['state'] : '' ) ) {
            return $job;
        }

        $queued   = isset( $job['queued_urls'] ) ? max( 0, (int) $job['queued_urls'] ) : 0;
        $warmed   = isset( $job['warmed_urls'] ) ? max( 0, (int) $job['warmed_urls'] ) : 0;
        $failed   = isset( $job['failed_urls'] ) ? max( 0, (int) $job['failed_urls'] ) : 0;
        $terminal = $warmed + $failed;
        $now      = (int) call_user_func( $this->clock );

        if ( $terminal >= $queued || $now <= ( isset( $job['updated_at'] ) ? (int) $job['updated_at'] : 0 ) + self::STALL_GRACE ) {
            return $job;
        }

        try {
            $status = call_user_func( $this->warm_queue_status );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $status = [];
        }

        $status      = is_array( $status ) ? $status : [];
        $queue_alive = ! empty( $status['running'] )
            || ! empty( $status['queued'] )
            || ! empty( $status['deferred'] )
            || ( ! empty( $status['recovery_at'] ) && (int) $status['recovery_at'] > $now )
            || ( ! empty( $status['circuit_open'] ) && ! empty( $status['open_until'] ) && (int) $status['open_until'] > $now );

        if ( $queue_alive ) {
            return $job;
        }

        $missing  = max( 0, $queued - $terminal );
        $results  = isset( $job['url_results'] ) && is_array( $job['url_results'] ) ? $job['url_results'] : [];
        $job_id   = isset( $job['id'] ) ? (string) $job['id'] : '';
        $has_urls = '' !== $job_id && $this->ledger->has_entries( $job_id );

        if ( ! $has_urls ) {
            return $this->fail_incomplete_warm_job( $job, $missing );
        }

        $pending = $this->ledger->pending( $job_id, $results, self::RECOVERY_BATCH_SIZE );

        if ( empty( $pending ) ) {
            return $this->fail_incomplete_warm_job( $job, $missing );
        }

        $previous_terminal = isset( $job['recovery_terminal_count'] ) ? max( 0, (int) $job['recovery_terminal_count'] ) : $terminal;
        $rounds            = isset( $job['recovery_rounds'] ) ? max( 0, (int) $job['recovery_rounds'] ) : 0;

        if ( $terminal > $previous_terminal ) {
            $rounds = 0;
        }

        if ( self::MAX_STALL_RECOVERIES <= $rounds ) {
            return $this->fail_incomplete_warm_job( $job, $missing );
        }

        try {
            $result = call_user_func( $this->warm_queue_recoverer, $pending, $job_id );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $result = [ 'success' => false, 'code' => 'recovery_exception', 'queued' => 0 ];
        }

        ++$rounds;
        $job['recovery_rounds']         = $rounds;
        $job['recovery_terminal_count'] = $terminal;
        $job['updated_at']              = $now;

        if ( is_array( $result ) && ! empty( $result['success'] ) && ! empty( $result['queued'] ) ) {
            $job['code'] = 'recovering';

            return $job;
        }

        if ( self::MAX_STALL_RECOVERIES <= $rounds ) {
            return $this->fail_incomplete_warm_job( $job, $missing );
        }

        $job['code'] = 'recovery-delayed';

        return $job;
    }

    private function fail_incomplete_warm_job( array $job, $missing ) {
        $missing            = max( 0, (int) $missing );
        $job['failed_urls'] = isset( $job['failed_urls'] ) ? max( 0, (int) $job['failed_urls'] ) + $missing : $missing;
        $job                = $this->add_failure_code( $job, 'incomplete-warm-results', max( 1, $missing ) );
        $job['state']       = 'failed';
        $job['code']        = 'incomplete-warm-results';
        $job['updated_at']  = (int) call_user_func( $this->clock );
        unset( $job['url_results'] );

        if ( ! empty( $job['id'] ) ) {
            $this->ledger->cleanup( $job['id'] );
        }

        return $job;
    }

    private function add_failure_code( array $job, $code, $count = 1 ) {
        $code  = substr( sanitize_key( (string) $code ), 0, 64 );
        $code  = '' !== $code ? $code : 'warm-failed';
        $count = max( 1, (int) $count );
        $codes = isset( $job['failure_codes'] ) && is_array( $job['failure_codes'] ) ? $job['failure_codes'] : [];

        if ( ! isset( $codes[ $code ] ) && self::MAX_FAILURE_CODES <= count( $codes ) ) {
            $code = 'other';
        }

        $codes[ $code ]       = isset( $codes[ $code ] ) ? max( 0, (int) $codes[ $code ] ) + $count : $count;
        $job['failure_codes'] = $codes;

        return $job;
    }

    private function add_failure_item( array $job, $url, $code, array $result ) {
        $items = isset( $job['failure_items'] ) && is_array( $job['failure_items'] ) ? $job['failure_items'] : [];

        if ( self::MAX_FAILURE_ITEMS <= count( $items ) ) {
            return $job;
        }

        $item = $this->normalize_failure_item(
            [
                'url'   => $url,
                'title' => isset( $result['resource_title'] ) ? $result['resource_title'] : '',
                'type'  => isset( $result['resource_type'] ) ? $result['resource_type'] : '',
                'code'  => $code,
            ]
        );

        if ( empty( $item ) ) {
            return $job;
        }

        $hash = hash( 'sha256', $item['url'] );

        if ( ! isset( $items[ $hash ] ) ) {
            $items[ $hash ] = $item;
        }

        $job['failure_items'] = $items;

        return $job;
    }

    private function normalize_failure_item( array $item ) {
        $url   = isset( $item['url'] ) ? substr( esc_url_raw( (string) $item['url'] ), 0, 2048 ) : '';
        $parts = wp_parse_url( $url );
        $home  = wp_parse_url( home_url( '/' ) );

        if ( '' === $url || ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['host'] ) || empty( $home['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ! isset( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
            return [];
        }

        $port      = isset( $parts['port'] ) ? absint( $parts['port'] ) : ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 );
        $home_port = isset( $home['port'] ) ? absint( $home['port'] ) : ( isset( $home['scheme'] ) && 'https' === strtolower( $home['scheme'] ) ? 443 : 80 );

        if ( empty( $home['scheme'] ) || strtolower( $parts['scheme'] ) !== strtolower( $home['scheme'] ) || strtolower( rtrim( $parts['host'], '.' ) ) !== strtolower( rtrim( $home['host'], '.' ) ) || $port !== $home_port ) {
            return [];
        }

        return [
            'url'   => $url,
            'title' => isset( $item['title'] ) ? substr( sanitize_text_field( (string) $item['title'] ), 0, 160 ) : '',
            'type'  => isset( $item['type'] ) ? substr( sanitize_key( (string) $item['type'] ), 0, 32 ) : '',
            'code'  => isset( $item['code'] ) ? substr( sanitize_key( (string) $item['code'] ), 0, 64 ) : 'warm-failed',
        ];
    }

    private function public_failure_items( array $job ) {
        $items  = isset( $job['failure_items'] ) && is_array( $job['failure_items'] ) ? $job['failure_items'] : [];
        $public = [];

        foreach ( array_slice( $items, 0, self::MAX_FAILURE_ITEMS, true ) as $item ) {
            $item = is_array( $item ) ? $this->normalize_failure_item( $item ) : [];

            if ( ! empty( $item ) ) {
                $public[] = $item;
            }
        }

        return $public;
    }

    private function failure_summary( array $job ) {
        $codes = isset( $job['failure_codes'] ) && is_array( $job['failure_codes'] ) ? $job['failure_codes'] : [];
        $best  = '';
        $count = 0;

        foreach ( $codes as $code => $candidate_count ) {
            $candidate_count = max( 0, (int) $candidate_count );

            if ( $candidate_count > $count ) {
                $best  = substr( sanitize_key( (string) $code ), 0, 64 );
                $count = $candidate_count;
            }
        }

        return [ 'code' => $best, 'count' => $count ];
    }

    private function clear_obsolete_terminal_failures( array $job ) {
        $state  = isset( $job['state'] ) ? $job['state'] : '';
        $failed = isset( $job['failed_urls'] ) ? max( 0, (int) $job['failed_urls'] ) : 0;

        if ( 'warm' !== ( isset( $job['action'] ) ? $job['action'] : '' ) || ! in_array( $state, [ 'completed', 'failed' ], true ) || 1 > $failed ) {
            return $job;
        }

        try {
            $current_failures = $this->resources->get_failed_resource_count( isset( $job['scope'] ) && is_array( $job['scope'] ) ? $job['scope'] : [] );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $job;
        }

        if ( 0 !== $current_failures ) {
            return $job;
        }

        $job['queued_urls']   = max( isset( $job['warmed_urls'] ) ? (int) $job['warmed_urls'] : 0, ( isset( $job['queued_urls'] ) ? (int) $job['queued_urls'] : 0 ) - $failed );
        $job['failed_urls']   = 0;
        $job['failure_codes'] = [];
        $job['failure_items'] = [];
        $job['state']         = 'completed';
        $job['code']          = 'completed';

        return $job;
    }

    /** @param array $job Current private job state. */
    private function sync_monitor( array $job ) {
        $job_id = isset( $job['id'] ) ? (string) $job['id'] : '';

        if ( '' === $job_id ) {
            return;
        }

        $args = [ $job_id ];

        if ( 'warm' === ( isset( $job['action'] ) ? $job['action'] : '' ) && 'verifying' === ( isset( $job['state'] ) ? $job['state'] : '' ) ) {
            if ( ! wp_next_scheduled( self::MONITOR_HOOK, $args ) ) {
                wp_schedule_single_event( (int) call_user_func( $this->clock ) + self::STALL_GRACE + 1, self::MONITOR_HOOK, $args );
            }

            return;
        }

        wp_clear_scheduled_hook( self::MONITOR_HOOK, $args );
    }

    private function is_discovery_complete( array $job ) {
        if ( array_key_exists( 'discovery_complete', $job ) ) {
            return ! empty( $job['discovery_complete'] );
        }

        return 'verifying' === ( isset( $job['state'] ) ? $job['state'] : '' );
    }

    private function normalize_scope( array $scope ) {
        $type            = isset( $scope['type'] ) && in_array( $scope['type'], [ 'all', 'listing', 'archive', 'page', 'search' ], true ) ? $scope['type'] : 'all';
        $listing_filters = [
            'directory_id' => isset( $scope['directory_id'] ) ? absint( $scope['directory_id'] ) : 0,
            'category_id'  => isset( $scope['category_id'] ) ? absint( $scope['category_id'] ) : 0,
            'location_id'  => isset( $scope['location_id'] ) ? absint( $scope['location_id'] ) : 0,
        ];

        if ( array_filter( $listing_filters ) ) {
            $type = 'listing';
        }

        return array_merge(
            [
                'type'        => $type,
                'search'      => isset( $scope['search'] ) ? substr( sanitize_text_field( (string) $scope['search'] ), 0, 100 ) : '',
                'cache_state' => isset( $scope['cache_state'] ) && in_array( $scope['cache_state'], [ 'needs-refresh', 'uncached', 'current', 'stale', 'expired', 'invalidated', 'failed' ], true ) ? $scope['cache_state'] : '',
            ],
            $listing_filters
        );
    }

    private function public_job( array $job ) {
        if ( empty( $job ) ) {
            return [ 'state' => 'idle', 'progress' => 0 ];
        }

        $total      = isset( $job['total'] ) ? max( 0, (int) $job['total'] ) : 0;
        $processed  = isset( $job['processed'] ) ? max( 0, (int) $job['processed'] ) : 0;
        $discovered = isset( $job['discovered'] ) ? max( 0, (int) $job['discovered'] ) : $processed;
        $queued     = isset( $job['queued_urls'] ) ? max( 0, (int) $job['queued_urls'] ) : 0;
        $warmed     = isset( $job['warmed_urls'] ) ? max( 0, (int) $job['warmed_urls'] ) : 0;
        $failed     = isset( $job['failed_urls'] ) ? max( 0, (int) $job['failed_urls'] ) : 0;
        $progress   = 0 < $total ? min( 100, (int) floor( 100 * $processed / $total ) ) : 0;

        if ( 'warm' === ( isset( $job['action'] ) ? $job['action'] : '' ) ) {
            $discovery_progress = 0 < $total ? min( 40, (int) floor( 40 * $discovered / $total ) ) : 0;
            $terminal_progress  = 0 < $queued ? min( 60, (int) floor( 60 * ( $warmed + $failed ) / $queued ) ) : 0;
            $progress           = 'completed' === ( isset( $job['state'] ) ? $job['state'] : '' ) ? 100 : min( 99, $discovery_progress + $terminal_progress );
        }

        $failure = $this->failure_summary( $job );
        $items   = $this->public_failure_items( $job );

        return [
            'id'                    => isset( $job['id'] ) ? (string) $job['id'] : '',
            'action'                => isset( $job['action'] ) ? sanitize_key( (string) $job['action'] ) : '',
            'scope'                 => isset( $job['scope'] ) && is_array( $job['scope'] ) ? $job['scope'] : [],
            'state'                 => isset( $job['state'] ) ? sanitize_key( (string) $job['state'] ) : 'failed',
            'code'                  => isset( $job['code'] ) ? sanitize_key( (string) $job['code'] ) : '',
            'processed'             => $processed,
            'discovered'            => $discovered,
            'queued'                => $queued,
            'warmed'                => $warmed,
            'failed'                => $failed,
            'failure_code'          => $failure['code'],
            'failure_count'         => $failure['count'],
            'failure_items'         => $items,
            'failure_items_omitted' => max( 0, $failed - count( $items ) ),
            'total'                 => $total,
            'progress'              => $progress,
            'created_at'            => isset( $job['created_at'] ) ? max( 0, (int) $job['created_at'] ) : 0,
            'updated_at'            => isset( $job['updated_at'] ) ? max( 0, (int) $job['updated_at'] ) : 0,
        ];
    }

    private function result( $success, $code, array $job = [] ) {
        return [
            'success' => (bool) $success,
            'code'    => (string) $code,
            'job'     => $this->public_job( $job ),
        ];
    }
}
