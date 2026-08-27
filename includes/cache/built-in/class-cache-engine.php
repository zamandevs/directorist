<?php

namespace Directorist\Cache\Built_In {
    require_once __DIR__ . '/class-request-key.php';
    require_once __DIR__ . '/class-cookie-policy.php';
    require_once __DIR__ . '/class-request-guard.php';
    require_once __DIR__ . '/class-cache-paths.php';
    require_once __DIR__ . '/class-atomic-file-writer.php';
    require_once __DIR__ . '/class-generation-store.php';
    require_once __DIR__ . '/class-cache-storage.php';
    require_once __DIR__ . '/class-response-validator.php';
    require_once __DIR__ . '/class-refresh-policy.php';
    require_once __DIR__ . '/class-early-refresh-dispatcher.php';

    /**
     * Coordinates fail-open early hits and core-approved response storage.
     */
    final class Cache_Engine {
        /** @var array */
        private $config;

        /** @var Cache_Storage */
        private $storage;

        /** @var Request_Guard */
        private $guard;

        /** @var Request_Key */
        private $request_key;

        /** @var Response_Validator */
        private $validator;

        /** @var Refresh_Policy */
        private $refresh_policy;

        /** @var callable */
        private $refresh_dispatcher;

        /** @var callable */
        private $core_begin;

        /** @var callable */
        private $core_finish;

        /** @var array */
        private $current_key = [];

        /** @var string */
        private $method = '';

        /** @var bool */
        private $regeneration = false;

        /** @var bool */
        private $capture_started = false;

        /** @var bool */
        private $buffer_overflow = false;

        /** @var string */
        private $captured_body = '';

        /** @var bool */
        private $hooks_registered = false;

        /** @var array|null */
        private $early_result;

        /** @var callable|null */
        private $warm_handler;

        /**
         * @param array $config Early engine configuration.
         * @param array $options Testable runtime boundaries.
         */
        public function __construct( array $config, array $options = [] ) {
            $this->config             = $this->normalize_config( $config );
            $this->request_key        = new Request_Key();
            $clock                    = isset( $options['clock'] ) && is_callable( $options['clock'] ) ? $options['clock'] : null;
            $this->guard              = new Request_Guard( $this->request_key, $this->config['cookie_policy'], $this->config['cache_filtered_results'], $this->config['refresh_token'], $clock );
            $this->storage            = new Cache_Storage( $this->config['cache_dir'], $clock );
            $this->validator          = new Response_Validator();
            $this->refresh_policy     = new Refresh_Policy();
            $this->refresh_dispatcher = isset( $options['refresh_dispatcher'] ) && is_callable( $options['refresh_dispatcher'] )
                ? $options['refresh_dispatcher']
                : [ new Early_Refresh_Dispatcher(), 'dispatch' ];
            $this->core_begin         = isset( $options['core_begin'] ) && is_callable( $options['core_begin'] )
                ? $options['core_begin']
                : static function () {
                    if ( ! function_exists( 'directorist_page_cache_begin_response_capture' ) ) {
                        return [ 'eligible' => false, 'reason' => 'core_unavailable' ];
                    }

                    return directorist_page_cache_begin_response_capture();
                };
            $this->core_finish        = isset( $options['core_finish'] ) && is_callable( $options['core_finish'] )
                ? $options['core_finish']
                : static function () {
                    if ( ! function_exists( 'directorist_page_cache_finish_response_capture' ) ) {
                        return [ 'eligible' => false, 'reason' => 'core_unavailable', 'dependencies' => [] ];
                    }

                    return directorist_page_cache_finish_response_capture();
                };
        }

        /** @return bool */
        public function is_available() {
            return $this->storage->is_available();
        }

