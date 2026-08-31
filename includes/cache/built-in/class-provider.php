<?php

namespace Directorist\Cache\Built_In;

/**
 * Core provider adapter for Directorist's built-in early cache engine.
 */
final class Provider implements \Directorist\Cache\Cache_Provider {
    /** @var callable */
    private $engine_resolver;

    /** @var callable */
    private $health_resolver;

    /** @var callable */
    private $enabled_resolver;

    /** @var callable|null */
    private $maintenance_scheduler;

    /** @var callable|null */
    private $inventory_resolver;

    /** @var bool */
    private $engine_resolved = false;

    /** @var object|null */
    private $engine;

    /**
     * @param callable|null $engine_resolver Engine resolver.
     * @param callable|null $health_resolver Ownership health resolver.
     * @param callable|null $enabled_resolver Early-cache state resolver.
     * @param callable|null $maintenance_scheduler Asynchronous cleanup scheduler.
     * @param callable|null $inventory_resolver On-demand cache inventory resolver.
     */
    public function __construct( $engine_resolver = null, $health_resolver = null, $enabled_resolver = null, $maintenance_scheduler = null, $inventory_resolver = null ) {
        $this->engine_resolver       = is_callable( $engine_resolver ) ? $engine_resolver : static function () {
            return function_exists( 'directorist_page_cache_builtin_engine' ) ? directorist_page_cache_builtin_engine() : null;
        };
        $this->health_resolver       = is_callable( $health_resolver ) ? $health_resolver : static function () {
            return function_exists( 'directorist_page_cache_builtin_runtime_is_healthy' )
                && directorist_page_cache_builtin_runtime_is_healthy();
        };
        $this->enabled_resolver      = is_callable( $enabled_resolver ) ? $enabled_resolver : static function () {
            return function_exists( 'directorist_page_cache_is_enabled' ) && directorist_page_cache_is_enabled();
        };
        $this->maintenance_scheduler = is_callable( $maintenance_scheduler ) ? $maintenance_scheduler : null;
        $this->inventory_resolver    = is_callable( $inventory_resolver ) ? $inventory_resolver : null;
    }

    /** @return string */
    public function get_id() {
        return 'directorist-cache';
    }

    /** @return bool */
    public function is_available() {
        try {
            if ( ! call_user_func( $this->enabled_resolver ) ) {
                return false;
            }

            if ( ! call_user_func( $this->health_resolver ) ) {
                return false;
            }

            $engine = $this->resolve_engine();

            return is_object( $engine )
                && is_callable( [ $engine, 'is_available' ] )
                && $engine->is_available()
                && is_callable( [ $engine, 'invalidate' ] );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }
    }

    /** @return string[] */
    public function get_capabilities() {
        $capabilities = [
            'purge_dependencies',
            'purge_generations',
            'purge_site',
            'purge_url',
            'purge_urls',
            'purge_entries',
        ];

        try {
            $engine = $this->resolve_engine();

            if ( is_object( $engine ) && is_callable( [ $engine, 'supports_warm' ] ) && $engine->supports_warm() ) {
                $capabilities[] = 'warm_urls';
            }
        } catch ( \Throwable $exception ) {
            unset( $exception );
        }

        return $capabilities;
    }

    /**
     * @param string $capability Capability identifier.
     * @return bool
     */
    public function supports( $capability ) {
        return in_array( $capability, $this->get_capabilities(), true );
    }

    /**
     * @param array $request Invalidation plan.
     * @return array
     */
    public function invalidate( array $request ) {
        if ( ! $this->is_available() ) {
            return $this->result( false, 'engine_unavailable' );
        }

        $result = $this->call_engine( 'invalidate', [ $request ] );

        if ( ! empty( $result['success'] ) && $this->maintenance_scheduler ) {
            try {
                call_user_func( $this->maintenance_scheduler );
            } catch ( \Throwable $exception ) {
                unset( $exception );
            }
        }

        return $result;
    }

    /**
     * @param string[] $urls Public URLs.
     * @return array
     */
    public function warm( array $urls ) {
        if ( ! $this->is_available() ) {
            return $this->result( false, 'engine_unavailable' );
        }

        if ( ! $this->supports( 'warm_urls' ) ) {
            return $this->result( false, 'capability_unavailable' );
        }

        return $this->call_engine( 'warm', [ $urls ] );
    }

    /** @return array */
    public function get_status() {
        try {
            $enabled = (bool) call_user_func( $this->enabled_resolver );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $enabled = false;
        }

        $status = [
            'id'           => $this->get_id(),
            'available'    => $this->is_available(),
            'capabilities' => $this->get_capabilities(),
            'enabled'      => $enabled,
        ];

        if ( ! $status['available'] ) {
            $status['code'] = $enabled ? 'engine_unavailable' : 'integration_disabled';

            return $status;
        }

        try {
            $engine = $this->resolve_engine();

            if ( is_callable( [ $engine, 'get_status' ] ) ) {
                $status['engine'] = $engine->get_status();
            }

        } catch ( \Throwable $exception ) {
            unset( $exception );
            $status['available'] = false;
            $status['code']      = 'engine_exception';
        }

        return $status;
    }

    /**
     * Return bounded built-in cache inventory for an explicit admin read.
     *
     * @param array $args Inventory pagination and filtering.
     * @return array
     */
    public function get_inventory( array $args = [] ) {
        if ( ! $this->inventory_resolver ) {
            return [];
        }

        try {
            $inventory = call_user_func( $this->inventory_resolver, $args );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return [];
        }

        return is_array( $inventory ) ? $inventory : [];
    }

    /** @return object|null */
    private function resolve_engine() {
        if ( ! $this->engine_resolved ) {
            $this->engine          = call_user_func( $this->engine_resolver );
            $this->engine_resolved = true;
        }

        return $this->engine;
    }

    /**
     * @param string $method Engine method.
     * @param array  $arguments Engine arguments.
     * @return array
     */
    private function call_engine( $method, array $arguments ) {
        try {
            $result = call_user_func_array( [ $this->resolve_engine(), $method ], $arguments );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->result( false, 'engine_exception' );
        }

        return is_array( $result ) ? $result : $this->result( false, 'invalid_engine_result' );
    }

    /**
     * @param bool   $success Operation state.
     * @param string $code Stable code.
     * @return array
     */
    private function result( $success, $code ) {
        return [
            'success'  => (bool) $success,
            'code'     => $code,
            'provider' => $this->get_id(),
        ];
    }
}
