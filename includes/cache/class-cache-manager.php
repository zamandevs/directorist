<?php

namespace Directorist\Cache;

/**
 * Request-scoped entry point for Directorist page-cache services.
 */
final class Cache_Manager {
    /** @var Cache_Manager */
    private static $instance;

    /** @var Cache_Provider */
    private $provider;

    /** @var bool */
    private $initialized = false;

    /**
     * @param Cache_Provider|null $provider Initial provider.
     */
    private function __construct( Cache_Provider $provider = null ) {
        $this->provider = $provider ?: new Null_Cache_Provider();
    }

    /** @return Cache_Manager */
    public static function instance() {
        if ( ! self::$instance instanceof self ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /** @return Cache_Manager */
    public function initialize() {
        if ( $this->initialized ) {
            return $this;
        }

        $this->initialized = true;

        /**
         * Fires after Directorist's page-cache contracts are available.
         *
         * No cache provider is selected and no output is captured by this hook.
         *
         * @param Cache_Manager $manager Request-scoped manager.
         */
        do_action( 'directorist_page_cache_initialized', $this );

        return $this;
    }

    /** @return bool */
    public function is_initialized() {
        return $this->initialized;
    }

    /** @return Cache_Provider */
    public function get_provider() {
        return $this->provider;
    }
}