        /**
         * Resolve one anonymous request before WordPress loads.
         *
         * @param array $server HTTP server values.
         * @param array $cookies Parsed cookies.
         * @return array
         */
        public function boot_early( array $server, array $cookies = [] ) {
            if ( null !== $this->early_result ) {
                return $this->early_result;
            }

            $decision = $this->guard->evaluate( $server, $cookies );

            if ( empty( $decision['eligible'] ) ) {
                $this->early_result = $this->early_result( false, false, $decision['code'] );

                return $this->early_result;
            }

            $this->current_key = $decision['request'];
            $this->method      = $decision['method'];
            $cached            = $this->storage->load( $this->current_key );
            $force_refresh     = ! empty( $decision['force_refresh'] ) && 'GET' === $this->method;

            if ( ! empty( $cached['hit'] ) && ! array_key_exists( 'directorist:0:lifecycle', $cached['metadata']['generations'] ) ) {
                $cached = [ 'hit' => false, 'code' => 'legacy_entry' ];
            }

            if ( ! empty( $cached['hit'] ) && $force_refresh ) {
                $lock = $this->storage->begin_regeneration( $this->current_key );

                if ( ! empty( $lock['success'] ) ) {
                    $this->regeneration = true;
                    $this->early_result = $this->early_result( false, true, 'refresh_forced' );

                    return $this->early_result;
                }

                $cached['code']     = 'refresh_in_progress';
                $this->early_result = $this->cached_response( $cached, $server );

                return $this->early_result;
            }

            if ( ! empty( $cached['hit'] ) && ! empty( $cached['stale'] ) ) {
                $refresh = $this->dispatch_stale_refresh( $cached, empty( $decision['read_only'] ) );

                if ( 'refresh_queued' === $refresh ) {
                    $cached['code'] = 'refresh_queued';
                } elseif ( 'refresh_sync' === $refresh ) {
                    $this->regeneration = true;
                    $this->early_result = $this->early_result( false, true, 'refresh_sync' );

                    return $this->early_result;
                }
            }

            if ( ! empty( $cached['hit'] ) ) {
                $this->early_result = $this->cached_response( $cached, $server );

                return $this->early_result;
            }

            if ( ! empty( $decision['read_only'] ) ) {
                $this->early_result = $this->early_result( false, false, 'read_only_miss' );

                return $this->early_result;
            }

            if ( 'HEAD' === $this->method ) {
                $this->early_result = $this->early_result( false, false, 'head_miss' );

                return $this->early_result;
            }

            $lock = $this->storage->begin_regeneration( $this->current_key );

            if ( empty( $lock['success'] ) ) {
                $this->early_result = $this->early_result( false, false, $lock['code'] );

                return $this->early_result;
            }

            $this->regeneration = true;
            $this->early_result = $this->early_result( false, true, $force_refresh ? 'refresh_forced' : $cached['code'] );

            return $this->early_result;
        }

        /**
         * Begin core route/dependency collection before template rendering.
         *
         * @return array
         */
        public function begin_capture() {
            if ( ! $this->regeneration || 'GET' !== $this->method ) {
                return [ 'eligible' => false, 'reason' => 'request_not_prepared' ];
            }

            try {
                $result = call_user_func( $this->core_begin );
            } catch ( \Throwable $exception ) {
                unset( $exception );
                $result = [ 'eligible' => false, 'reason' => 'core_exception' ];
            }

            if ( ! is_array( $result ) || empty( $result['eligible'] ) ) {
                $this->release_request();

                return is_array( $result ) ? $result : [ 'eligible' => false, 'reason' => 'invalid_core_result' ];
            }

            $this->capture_started = true;

            return $result;
        }

        /**
         * Validate and store one complete rendered response.
         *
         * @param string $body Complete body.
         * @param int    $status HTTP status.
         * @param array  $headers Header lines.
         * @return array
         */
        public function finalize_capture( $body, $status, array $headers ) {
            if ( ! $this->capture_started ) {
                $this->release_request();

                return $this->operation_result( false, 'capture_not_started' );
            }

            try {
                $descriptor = call_user_func( $this->core_finish );
            } catch ( \Throwable $exception ) {
                unset( $exception );
                $descriptor = [ 'eligible' => false, 'reason' => 'core_exception', 'dependencies' => [] ];
            }

            $descriptor     = is_array( $descriptor ) ? $descriptor : [];
            $dependencies   = isset( $descriptor['dependencies'] ) && is_array( $descriptor['dependencies'] ) ? $descriptor['dependencies'] : [];
            $dependencies[] = 'directorist:0:lifecycle';
            $url_generation = $this->url_generation_key(
                isset( $descriptor['site_id'] ) ? $descriptor['site_id'] : 1,
                isset( $this->current_key['canonical_url'] ) ? $this->current_key['canonical_url'] : ''
            );

            if ( '' !== $url_generation ) {
                $dependencies[] = $url_generation;
            }

            $descriptor['dependencies']     = array_values( array_unique( $dependencies ) );
            $descriptor['refresh_endpoint'] = function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '';
            $validated                      = $this->validator->validate( $body, $status, $headers, $descriptor );

            if ( empty( $validated['accepted'] ) ) {
                $this->release_request();

                return $this->operation_result( false, $validated['code'] );
            }

            $lifetime = $this->refresh_policy->resolve(
                $this->config,
                $descriptor,
                isset( $this->current_key['hash'] ) ? $this->current_key['hash'] : ''
            );
            $result   = $this->storage->store(
                $this->current_key,
                $body,
                $descriptor,
                $validated['headers'],
                $lifetime['soft_ttl'],
                $lifetime['stale_ttl']
            );
            $this->release_request();

            return $result;
        }

