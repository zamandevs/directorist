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

    /** @var Change_Set|null */
    private $change_set;

    /** @var Invalidation_Subscriber|null */
    private $invalidation_subscriber;

    /** @var Response_Capture|null */
    private $response_capture;

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
     * Enable mutation tracking for an explicitly selected available provider.
     *
     * Provider discovery and priority belong to the provider registry. The
     * default null provider never calls this method.
     *
     * @param Cache_Provider $provider Selected provider.
     * @return bool
     */
    public function enable_invalidation( Cache_Provider $provider ) {
        if ( ! $provider->is_available() || $provider instanceof Null_Cache_Provider ) {
            return false;
        }

        $this->disable_invalidation();

        $this->provider                = $provider;
        $this->change_set              = new Change_Set( get_current_blog_id() );
        $dispatcher                    = new Invalidation_Dispatcher( $this->change_set, new Invalidation_Planner(), $provider );
        $this->invalidation_subscriber = new Invalidation_Subscriber( $this->change_set, $dispatcher );
        $this->invalidation_subscriber->register();

        return true;
    }

    /** @return void */
    public function disable_invalidation() {
        if ( $this->invalidation_subscriber instanceof Invalidation_Subscriber ) {
            $this->invalidation_subscriber->unregister();
        }

        $this->invalidation_subscriber = null;
        $this->change_set              = null;
        $this->provider                = new Null_Cache_Provider();
    }

    /** @return bool */
    public function is_tracking_mutations() {
        return $this->change_set instanceof Change_Set;
    }

    /** @return Change_Set|null */
    public function get_change_set() {
        return $this->change_set;
    }

    /**
     * Dispatch the active request's coalesced invalidation plan.
     *
     * The subscriber also calls this at shutdown. A second call is a no-op
     * because the dispatcher drains the request-local change set.
     *
     * @return array
     */
    public function dispatch_invalidation() {
        if ( ! $this->invalidation_subscriber instanceof Invalidation_Subscriber ) {
            return [ 'success' => false, 'code' => 'invalidation_disabled' ];
        }

        return $this->invalidation_subscriber->dispatch();
    }

    /**
     * Record an extension-owned semantic mutation while tracking is active.
     *
     * @param string $extension Extension slug.
     * @param string $identifier Mutation identifier.
     * @param array  $context Mutation context.
     * @return bool
     */
    public function record_extension_change( $extension, $identifier = '', array $context = [] ) {
        if ( ! $this->is_tracking_mutations() ) {
            return false;
        }

        $extension  = sanitize_key( sanitize_title( (string) $extension ) );
        $identifier = sanitize_key( sanitize_title( (string) $identifier ) );

        if ( '' === $extension ) {
            return false;
        }

        $change_id = '' === $identifier ? $extension : $extension . ':' . $identifier;

        return $this->change_set->record( Change_Type::EXTENSION, $change_id, $context );
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

    /** @return array */
    public function begin_response_capture() {
        if ( ! $this->response_capture instanceof Response_Capture ) {
            $this->response_capture = new Response_Capture( $this );
        }

        return $this->response_capture->begin();
    }

    /** @return array */
    public function finish_response_capture() {
        if ( ! $this->response_capture instanceof Response_Capture ) {
            return [
                'eligible'     => false,
                'reason'       => 'capture_not_started',
                'detail'       => '',
                'site_id'      => 0,
                'route_type'   => '',
                'cache_key'    => '',
                'dependencies' => [],
            ];
        }

        return $this->response_capture->finish();
    }
}
