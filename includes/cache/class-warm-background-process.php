<?php

namespace Directorist\Cache;

if ( ! class_exists( 'Directorist\\Background_Process', false ) ) {
    include_once ATBDP_INC_DIR . 'classes/class-abstract-background-process.php';
}

/**
 * Provider-neutral anonymous loopback warmer for external cache engines.
 */
final class Warm_Background_Process extends \Directorist\Background_Process {
    const ACTION             = 'directorist_page_cache_warm';
    const RETRY_HOOK         = 'directorist_page_cache_retry_warm_url';
    const RETRY_BATCH_HOOK   = 'directorist_page_cache_retry_warm_batch';
    const MAX_URLS           = 50;
    const MAX_ATTEMPTS       = 4;
    const MAX_DEFERRED_ITEMS = 5000;
    const CIRCUIT_FAILURES   = 4;
    const CIRCUIT_SECONDS    = 300;
    const DEDUPE_SECONDS     = 300;
    const MAX_DEDUPE_KEYS    = 200;
    const DEFERRED_LOCK_TTL  = 30;

    /** @var callable */
    private $requester;

    /** @var callable|null */
    private $dispatcher;

    /** @var callable */
    private $scheduler;

    /** @var callable */
    private $unscheduler;

    /** @var bool */
    private $uses_wordpress_scheduler;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $verifier;

    /** @var callable|null */
    private $queue_persister;

    /** @var callable */
    private $refresh_token;

    /**
     * @param array $options Testable HTTP, dispatch, schedule, and clock boundaries.
     */
    public function __construct( array $options = [] ) {
        $this->prefix                   = 'wp_' . get_current_blog_id();
        $this->action                   = self::ACTION;
        $this->requester                = isset( $options['requester'] ) && is_callable( $options['requester'] ) ? $options['requester'] : 'wp_remote_get';
        $this->dispatcher               = isset( $options['dispatcher'] ) && is_callable( $options['dispatcher'] ) ? $options['dispatcher'] : null;
        $this->uses_wordpress_scheduler = empty( $options['scheduler'] ) && empty( $options['unscheduler'] );
        $this->scheduler                = isset( $options['scheduler'] ) && is_callable( $options['scheduler'] ) ? $options['scheduler'] : static function ( $timestamp, $hook, array $args ) {
            return wp_schedule_single_event( $timestamp, $hook, $args );
        };
        $this->unscheduler              = isset( $options['unscheduler'] ) && is_callable( $options['unscheduler'] ) ? $options['unscheduler'] : static function ( $hook ) {
            if ( function_exists( 'wp_unschedule_hook' ) ) {
                return wp_unschedule_hook( $hook );
            }

            $removed = 0;

            foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
                foreach ( isset( $hooks[ $hook ] ) ? $hooks[ $hook ] : [] as $event ) {
                    if ( false !== wp_unschedule_event( $timestamp, $hook, isset( $event['args'] ) ? $event['args'] : [] ) ) {
                        ++$removed;
                    }
                }
            }

            return $removed;
        };
        $this->clock                    = isset( $options['clock'] ) && is_callable( $options['clock'] ) ? $options['clock'] : 'time';
        $this->verifier                 = isset( $options['verifier'] ) && is_callable( $options['verifier'] ) ? $options['verifier'] : static function () {
            return [ 'success' => true, 'code' => 'http-current' ];
        };
        $this->queue_persister          = isset( $options['queue_persister'] ) && is_callable( $options['queue_persister'] ) ? $options['queue_persister'] : null;
        $this->refresh_token            = isset( $options['refresh_token'] ) && is_callable( $options['refresh_token'] )
            ? $options['refresh_token']
            : static function () {
                return function_exists( 'directorist_page_cache_refresh_token' ) ? directorist_page_cache_refresh_token() : '';
            };