        /**
         * Register late capture only for a prepared cold request.
         *
         * @return bool
         */
        public function register_wordpress_hooks() {
            if ( $this->hooks_registered || ! $this->regeneration || ! function_exists( 'add_action' ) ) {
                return false;
            }

            add_action( 'template_redirect', [ $this, 'begin_wordpress_capture' ], -1000 );
            $this->hooks_registered = true;

            return true;
        }

        /** @return void */
        public function begin_wordpress_capture() {
            if ( headers_sent() ) {
                $this->release_request();

                return;
            }

            $result = $this->begin_capture();

            if ( empty( $result['eligible'] ) ) {
                return;
            }

            $started = ob_start( [ $this, 'capture_output' ] );

            if ( ! $started ) {
                $this->release_request();
            }
        }

        /**
         * Observe output without changing what WordPress sends.
         *
         * @param string $buffer Output chunk.
         * @param int    $phase Output-handler phase.
         * @return string
         */
        public function capture_output( $buffer, $phase ) {
            if ( ! $this->buffer_overflow ) {
                if ( Cache_Storage::MAX_BODY_BYTES < strlen( $this->captured_body ) + strlen( $buffer ) ) {
                    $this->captured_body   = '';
                    $this->buffer_overflow = true;
                } else {
                    $this->captured_body .= $buffer;
                }
            }

            if ( $phase & PHP_OUTPUT_HANDLER_FINAL ) {
                $body = $this->buffer_overflow ? '' : $this->captured_body;
                $this->finalize_capture( $body, http_response_code(), headers_list() );
            }

            return $buffer;
        }

        /**
         * Send a previously prepared hit response.
         *
         * @param array $response Hit response.
         * @return bool
         */
        public function send_response( array $response ) {
            if ( empty( $response['served'] ) ) {
                return false;
            }

            http_response_code( $response['status'] );

            foreach ( $response['headers'] as $name => $value ) {
                header( $name . ': ' . $value, true );
            }

            if ( '' !== $response['body'] ) {
                echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integrity-checked cached response.
            }

            return true;
        }

        /**
         * Apply exact and generation-based invalidation.
         *
         * @param array $plan Normalized Directorist invalidation plan.
         * @return array
         */
        public function invalidate( array $plan ) {
            $site_id      = isset( $plan['site_id'] ) ? max( 1, (int) $plan['site_id'] ) : 1;
            $dependencies = $this->string_list( isset( $plan['dependencies'] ) ? $plan['dependencies'] : [] );
            $generations  = $this->string_list( isset( $plan['generations'] ) ? $plan['generations'] : [] );
            $url_keys     = [];
            $valid_urls   = true;

            foreach ( $this->string_list( isset( $plan['urls'] ) ? $plan['urls'] : [] ) as $url ) {
                $key = $this->request_key->from_url( $url );

                if ( empty( $key['success'] ) ) {
                    $valid_urls = false;
                    continue;
                }

                $url_keys[]    = $key;
                $generations[] = $this->url_generation_key( $site_id, $key['canonical_url'] );
            }

            if ( ! empty( $plan['conservative'] ) ) {
                $generations[] = 'directorist:' . $site_id . ':site';
            }

            $generation_keys = array_values( array_unique( array_filter( array_merge( $dependencies, $generations ) ) ) );
            $generation      = $this->storage->bump_generations( $generation_keys );
            $purged          = 0;

            foreach ( $url_keys as $key ) {
                $result = $this->storage->purge( $key );

                if ( empty( $result['success'] ) ) {
                    $valid_urls = false;
                } else {
                    ++$purged;
                }
            }

            $purged_entries = 0;
            $valid_entries  = true;

            foreach ( $this->string_list( isset( $plan['entry_hashes'] ) ? $plan['entry_hashes'] : [] ) as $hash ) {
                $result = $this->storage->purge_hash( $hash );

                if ( empty( $result['success'] ) ) {
                    $valid_entries = false;
                } else {
                    ++$purged_entries;
                }
            }

            $success = ! empty( $generation['success'] ) && $valid_urls && $valid_entries;

            return [
                'success'            => $success,
                'code'               => $success ? 'invalidated' : 'invalidation_failed',
                'provider'           => 'directorist-cache',
                'purged_urls'        => $purged,
                'purged_entries'     => $purged_entries,
                'bumped_generations' => count( $generation_keys ),
            ];
        }

