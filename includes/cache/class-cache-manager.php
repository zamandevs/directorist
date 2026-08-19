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

    /** @var Dependency_Collector|null */
    private $dependency_collector;

    /** @var Route_Identity|null */
    private $route_identity;

    /** @var string */
    private $private_reason = '';

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

    /**
     * Begin request-local dependency collection for an eligible route candidate.
     *
     * @param Route_Identity $identity Route identity.
     * @return Dependency_Collector
     */
    public function begin_request( Route_Identity $identity ) {
        $this->dependency_collector = null;
        $this->route_identity       = $identity;
        $this->dependency_collector = new Dependency_Collector( $identity->get_site_id() );
        $this->dependency_collector->collect_route( $identity );

        return $this->dependency_collector;
    }

    /** @return Route_Identity|null */
    public function get_route_identity() {
        return $this->route_identity;
    }

    /** @return bool */
    public function is_collecting_dependencies() {
        return $this->dependency_collector instanceof Dependency_Collector;
    }

    /**
     * @param string     $domain Dependency domain.
     * @param int|string $identifier Optional identifier.
     * @return bool
     */
    public function add_dependency( $domain, $identifier = '' ) {
        if ( ! $this->is_collecting_dependencies() ) {
            return false;
        }

        return $this->dependency_collector->add( $domain, $identifier );
    }

    /** @return string[] */
    public function get_dependencies() {
        return $this->is_collecting_dependencies() ? $this->dependency_collector->all() : [];
    }

    /**
     * @param string $reason Stable, non-sensitive reason.
     * @return bool
     */
    public function mark_private( $reason = 'integration_veto' ) {
        if ( '' === $this->private_reason ) {
            $this->private_reason = sanitize_key( (string) $reason );
        }

        if ( '' === $this->private_reason ) {
            $this->private_reason = 'integration_veto';
        }

        return true;
    }

    /** @return bool */
    public function is_private() {
        return '' !== $this->private_reason;
    }

    /** @return string */
    public function get_private_reason() {
        return $this->private_reason;
    }

    /** @return void */
    public function end_request() {
        $this->dependency_collector = null;
        $this->route_identity       = null;
        $this->private_reason       = '';
    }
}
