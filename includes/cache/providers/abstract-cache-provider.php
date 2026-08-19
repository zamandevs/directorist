<?php

namespace Directorist\Cache;

/**
 * Shared capability-aware invalidation translation for external providers.
 */
abstract class Abstract_Cache_Provider implements Cache_Provider {
    /** @var string */
    protected $id;

    /** @var string */
    protected $version;

    /** @var Provider_Capabilities */
    protected $capabilities;

    /** @var array<string,callable> */
    protected $operations = [];

    /** @var bool */
    protected $available = false;

    /**
     * @param string $id Provider ID.
     * @param string $version Provider version.
     * @param array  $operations Callable operations.
     * @param bool   $available Availability state.
     */
    protected function configure( $id, $version, array $operations, $available ) {
        $this->id         = $id;
        $this->version    = (string) $version;
        $this->operations = array_filter( $operations, 'is_callable' );
        $this->available  = (bool) $available;

        $capabilities = [];

        if ( isset( $this->operations['delete_url'] ) ) {
            $capabilities[] = Provider_Capabilities::PURGE_URL;
            $capabilities[] = Provider_Capabilities::PURGE_URLS;
        }

        if ( isset( $this->operations['delete_urls'] ) ) {
            $capabilities[] = Provider_Capabilities::PURGE_URLS;
        }

        if ( isset( $this->operations['purge_site'] ) ) {
            $capabilities[] = Provider_Capabilities::PURGE_SITE;
        }

        if ( isset( $this->operations['purge_dependencies'] ) ) {
            $capabilities[] = Provider_Capabilities::PURGE_DEPENDENCIES;
        }

        if ( isset( $this->operations['purge_generations'] ) ) {
            $capabilities[] = Provider_Capabilities::PURGE_GENERATIONS;
        }

        if ( isset( $this->operations['warm_urls'] ) ) {
            $capabilities[] = Provider_Capabilities::WARM_URLS;
        }

        $this->capabilities = new Provider_Capabilities( $capabilities );
    }

    /** @return string */
    public function get_id() {
        return $this->id;
    }

    /** @return bool */
    public function is_available() {
        return $this->available && ! empty( $this->capabilities->all() );
    }

    /** @return string[] */
    public function get_capabilities() {
        return $this->capabilities->all();
    }

    /**
     * @param string $capability Capability identifier.
     * @return bool
     */
    public function supports( $capability ) {
        return $this->capabilities->supports( $capability );
    }

    /**
     * @param array $request Normalized invalidation plan.
     * @return array
     */
    public function invalidate( array $request ) {
        if ( ! $this->is_available() ) {
            return $this->result( false, 'provider_unavailable' );
        }

        $plan = $this->normalize_plan( $request );

        if ( empty( $plan['urls'] ) && empty( $plan['dependencies'] ) && empty( $plan['generations'] ) && ! $plan['conservative'] ) {
            return $this->result( true, 'no_changes' );
        }

        if ( $plan['conservative'] || $this->must_purge_site( $plan ) ) {
            return $this->purge_site( $plan['site_id'] );
        }

        if ( ! empty( $plan['generations'] ) ) {
            if ( ! $this->supports( Provider_Capabilities::PURGE_GENERATIONS ) ) {
                return $this->purge_site( $plan['site_id'] );
            }

            $result = $this->invoke( 'purge_generations', [ $plan['generations'], $plan['site_id'] ], 'purged_generations' );

            if ( empty( $result['success'] ) ) {
                return $result;
            }
        }

        if ( ! empty( $plan['dependencies'] ) && empty( $plan['urls'] ) ) {
            if ( ! $this->supports( Provider_Capabilities::PURGE_DEPENDENCIES ) ) {
                return $this->purge_site( $plan['site_id'] );
            }

            return $this->invoke( 'purge_dependencies', [ $plan['dependencies'], $plan['site_id'] ], 'purged_dependencies' );
        }

        if ( ! empty( $plan['dependencies'] ) && $this->supports( Provider_Capabilities::PURGE_DEPENDENCIES ) ) {
            $result = $this->invoke( 'purge_dependencies', [ $plan['dependencies'], $plan['site_id'] ], 'purged_dependencies' );

            if ( empty( $result['success'] ) ) {
                return $result;
            }
        }

        if ( ! empty( $plan['urls'] ) ) {
            return $this->purge_urls( $plan['urls'], $plan['site_id'] );
        }

        return $this->result( true, 'invalidated' );
    }