        /**
         * Attach the late WordPress queue boundary after the plugin loads.
         *
         * @param callable $handler Warm queue handler.
         * @return bool
         */
        public function set_warm_handler( $handler ) {
            if ( ! is_callable( $handler ) ) {
                return false;
            }

            $this->warm_handler = $handler;

            return true;
        }

        /** @return bool */
        public function supports_warm() {
            return is_callable( $this->warm_handler );
        }

        /**
         * @param string[] $urls Public URLs.
         * @return array
         */
        public function warm( array $urls ) {
            if ( ! $this->supports_warm() ) {
                return $this->operation_result( false, 'warming_unavailable' );
            }

            try {
                $result = call_user_func( $this->warm_handler, $urls );
            } catch ( \Throwable $exception ) {
                unset( $exception );

                return $this->operation_result( false, 'warming_exception' );
            }

            return is_array( $result ) ? $result : $this->operation_result( false, 'invalid_warming_result' );
        }

        /** @return array */
        public function get_status() {
            return [
                'available'  => $this->is_available(),
                'prepared'   => $this->regeneration,
                'capturing'  => $this->capture_started,
                'warm_ready' => $this->supports_warm(),
            ];
        }

        /** @return void */
        public function release_request() {
            $this->storage->release_regeneration();
            $this->regeneration    = false;
            $this->capture_started = false;
        }

        /**
         * @param array $cached Cache storage hit.
         * @param array $server HTTP server values.
         * @return array
         */
        private function cached_response( array $cached, array $server ) {
            $metadata = $cached['metadata'];
            $headers  = [];

            foreach ( $metadata['headers'] as $name => $value ) {
                $headers[ implode( '-', array_map( 'ucfirst', explode( '-', $name ) ) ) ] = $value;
            }

            $headers['Last-Modified'] = gmdate( 'D, d M Y H:i:s', $metadata['created_at'] ) . ' GMT';
            $headers['Cache-Control'] = 'no-cache, must-revalidate';
            $status                   = $this->not_modified( $server, $metadata['created_at'] ) ? 304 : 200;
            $body                     = 304 === $status || 'HEAD' === $this->method ? '' : $cached['body'];

            if ( 304 !== $status ) {
                $headers['Content-Length'] = (string) $metadata['body_size'];
            }

            if ( $this->config['debug'] ) {
                $headers['X-Directorist-Cache'] = ! empty( $cached['stale'] ) ? 'STALE' : 'HIT';
            }

            return [
                'served'       => true,
                'regeneration' => false,
                'code'         => $cached['code'],
                'status'       => $status,
                'headers'      => $headers,
                'body'         => $body,
                'stale'        => ! empty( $cached['stale'] ),
            ];
        }

        /**
         * @param array $server HTTP server values.
         * @param int   $created_at Cache creation timestamp.
         * @return bool
         */
        private function not_modified( array $server, $created_at ) {
            if ( empty( $server['HTTP_IF_MODIFIED_SINCE'] ) || ! is_scalar( $server['HTTP_IF_MODIFIED_SINCE'] ) ) {
                return false;
            }

            $timestamp = strtotime( (string) $server['HTTP_IF_MODIFIED_SINCE'] );

            return false !== $timestamp && $timestamp >= $created_at;
        }

        /**
         * @param bool   $served Hit state.
         * @param bool   $regeneration Lock ownership state.
         * @param string $code Stable result code.
         * @return array
         */
        private function early_result( $served, $regeneration, $code ) {
            return [
                'served'       => (bool) $served,
                'regeneration' => (bool) $regeneration,
                'code'         => (string) $code,
                'status'       => 0,
                'headers'      => [],
                'body'         => '',
                'stale'        => false,
            ];
        }