        parent::__construct();
        add_action( self::RETRY_HOOK, [ $this, 'retry' ] );
        add_action( self::RETRY_BATCH_HOOK, [ $this, 'retry_deferred' ] );
    }

    /**
     * @param string[] $urls Public same-origin URLs.
     * @return array
     */
    public function enqueue( array $urls ) {
        $registry = new Warm_URL_Registry( home_url( '/' ), self::MAX_URLS );
        $registry->add( $urls, 'automatic' );
        $queued     = 0;
        $generation = $this->generation();

        $public_urls = array_values( array_filter( $registry->all(), [ $this, 'is_public_url' ] ) );

        $accepted_urls = $this->claim_urls( $public_urls );

        foreach ( $accepted_urls as $url ) {

            $item = apply_filters(
                'directorist_page_cache_warm_queue_item',
                [ 'url' => $url, 'attempts' => 0, 'generation' => $generation ],
                $url,
                $this
            );

            if ( ! is_array( $item ) ) {
                continue;
            }

            $this->push_to_queue( $item );
            ++$queued;
        }

        if ( ! $queued ) {
            return [ 'success' => true, 'code' => 'no_urls', 'queued' => 0 ];
        }

        if ( ! $this->persist_queue() ) {
            foreach ( $accepted_urls as $url ) {
                $this->release_url( $url );
            }

            return [ 'success' => false, 'code' => 'queue_persist_failed', 'queued' => 0, 'accepted_urls' => [] ];
        }

        $dispatched = $this->dispatch();

        return [
            'success'       => ! is_wp_error( $dispatched ) && false !== $dispatched,
            'code'          => ! is_wp_error( $dispatched ) && false !== $dispatched ? 'queued' : 'dispatch_failed',
            'queued'        => $queued,
            'accepted_urls' => $accepted_urls,
        ];
    }

    /**
     * Queue one exact cookie/query cache variant from a signed stale handoff.
     *
     * @param string $url Canonical public URL.
     * @param array  $variation Validated cache cookie variation.
     * @param string $cache_hash Expected canonical cache hash.
     * @return array
     */
    public function enqueue_refresh( $url, array $variation, $cache_hash ) {
        $registry = new Warm_URL_Registry( home_url( '/' ), 1 );
        $registry->add( [ $url ], 'soft-expiry' );
        $urls      = $registry->all();
        $variation = $this->normalize_refresh_variation( $variation );

        if ( 1 !== count( $urls ) || false === $variation || ! preg_match( '/^[a-f0-9]{64}$/', (string) $cache_hash ) ) {
            return [ 'success' => false, 'code' => 'invalid_refresh_request', 'queued' => 0, 'accepted_urls' => [] ];
        }

        $key = ( new Built_In\Request_Key() )->from_url( $urls[0], $variation );

        if ( empty( $key['success'] ) || ! hash_equals( $key['hash'], (string) $cache_hash ) ) {
            return [ 'success' => false, 'code' => 'invalid_refresh_key', 'queued' => 0, 'accepted_urls' => [] ];
        }

        if ( ! $this->claim_hash( $key['hash'] ) ) {
            return [ 'success' => true, 'code' => 'no_urls', 'queued' => 0, 'accepted_urls' => [] ];
        }

        $this->push_to_queue(
            [
                'url'        => $key['canonical_url'],
                'attempts'   => 0,
                'generation' => $this->generation(),
                'variation'  => $variation,
                'cache_hash' => $key['hash'],
            ]
        );

        if ( ! $this->persist_queue() ) {
            $this->release_hash( $key['hash'] );

            return [ 'success' => false, 'code' => 'queue_persist_failed', 'queued' => 0, 'accepted_urls' => [] ];
        }

        $dispatched = $this->dispatch();
        $success    = ! is_wp_error( $dispatched ) && false !== $dispatched;

        return [
            'success'       => $success,
            'code'          => $success ? 'queued' : 'dispatch_failed',
            'queued'        => 1,
            'accepted_urls' => [ $key['canonical_url'] ],
        ];
    }

    /**
     * Requeue URLs known to be missing from a Performance job ledger.
     *
     * @param string[] $urls Missing public URLs.
     * @param string   $job_id Performance job identity.
     * @return array
     */
    public function recover( array $urls, $job_id ) {
        $registry = new Warm_URL_Registry( home_url( '/' ), self::MAX_URLS );
        $registry->add( $urls, 'performance-recovery' );
        $urls       = array_values( array_filter( $registry->all(), [ $this, 'is_public_url' ] ) );
        $generation = $this->generation();
        $job_id     = sanitize_text_field( (string) $job_id );

        if ( empty( $urls ) || '' === $job_id ) {
            return [ 'success' => false, 'code' => 'invalid_recovery_urls', 'queued' => 0, 'accepted_urls' => [] ];
        }

        foreach ( $urls as $url ) {
            $this->release_url( $url );
            $this->push_to_queue(
                [
                    'url'                => $url,
                    'attempts'           => 0,
                    'generation'         => $generation,
                    'performance_job_id' => $job_id,
                ]
            );
        }

        if ( ! $this->persist_queue() ) {
            return [ 'success' => false, 'code' => 'queue_persist_failed', 'queued' => 0, 'accepted_urls' => [] ];
        }

        $dispatched = $this->dispatch();
        $success    = ! is_wp_error( $dispatched ) && false !== $dispatched;

        return [
            'success'       => $success,
            'code'          => $success ? 'recovery_queued' : 'recovery_dispatch_failed',
            'queued'        => count( $urls ),
            'accepted_urls' => $urls,
        ];
    }

    /** @return mixed */
    public function dispatch() {
        if ( $this->dispatcher ) {
            return call_user_func( $this->dispatcher );
        }

        return parent::dispatch();
    }

    /**
     * @param array $item Scheduled retry item.
     * @return array
     */
    public function retry( $item ) {
        if ( ! is_array( $item ) || empty( $item['url'] ) || ! $this->is_public_url( $item['url'] ) ) {
            return [ 'success' => false, 'code' => 'invalid_item' ];
        }

        if ( ! $this->is_current_generation( $item ) ) {
            delete_transient( $this->dedupe_option() );

            return [ 'success' => false, 'code' => 'cancelled' ];
        }

        $scheduled = $this->schedule_retry( $item, (int) call_user_func( $this->clock ) );

        if ( ! $scheduled ) {
            $this->finish_retry_failure( $item, 'retry-schedule-failed' );
        }

        return [ 'success' => $scheduled, 'code' => $scheduled ? 'retry_deferred' : 'retry_schedule_failed' ];
    }

    /**
     * Move one bounded set of due retries back to the background queue.
     *
     * @return array
     */
    public function retry_deferred() {
        $lock = $this->acquire_deferred_lock();

        if ( '' === $lock ) {
            $state = $this->deferred_state();
            call_user_func( $this->scheduler, (int) call_user_func( $this->clock ) + MINUTE_IN_SECONDS, self::RETRY_BATCH_HOOK, [] );

            return [ 'success' => true, 'code' => 'retry_batch_locked', 'queued' => 0, 'deferred' => count( $state['items'] ) ];
        }

        try {
            $now                   = (int) call_user_func( $this->clock );
            $state                 = $this->deferred_state();
            $state['scheduled_at'] = 0;
            $this->save_deferred_state( $state );

            if ( empty( $state['items'] ) ) {
                return [ 'success' => true, 'code' => 'no_retries', 'queued' => 0, 'deferred' => 0 ];
            }

            $health = $this->health();

            if ( $health['open_until'] > $now ) {
                $this->schedule_deferred_event( $health['open_until'], true );

                return [
                    'success'  => true,
                    'code'     => 'circuit_open',
                    'queued'   => 0,
                    'deferred' => count( $state['items'] ),
                ];
            }

            uasort(
                $state['items'],
                static function ( $left, $right ) {
                    return (int) $left['run_at'] <=> (int) $right['run_at'];
                }
            );

            $due = [];

            foreach ( $state['items'] as $hash => $record ) {
                if ( (int) $record['run_at'] > $now || self::MAX_URLS <= count( $due ) ) {
                    continue;
                }

                $due[ $hash ] = $record['item'];
            }

            if ( empty( $due ) ) {
                $this->schedule_deferred_successor( $state, $now );

                return [
                    'success'  => true,
                    'code'     => 'retry_batch_waiting',
                    'queued'   => 0,
                    'deferred' => count( $state['items'] ),
                ];
            }

            $queued = 0;

            foreach ( $due as $item ) {
                if ( isset( $item['generation'] ) && (int) $item['generation'] !== $this->generation() ) {
                    $this->release_item( $item );
                    continue;
                }

                $this->push_to_queue( $item );
                ++$queued;
            }

            $dispatched = true;

            if ( $queued ) {
                if ( ! $this->persist_queue() ) {
                    $this->schedule_deferred_successor( $state, $now );

                    return [
                        'success'  => false,
                        'code'     => 'retry_persist_failed',
                        'queued'   => 0,
                        'deferred' => count( $state['items'] ),
                    ];
                }
            }

            foreach ( array_keys( $due ) as $hash ) {
                unset( $state['items'][ $hash ] );
            }

            $this->save_deferred_state( $state );

            if ( $queued ) {
                $dispatched = $this->dispatch();
            }

            $this->schedule_deferred_successor( $state, $now );

            return [
                'success'  => ! is_wp_error( $dispatched ) && false !== $dispatched,
                'code'     => ! is_wp_error( $dispatched ) && false !== $dispatched ? 'retry_batch_queued' : 'retry_dispatch_failed',
                'queued'   => $queued,
                'deferred' => count( $state['items'] ),
            ];
        } finally {
            $this->release_deferred_lock( $lock );
        }
    }

    /**
     * @param array $item Queue item.
     * @return array
     */
    public function process_item( $item ) {
        if ( ! is_array( $item ) || empty( $item['url'] ) || ! $this->is_public_url( $item['url'] ) ) {
            if ( is_array( $item ) ) {
                $this->record_performance_result( $item, [ 'success' => false, 'code' => 'invalid_item' ] );
            }

            return [ 'success' => false, 'code' => 'invalid_item' ];
        }

        if ( isset( $item['generation'] ) && (int) $item['generation'] !== $this->generation() ) {
            $this->release_item( $item );

            return [ 'success' => false, 'code' => 'cancelled' ];
        }

        $now    = (int) call_user_func( $this->clock );
        $health = $this->health();

        if ( $health['open_until'] > $now ) {
            if ( ! $this->schedule_retry( $item, $health['open_until'], true ) ) {
                $this->finish_retry_failure( $item, 'retry-schedule-failed' );

                return [ 'success' => false, 'code' => 'retry_schedule_failed' ];
            }

            return [ 'success' => false, 'code' => 'circuit_open' ];
        }

        $variation = isset( $item['variation'] ) && is_array( $item['variation'] ) ? $this->normalize_refresh_variation( $item['variation'] ) : [];
        $variation = false === $variation ? [] : $variation;
        $cookies   = [];

        foreach ( $variation as $name => $value ) {
            $cookies[] = new \WP_Http_Cookie( [ 'name' => $name, 'value' => $value ] );
        }

        $headers       = [
            'Accept'                   => 'text/html,application/xhtml+xml',
            'X-Directorist-Cache-Warm' => '1',
        ];
        $refresh_token = (string) call_user_func( $this->refresh_token );

        if ( '' !== $refresh_token ) {
            $refresh_time = (int) call_user_func( $this->clock );
            $refresh_key  = ( new Built_In\Request_Key() )->from_url( $item['url'], $variation );
            $refresh_url  = ! empty( $refresh_key['success'] ) ? $refresh_key['canonical_url'] : $item['url'];
            $refresh_hash = ! empty( $refresh_key['success'] ) ? $refresh_key['hash'] : '';

            if ( ! empty( $item['cache_hash'] ) && is_string( $item['cache_hash'] ) && preg_match( '/^[a-f0-9]{64}$/', $item['cache_hash'] ) && ! empty( $refresh_key['success'] ) && hash_equals( $refresh_key['hash'], $item['cache_hash'] ) ) {
                $refresh_hash = $item['cache_hash'];
            }

            $headers['X-Directorist-Cache-Refresh-Time'] = (string) $refresh_time;
            $headers['X-Directorist-Cache-Refresh-Key']  = $refresh_hash;
            $headers['X-Directorist-Cache-Refresh']      = hash_hmac( 'sha256', $refresh_time . "\n" . $refresh_url . "\n" . $refresh_hash, $refresh_token );
        }

        $response = call_user_func(
            $this->requester,
            $item['url'],
            [
                'timeout'     => 15,
                'redirection' => 3,
                'blocking'    => true,
                'cookies'     => $cookies,
                'headers'     => $headers,
                'user-agent'  => 'Directorist-Cache-Warmer/' . ( defined( 'ATBDP_VERSION' ) ? ATBDP_VERSION : 'unknown' ),
            ]
        );
        $status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

        // Cancellation can happen while the anonymous HTTP request is in
        // flight. Do not recreate claims, retries, or job results afterward.
        if ( ! $this->is_current_generation( $item ) ) {
            delete_transient( $this->dedupe_option() );

            return [ 'success' => false, 'code' => 'cancelled', 'status' => $status ];
        }

        $verification = [ 'success' => false, 'code' => 'http-status-' . $status ];

        if ( 200 === $status ) {
            try {
                $verification = call_user_func( $this->verifier, $item['url'], $response, $item );
            } catch ( \Throwable $exception ) {
                unset( $exception );
                $verification = [ 'success' => false, 'code' => 'verification-exception' ];
            }

            $verification = is_array( $verification ) ? $verification : [];
        }

        if ( 200 === $status && ! empty( $verification['success'] ) ) {
            delete_site_option( $this->health_option() );
            $this->release_item( $item );
            $result = [ 'success' => true, 'code' => isset( $verification['code'] ) ? sanitize_key( (string) $verification['code'] ) : 'warmed' ];
            do_action( 'directorist_page_cache_url_warmed', $item['url'], $result, $item );
            $this->record_performance_result( $item, $result );

            return $result;
        }

        $verification_code = ! empty( $verification['code'] ) ? sanitize_key( (string) $verification['code'] ) : '';

        if ( 200 === $status && in_array( $verification_code, [ 'uncached', 'invalid-cache-key' ], true ) ) {
            $this->release_item( $item );
            $result = [ 'success' => false, 'code' => $verification_code, 'status' => $status ];
            $this->record_performance_result( $item, $result );

            return $result;
        }

        if ( 400 <= $status && 500 > $status && ! in_array( $status, [ 408, 425, 429 ], true ) ) {
            $this->release_item( $item );
            $result = [ 'success' => false, 'code' => 'http-status-' . $status, 'status' => $status ];
            $this->record_performance_result( $item, $result );

            return $result;
        }

        ++$health['failures'];
        $attempts         = isset( $item['attempts'] ) ? (int) $item['attempts'] + 1 : 1;
        $item['attempts'] = $attempts;
        $code             = 'retry_scheduled';

        if ( self::CIRCUIT_FAILURES <= $health['failures'] ) {
            $health['open_until'] = $now + self::CIRCUIT_SECONDS;
            $code                 = 'circuit_open';

            if ( self::CIRCUIT_FAILURES === $health['failures'] && function_exists( 'directorist_page_cache_record_performance_event' ) ) {
                directorist_page_cache_record_performance_event(
                    'warning',
                    'automatic-warm-circuit-open',
                    [ 'status' => $status ]
                );
            }
        }

        update_site_option( $this->health_option(), $health );

        if ( self::MAX_ATTEMPTS > $attempts ) {
            $delay = 'circuit_open' === $code ? self::CIRCUIT_SECONDS : [ 1 => 15, 2 => 60, 3 => 180 ][ min( 3, $attempts ) ];

            if ( ! $this->schedule_retry( $item, $now + $delay, 'circuit_open' === $code ) ) {
                $code = 'retry_schedule_failed';
                $this->finish_retry_failure( $item, $code, $status, $verification );
            }
        } else {
            $code = 'retry_exhausted';
            $this->release_item( $item );
            $this->record_performance_result(
                $item,
                [
                    'success' => false,
                    'code'    => ! empty( $verification['code'] ) ? sanitize_key( (string) $verification['code'] ) : $code,
                    'status'  => $status,
                ]
            );
        }

        return [ 'success' => false, 'code' => $code, 'status' => $status ];
    }

    /** @return array */
    public function status() {
        $claims = get_transient( $this->dedupe_option() );
        $health = $this->health();
        $now    = (int) call_user_func( $this->clock );
        $state  = $this->deferred_state();

        if ( $this->uses_wordpress_scheduler && ! empty( $state['items'] ) && ! $this->next_hook_event( self::RETRY_BATCH_HOOK ) ) {
            $lock = $this->acquire_deferred_lock();

            if ( '' !== $lock ) {
                try {
                    $state                 = $this->deferred_state();
                    $state['scheduled_at'] = 0;
                    $this->save_deferred_state( $state );

                    if ( ! empty( $state['items'] ) ) {
                        $next = min( wp_list_pluck( $state['items'], 'run_at' ) );
                        $this->schedule_deferred_event( max( (int) $next, $now + 1 ), true );
                    }

                    $state = $this->deferred_state();
                } finally {
                    $this->release_deferred_lock( $lock );
                }
            }
        }

        // Claims suppress duplicate mutations but are not durable queue items;
        // completed concurrent workers can leave a claim until its short TTL.
        $queue_exists = ! $this->is_queue_empty();
        $queued       = $queue_exists ? max( 1, is_array( $claims ) ? min( self::MAX_DEDUPE_KEYS, count( $claims ) ) : 0 ) : 0;
        $queued       = max( $queued, count( $state['items'] ) );

        $recovery = array_filter(
            [
                (int) $state['scheduled_at'],
                $this->next_hook_event( self::RETRY_BATCH_HOOK ),
                $this->next_hook_event( self::RETRY_HOOK ),
                (int) wp_next_scheduled( $this->cron_hook_identifier ),
            ]
        );

        return [
            'queued'       => $queued,
            'deferred'     => count( $state['items'] ),
            'running'      => (bool) get_site_transient( $this->identifier . '_process_lock' ),
            'circuit_open' => $health['open_until'] > $now,
            'open_until'   => $health['open_until'] > $now ? $health['open_until'] : 0,
            'recovery_at'  => empty( $recovery ) ? 0 : min( $recovery ),
        ];
    }

    /** @return void */
    public function reset() {
        $this->advance_generation();
        $this->delete_all_batches();
        delete_site_transient( $this->identifier . '_process_lock' );
        delete_site_option( $this->health_option() );
        delete_transient( $this->dedupe_option() );
        delete_transient( $this->deferred_lock_option() );
        delete_option( $this->deferred_lock_option() );
        delete_option( $this->deferred_option() );
        wp_clear_scheduled_hook( $this->cron_hook_identifier );
        call_user_func( $this->unscheduler, self::RETRY_HOOK );
        call_user_func( $this->unscheduler, self::RETRY_BATCH_HOOK );
        $this->data = [];
    }

    /** @return array */
    public function cancel() {
        $this->reset();

        return [ 'success' => true, 'code' => 'cancelled' ];
    }

    /**
     * Prevent an in-flight worker from restoring the remainder of a batch
     * deleted by cancel().
     *
     * @param string $key Batch option key.
     * @param array  $data Remaining batch items.
     * @return $this
     */
    public function update( $key, $data ) {
        $data = array_filter(
            is_array( $data ) ? $data : [],
            [ $this, 'is_current_generation' ]
        );

        if ( empty( $data ) ) {
            $this->delete( $key );

            return $this;
        }

        return parent::update( $key, $data );
    }

    /**
     * @param mixed $item Queue item.
     * @return false
     */
    protected function task( $item ) {
        $this->process_item( $item );

        return false;
    }

    /**
     * @param array $item Retry item.
     * @param int   $timestamp Run time.
     * @param bool  $force Replace an earlier recovery event, such as while a circuit is open.
     * @return bool
     */
    private function schedule_retry( array $item, $timestamp, $force = false ) {
        $lock = $this->acquire_deferred_lock();

        if ( '' === $lock ) {
            return false;
        }

        try {
            $state = $this->deferred_state();
            $hash  = hash( 'sha256', $item['url'] );

            if ( ! isset( $state['items'][ $hash ] ) && self::MAX_DEFERRED_ITEMS <= count( $state['items'] ) ) {
                return false;
            }

            $state['items'][ $hash ] = [
                'item'   => $item,
                'run_at' => max( 1, (int) $timestamp ),
            ];
            $this->save_deferred_state( $state );

            if ( ! $this->schedule_deferred_event( max( 1, (int) $timestamp ), $force ) ) {
                $state = $this->deferred_state();
                unset( $state['items'][ $hash ] );
                $this->save_deferred_state( $state );

                return false;
            }

            return true;
        } finally {
            $this->release_deferred_lock( $lock );
        }
    }

    /**
     * Ensure one cron event owns retry recovery.
     *
     * @param int  $timestamp Run time.
     * @param bool $force Replace an earlier event.
     * @return bool
     */
    private function schedule_deferred_event( $timestamp, $force = false ) {
        $state     = $this->deferred_state();
        $timestamp = max( 1, (int) $timestamp );
        $current   = (int) $state['scheduled_at'];

        if ( $this->uses_wordpress_scheduler ) {
            $actual = $this->next_hook_event( self::RETRY_BATCH_HOOK );

            if ( $actual && $actual !== $current ) {
                $current               = $actual;
                $state['scheduled_at'] = $actual;
                $this->save_deferred_state( $state );
            }
        }

        if ( $current === $timestamp || ( ! $force && $current && $current <= $timestamp ) ) {
            return true;
        }

        if ( $current ) {
            call_user_func( $this->unscheduler, self::RETRY_BATCH_HOOK );
            $state['scheduled_at'] = 0;
            $this->save_deferred_state( $state );
        }

        $scheduled = (bool) call_user_func( $this->scheduler, $timestamp, self::RETRY_BATCH_HOOK, [] );

        if ( $scheduled ) {
            $state['scheduled_at'] = $timestamp;
            $this->save_deferred_state( $state );
        }

        return $scheduled;
    }

    /**
     * Schedule the next deferred chunk without creating per-item events.
     *
     * @param array $state Deferred state.
     * @param int   $now Current timestamp.
     * @return void
     */
    private function schedule_deferred_successor( array $state, $now ) {
        if ( empty( $state['items'] ) ) {
            delete_option( $this->deferred_option() );

            return;
        }

        $next = min( wp_list_pluck( $state['items'], 'run_at' ) );
        $this->schedule_deferred_event( max( (int) $next, (int) $now + 1 ), true );
    }

    /**
     * @return array{items: array, scheduled_at: int}
     */
    private function deferred_state() {
        $state = get_option( $this->deferred_option(), [] );
        $state = is_array( $state ) ? $state : [];

        return [
            'items'        => isset( $state['items'] ) && is_array( $state['items'] ) ? $state['items'] : [],
            'scheduled_at' => isset( $state['scheduled_at'] ) ? max( 0, (int) $state['scheduled_at'] ) : 0,
        ];
    }

    /** @param array $state Deferred state. */
    private function save_deferred_state( array $state ) {
        if ( empty( $state['items'] ) && empty( $state['scheduled_at'] ) ) {
            delete_option( $this->deferred_option() );

            return;
        }

        update_option( $this->deferred_option(), $state, false );
    }

    /** @return int */
    private function deferred_count() {
        return count( $this->deferred_state()['items'] );
    }

    /** @return string */
    private function deferred_option() {
        return $this->identifier . '_deferred';
    }

    /** @return string */
    private function deferred_lock_option() {
        return $this->identifier . '_deferred_lock';
    }

    /**
     * Return the next event for a hook regardless of its argument signature.
     *
     * @param string $hook Cron hook.
     * @return int
     */
    private function next_hook_event( $hook ) {
        foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
            if ( isset( $hooks[ $hook ] ) ) {
                return max( 0, (int) $timestamp );
            }
        }

        return 0;
    }

    /** @return bool */
    private function persist_queue() {
        if ( empty( $this->data ) ) {
            return true;
        }

        if ( $this->queue_persister ) {
            $stored     = (bool) call_user_func( $this->queue_persister, $this->data, $this );
            $this->data = [];

            return $stored;
        }

        $key    = $this->generate_key();
        $stored = update_site_option( $key, $this->data );

        if ( ! $stored && $this->data !== get_site_option( $key, [] ) ) {
            $this->data = [];

            return false;
        }

        $this->data = [];

        return true;
    }

    /** @return string */
    private function acquire_deferred_lock() {
        if ( get_transient( $this->deferred_lock_option() ) ) {
            return '';
        }

        $token    = wp_generate_uuid4();
        $deadline = microtime( true ) + 1;

        do {
            $existing = get_option( $this->deferred_lock_option(), [] );

            if ( is_array( $existing ) && ! empty( $existing['acquired_at'] ) && (int) $existing['acquired_at'] < time() - self::DEFERRED_LOCK_TTL ) {
                delete_option( $this->deferred_lock_option() );
            }

            if ( add_option( $this->deferred_lock_option(), [ 'token' => $token, 'acquired_at' => time() ], '', false ) ) {
                return $token;
            }

            usleep( 20000 );
        } while ( microtime( true ) < $deadline );

        return '';
    }

    /** @return void */
    private function release_deferred_lock( $token ) {
        $lock = get_option( $this->deferred_lock_option(), [] );

        if ( is_array( $lock ) && ! empty( $lock['token'] ) && is_string( $token ) && hash_equals( (string) $lock['token'], $token ) ) {
            delete_option( $this->deferred_lock_option() );
        }
    }

    /**
     * Release a retry that cannot be retained and close its performance result.
     *
     * @param array  $item Queue item.
     * @param string $code Failure code.
     * @param int    $status HTTP status.
     * @param array  $verification Verification result.
     * @return void
     */
    private function finish_retry_failure( array $item, $code, $status = 0, array $verification = [] ) {
        $this->release_item( $item );
        $this->record_performance_result(
            $item,
            [
                'success' => false,
                'code'    => ! empty( $verification['code'] ) ? sanitize_key( (string) $verification['code'] ) : sanitize_key( (string) $code ),
                'status'  => max( 0, (int) $status ),
            ]
        );
    }

    /** @return array */
    private function health() {
        $health = get_site_option( $this->health_option(), [] );

        return [
            'failures'   => isset( $health['failures'] ) ? max( 0, (int) $health['failures'] ) : 0,
            'open_until' => isset( $health['open_until'] ) ? max( 0, (int) $health['open_until'] ) : 0,
        ];
    }

    /** @return string */
    private function health_option() {
        return $this->identifier . '_health';
    }

    /** @return int */
    private function generation() {
        return max( 0, (int) get_site_option( $this->identifier . '_generation', 0 ) );
    }

    private function is_current_generation( $item ) {
        return is_array( $item ) && ( ! isset( $item['generation'] ) || (int) $item['generation'] === $this->generation() );
    }

    /** @return void */
    private function advance_generation() {
        update_site_option( $this->identifier . '_generation', $this->generation() + 1 );
    }

    /** @return string */
    private function dedupe_option() {
        return $this->identifier . '_dedupe';
    }

    /**
     * Coalesce ordinary mutation bursts without blocking scheduled retries.
     *
     * @param string[] $urls Normalized same-origin URLs.
     * @return string[]
     */
    private function claim_urls( array $urls ) {
        $now      = (int) call_user_func( $this->clock );
        $claims   = get_transient( $this->dedupe_option() );
        $claims   = is_array( $claims ) ? $claims : [];
        $accepted = [];

        foreach ( $claims as $hash => $expires ) {
            if ( ! is_numeric( $expires ) || (int) $expires <= $now ) {
                unset( $claims[ $hash ] );
            }
        }

        foreach ( $urls as $url ) {
            $hash = hash( 'sha256', $url );

            if ( isset( $claims[ $hash ] ) ) {
                continue;
            }

            $claims[ $hash ] = $now + self::DEDUPE_SECONDS;
            $accepted[]      = $url;
        }

        if ( self::MAX_DEDUPE_KEYS < count( $claims ) ) {
            asort( $claims, SORT_NUMERIC );
            $claims = array_slice( $claims, -self::MAX_DEDUPE_KEYS, null, true );
        }

        set_transient( $this->dedupe_option(), $claims, self::DEDUPE_SECONDS );

        return $accepted;
    }

    private function claim_hash( $hash ) {
        if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
            return false;
        }

        $now    = (int) call_user_func( $this->clock );
        $claims = get_transient( $this->dedupe_option() );
        $claims = is_array( $claims ) ? $claims : [];

        foreach ( $claims as $candidate => $expires ) {
            if ( ! is_numeric( $expires ) || (int) $expires <= $now ) {
                unset( $claims[ $candidate ] );
            }
        }

        if ( isset( $claims[ $hash ] ) ) {
            return false;
        }

        $claims[ $hash ] = $now + self::DEDUPE_SECONDS;

        if ( self::MAX_DEDUPE_KEYS < count( $claims ) ) {
            asort( $claims, SORT_NUMERIC );
            $claims = array_slice( $claims, -self::MAX_DEDUPE_KEYS, null, true );
        }

        set_transient( $this->dedupe_option(), $claims, self::DEDUPE_SECONDS );

        return true;
    }

    private function normalize_refresh_variation( array $variation ) {
        if ( 8 < count( $variation ) ) {
            return false;
        }

        $normalized = [];

        foreach ( $variation as $name => $value ) {
            if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $name ) || ! is_scalar( $value ) ) {
                return false;
            }

            $value = (string) $value;

            if ( 64 < strlen( $value ) || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
                return false;
            }

            $normalized[ $name ] = $value;
        }

        ksort( $normalized, SORT_STRING );

        return $normalized;
    }

    /**
     * Release a completed URL while retaining claims for queued or failed work.
     *
     * @param string $url Successfully warmed URL.
     * @return void
     */
    private function release_url( $url ) {
        $this->release_hash( hash( 'sha256', $url ) );
    }

    private function release_item( array $item ) {
        $hash = isset( $item['cache_hash'] ) && is_string( $item['cache_hash'] ) && preg_match( '/^[a-f0-9]{64}$/', $item['cache_hash'] )
            ? $item['cache_hash']
            : hash( 'sha256', isset( $item['url'] ) ? (string) $item['url'] : '' );

        $this->release_hash( $hash );
    }

    private function release_hash( $hash ) {
        $claims = get_transient( $this->dedupe_option() );

        if ( ! is_array( $claims ) ) {
            return;
        }

        unset( $claims[ $hash ] );

        if ( empty( $claims ) ) {
            delete_transient( $this->dedupe_option() );

            return;
        }

        set_transient( $this->dedupe_option(), $claims, self::DEDUPE_SECONDS );
    }

    private function record_performance_result( array $item, array $result ) {
        $url = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '';
        do_action( 'directorist_page_cache_warm_result', $url, $result, $item );

        if ( empty( $item['performance_job_id'] ) ) {
            return;
        }

        $performance_result = $result;

        if ( ! empty( $item['performance_resource_title'] ) ) {
            $performance_result['resource_title'] = substr( sanitize_text_field( (string) $item['performance_resource_title'] ), 0, 160 );
        }

        if ( ! empty( $item['performance_resource_type'] ) ) {
            $performance_result['resource_type'] = substr( sanitize_key( (string) $item['performance_resource_type'] ), 0, 32 );
        }

        do_action(
            'directorist_page_cache_performance_warm_result',
            sanitize_text_field( (string) $item['performance_job_id'] ),
            $url,
            $performance_result
        );
    }

    /**
     * @param string $url Candidate URL.
     * @return bool
     */
    private function is_public_url( $url ) {
        $registry = new Warm_URL_Registry( home_url( '/' ), 1 );
        $registry->add( $url, 'worker' );
        $urls = $registry->all();

        if ( empty( $urls ) ) {
            return false;
        }

        $parts = wp_parse_url( $urls[0] );
        $path  = isset( $parts['path'] ) ? strtolower( $parts['path'] ) : '/';

        foreach ( [ '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php' ] as $private_path ) {
            if ( $private_path === $path || 0 === strpos( $path, $private_path . '/' ) ) {
                return false;
            }
        }

        $query = [];
        parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );

        foreach ( array_keys( $query ) as $name ) {
            $name = strtolower( (string) $name );

            if ( false !== strpos( $name, 'nonce' ) || in_array( $name, [ 'security', 'preview', 'rest_route' ], true ) ) {
                return false;
            }
        }

        return true;
    }
}