    /**
     * @param string[] $urls Public URLs.
     * @return array
     */
    public function warm( array $urls ) {
        if ( ! $this->is_available() ) {
            return $this->result( false, 'provider_unavailable' );
        }

        if ( ! $this->supports( Provider_Capabilities::WARM_URLS ) ) {
            return $this->result( false, 'unsupported_warm' );
        }

        $urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );

        if ( empty( $urls ) ) {
            return $this->result( true, 'no_urls' );
        }

        return $this->invoke( 'warm_urls', [ $urls ], 'warmed_urls' );
    }

    /** @return array */
    public function get_status() {
        return [
            'id'           => $this->id,
            'version'      => $this->version,
            'available'    => $this->is_available(),
            'capabilities' => $this->get_capabilities(),
        ];
    }

    /**
     * @param array $plan Normalized plan.
     * @return bool
     */
    protected function must_purge_site( array $plan ) {
        unset( $plan );

        return false;
    }

    /**
     * @param string[] $urls URLs to purge.
     * @param int      $site_id Site ID.
     * @return array
     */
    private function purge_urls( array $urls, $site_id ) {
        if ( isset( $this->operations['delete_urls'] ) ) {
            return $this->invoke( 'delete_urls', [ $urls ], 'purged_urls' );
        }

        if ( isset( $this->operations['delete_url'] ) ) {
            foreach ( $urls as $url ) {
                $result = $this->invoke( 'delete_url', [ $url ], 'purged_urls' );

                if ( empty( $result['success'] ) ) {
                    return $result;
                }
            }

            return $this->result( true, 'purged_urls', [ 'count' => count( $urls ) ] );
        }

        return $this->purge_site( $site_id );
    }

    /**
     * @param int $site_id Site ID.
     * @return array
     */
    private function purge_site( $site_id ) {
        if ( ! isset( $this->operations['purge_site'] ) ) {
            return $this->result( false, 'unsupported_invalidation' );
        }

        return $this->invoke( 'purge_site', [ $site_id ], 'purged_site' );
    }

    /**
     * @param string $operation Operation key.
     * @param array  $arguments Callable arguments.
     * @param string $success_code Success result code.
     * @return array
     */
    private function invoke( $operation, array $arguments, $success_code ) {
        if ( ! isset( $this->operations[ $operation ] ) ) {
            return $this->result( false, 'unsupported_invalidation' );
        }

        try {
            $operation_result = call_user_func_array( $this->operations[ $operation ], $arguments );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->result( false, 'provider_exception', [ 'operation' => $operation ] );
        }

        if ( false === $operation_result ) {
            return $this->result( false, 'provider_operation_failed', [ 'operation' => $operation ] );
        }

        return $this->result( true, $success_code, [ 'operation' => $operation ] );
    }

    /**
     * @param array $request Provider request.
     * @return array
     */
    private function normalize_plan( array $request ) {
        return [
            'site_id'      => isset( $request['site_id'] ) ? max( 1, absint( $request['site_id'] ) ) : get_current_blog_id(),
            'urls'         => isset( $request['urls'] ) && is_array( $request['urls'] ) ? array_values( array_unique( array_filter( array_map( 'esc_url_raw', $request['urls'] ) ) ) ) : [],
            'dependencies' => isset( $request['dependencies'] ) && is_array( $request['dependencies'] ) ? array_values( array_unique( array_filter( array_map( 'strval', $request['dependencies'] ) ) ) ) : [],
            'generations'  => isset( $request['generations'] ) && is_array( $request['generations'] ) ? array_values( array_unique( array_filter( array_map( 'strval', $request['generations'] ) ) ) ) : [],
            'conservative' => ! empty( $request['conservative'] ),
        ];
    }

    /**
     * @param bool   $success Operation state.
     * @param string $code Stable result code.
     * @param array  $extra Additional non-sensitive data.
     * @return array
     */
    private function result( $success, $code, array $extra = [] ) {
        return array_merge(
            [
                'success'  => (bool) $success,
                'code'     => sanitize_key( (string) $code ),
                'provider' => $this->id,
            ],
            $extra
        );
    }
}