        /**
         * @param array $config Raw configuration.
         * @return array
         */
        private function normalize_config( array $config ) {
            $cache_dir = isset( $config['cache_dir'] ) && is_string( $config['cache_dir'] ) ? $config['cache_dir'] : '';
            $ttl       = isset( $config['ttl'] ) ? (int) $config['ttl'] : 3600;
            $stale_ttl = isset( $config['stale_ttl'] ) ? (int) $config['stale_ttl'] : 30;

            return [
                'cache_dir'              => $cache_dir,
                'ttl'                    => min( 86400, max( 1, $ttl ) ),
                'stale_ttl'              => min( 300, max( 0, $stale_ttl ) ),
                'refresh_policy'         => isset( $config['refresh_policy'] ) && is_array( $config['refresh_policy'] ) ? $config['refresh_policy'] : [],
                'refresh_endpoint'       => isset( $config['refresh_endpoint'] ) && is_string( $config['refresh_endpoint'] ) ? $config['refresh_endpoint'] : '',
                'refresh_token'          => isset( $config['refresh_token'] ) && is_string( $config['refresh_token'] ) ? $config['refresh_token'] : '',
                'debug'                  => ! empty( $config['debug'] ),
                'cache_filtered_results' => ! array_key_exists( 'cache_filtered_results', $config ) || ! empty( $config['cache_filtered_results'] ),
                'cookie_policy'          => isset( $config['cookie_policy'] ) && is_array( $config['cookie_policy'] ) ? $config['cookie_policy'] : [],
            ];
        }

        /**
         * @param mixed $values Candidate string list.
         * @return string[]
         */
        private function string_list( $values ) {
            if ( ! is_array( $values ) ) {
                return [];
            }

            return array_values( array_unique( array_filter( array_map( 'strval', $values ) ) ) );
        }

        private function dispatch_stale_refresh( array $cached, $allow_sync = true ) {
            if ( '' === $this->config['refresh_endpoint'] || 8 > strlen( $this->config['refresh_token'] ) || ! is_callable( $this->refresh_dispatcher ) ) {
                if ( ! $allow_sync ) {
                    return 'refresh_due';
                }

                $lock = $this->storage->begin_regeneration( $this->current_key );

                return ! empty( $lock['success'] ) ? 'refresh_sync' : 'refresh_due';
            }

            $claim = $this->storage->claim_refresh( $this->current_key, 300 );

            if ( empty( $claim['success'] ) ) {
                if ( 'refresh_claimed' === ( isset( $claim['code'] ) ? $claim['code'] : '' ) ) {
                    return 'refresh_due';
                }

                if ( ! $allow_sync ) {
                    return 'refresh_due';
                }

                $lock = $this->storage->begin_regeneration( $this->current_key );

                return ! empty( $lock['success'] ) ? 'refresh_sync' : 'refresh_due';
            }

            try {
                $scheduled = (bool) call_user_func(
                    $this->refresh_dispatcher,
                    [
                        'endpoint'  => ! empty( $cached['metadata']['refresh_endpoint'] ) ? $cached['metadata']['refresh_endpoint'] : $this->config['refresh_endpoint'],
                        'token'     => $this->config['refresh_token'],
                        'url'       => $this->current_key['canonical_url'],
                        'hash'      => $this->current_key['hash'],
                        'variation' => isset( $this->current_key['variation'] ) ? $this->current_key['variation'] : [],
                        'metadata'  => isset( $cached['metadata'] ) ? $cached['metadata'] : [],
                    ]
                );
            } catch ( \Throwable $exception ) {
                unset( $exception );
                $scheduled = false;
            }

            if ( $scheduled ) {
                return 'refresh_queued';
            }

            $this->storage->release_refresh_claim( $this->current_key );

            if ( ! $allow_sync ) {
                return 'refresh_due';
            }

            $lock = $this->storage->begin_regeneration( $this->current_key );

            return ! empty( $lock['success'] ) ? 'refresh_sync' : 'refresh_due';
        }

        /**
         * @param int    $site_id Site ID.
         * @param string $canonical_url Canonical public URL.
         * @return string
         */
        private function url_generation_key( $site_id, $canonical_url ) {
            if ( ! is_string( $canonical_url ) || '' === $canonical_url ) {
                return '';
            }

            return 'directorist:' . max( 1, (int) $site_id ) . ':url:' . hash( 'sha256', $canonical_url );
        }

        /**
         * @param bool   $success Operation state.
         * @param string $code Stable result code.
         * @return array
         */
        private function operation_result( $success, $code ) {
            return [
                'success'  => (bool) $success,
                'code'     => (string) $code,
                'provider' => 'directorist-cache',
            ];
        }
    }
}

namespace {
    if ( ! function_exists( 'directorist_page_cache_builtin_engine' ) ) {
        /**
         * Return the request-scoped early cache engine.
         *
         * @param array|null $config Early engine configuration.
         * @return Directorist\Cache\Built_In\Cache_Engine
         */
        function directorist_page_cache_builtin_engine( array $config = null ) {
            static $engine;

            if ( ! $engine instanceof Directorist\Cache\Built_In\Cache_Engine ) {
                $engine = new Directorist\Cache\Built_In\Cache_Engine( null === $config ? [] : $config );
            }

            return $engine;
        }
    }
}
