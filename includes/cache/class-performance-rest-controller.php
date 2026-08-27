<?php

namespace Directorist\Cache;

/**
 * Authenticated REST boundary for the Directorist Performance screen.
 */
final class Performance_REST_Controller {
    const NAMESPACE = 'directorist/v1';
    const BASE      = '/admin/performance';

    /** @var Performance_Status */
    private $status;

    /** @var Performance_Resource_Catalog */
    private $resources;

    /** @var Performance_Settings */
    private $settings;

    /** @var Performance_Operations */
    private $operations;

    /** @var Performance_Listing_Index_Service */
    private $listing_index;

    /** @var Performance_Job_Manager|null */
    private $jobs;

    public function __construct( Performance_Status $status, Performance_Resource_Catalog $resources, Performance_Settings $settings, Performance_Operations $operations, Performance_Listing_Index_Service $listing_index, Performance_Job_Manager $jobs = null ) {
        $this->status        = $status;
        $this->resources     = $resources;
        $this->settings      = $settings;
        $this->operations    = $operations;
        $this->listing_index = $listing_index;
        $this->jobs          = $jobs;
    }

    /** @return void */
    public function register_routes() {
        $permission = [ $this, 'permissions_check' ];

        register_rest_route(
            self::NAMESPACE,
            self::BASE . '/summary',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_summary' ],
                'permission_callback' => $permission,
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            self::BASE . '/resources',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_resources' ],
                'permission_callback' => $permission,
                'args'                => array_merge(
                    $this->list_args( [ 'all', 'listing', 'archive', 'page', 'search' ] ),
                    $this->listing_filter_args(),
                    [
                        'orderby'     => [ 'type' => 'string', 'enum' => [ 'id', 'modified', 'cache_state' ], 'default' => 'id' ],
                        'order'       => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'ASC' ],
                        'cache_state' => [ 'type' => 'string', 'enum' => [ '', 'needs-refresh', 'uncached', 'current', 'stale', 'expired', 'invalidated', 'failed' ], 'default' => '' ],
                    ]
                ),
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            self::BASE . '/resources/filter-options',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_resource_filter_options' ],
                'permission_callback' => $permission,
                'args'                => [
                    'kind'     => [ 'type' => 'string', 'enum' => [ 'directory', 'category', 'location' ], 'required' => true ],
                    'search'   => [ 'type' => 'string', 'maxLength' => 100, 'default' => '' ],
                    'selected' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
                ],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            self::BASE . '/resources/variants',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_resource_variants' ],
                'permission_callback' => $permission,
                'args'                => [
                    'url'        => [ 'type' => 'string', 'format' => 'uri', 'required' => true ],
                    'route_type' => [ 'type' => 'string', 'pattern' => '^[a-z0-9-]{1,64}$', 'required' => true ],
                ],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            self::BASE . '/settings',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_settings' ],
                    'permission_callback' => $permission,
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_settings' ],
                    'permission_callback' => $permission,
                    'args'                => [
                        'enabled'                => [ 'type' => 'boolean' ],
                        'cache_duration'         => [ 'type' => 'string', 'enum' => [ 'automatic', '3600', '21600', '43200', '86400' ] ],
                        'cache_filtered_results' => [ 'type' => 'boolean' ],
                    ],
                ],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            self::BASE . '/cache/actions',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'cache_action' ],
                'permission_callback' => $permission,
                'args'                => [
                    'action'       => [ 'type' => 'string', 'enum' => [ 'purge-all', 'warm-all', 'warm-filtered', 'purge-filtered', 'purge-selected', 'warm-selected' ], 'required' => true ],
                    'urls'         => [ 'type' => 'array', 'maxItems' => 50, 'items' => [ 'type' => 'string', 'format' => 'uri' ] ],
                    'type'         => [ 'type' => 'string', 'enum' => [ 'all', 'listing', 'archive', 'page', 'search' ] ],
                    'search'       => [ 'type' => 'string', 'maxLength' => 100 ],
                    'directory_id' => [ 'type' => 'integer', 'minimum' => 0 ],
                    'category_id'  => [ 'type' => 'integer', 'minimum' => 0 ],
                    'location_id'  => [ 'type' => 'integer', 'minimum' => 0 ],
                    'cache_state'  => [ 'type' => 'string', 'enum' => [ '', 'needs-refresh', 'uncached', 'current', 'stale', 'expired', 'invalidated', 'failed' ] ],
                ],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            self::BASE . '/jobs',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_job' ],
                    'permission_callback' => $permission,
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'cancel_job' ],
                    'permission_callback' => $permission,
                    'args'                => [
                        'id' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$', 'required' => true ],
                    ],
                ],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            self::BASE . '/listing-index',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_listing_index' ],
                'permission_callback' => $permission,
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            self::BASE . '/listing-index/directories',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_listing_index_directories' ],
                'permission_callback' => $permission,
                'args'                => $this->list_args(),
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            self::BASE . '/listing-index/actions',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'listing_index_action' ],
                'permission_callback' => $permission,
                'args'                => [
                    'action'       => [ 'type' => 'string', 'enum' => [ 'regenerate-directory', 'regenerate-all', 'repair', 'enable-reads', 'disable-reads' ], 'required' => true ],
                    'directory_id' => [ 'type' => 'integer', 'minimum' => 0 ],
                ],
            ]
        );
    }

    /** @return true|\WP_Error */
    public function permissions_check() {
        return current_user_can( 'manage_options' )
            ? true
            : new \WP_Error( 'directorist_performance_forbidden', __( 'You are not allowed to manage Directorist performance.', 'directorist' ), [ 'status' => 403 ] );
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function get_summary() {
        $authorized = $this->authorize();

        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }

        try {
            $snapshot = $this->status->snapshot( [ 'include_inventory' => false ] );
            $provider = $snapshot['provider'];
            $mode     = $snapshot['delivery']['mode'];
            $index    = $this->listing_index->get_summary();
            $job      = $this->jobs ? $this->jobs->get_current() : [ 'state' => 'idle', 'progress' => 0 ];
            $coverage = $this->resources->get_cache_coverage();
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->service_error();
        }

        return rest_ensure_response(
            [
                'page_cache'    => [
                    'state'      => $snapshot['outcome'],
                    'enabled'    => ! empty( $snapshot['settings']['enabled'] ),
                    'delivery'   => $mode,
                    'provider'   => [
                        'id'        => $provider['id'],
                        'label'     => $this->provider_label( $provider['id'] ),
                        'available' => ! empty( $provider['available'] ),
                        'managed'   => 'external' === $mode,
                    ],
                    'automation' => $snapshot['automation'],
                    'queue'      => $snapshot['warm_queue'],
                    'coverage'   => $coverage,
                ],
                'listing_index' => $index,
                'job'           => $job,
            ]
        );
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function get_resources( \WP_REST_Request $request ) {
        $authorized = $this->authorize();

        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }

        try {
            return rest_ensure_response( $this->resources->get_items( $request->get_params() ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->service_error();
        }
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function get_resource_filter_options( \WP_REST_Request $request ) {
        $authorized = $this->authorize();

        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }

        try {
            return rest_ensure_response( $this->resources->get_filter_options( $request->get_params() ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->service_error();
        }
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function get_resource_variants( \WP_REST_Request $request ) {
        $authorized = $this->authorize();

        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }

        try {
            $result = $this->resources->get_variants( $request->get_param( 'url' ), $request->get_param( 'route_type' ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->service_error();
        }

        if ( 'invalid-resource' === $result['code'] ) {
            return new \WP_Error( 'directorist_performance_invalid_resource', __( 'This resource cannot be inspected.', 'directorist' ), [ 'status' => 400 ] );
        }

        return rest_ensure_response( $result );
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function get_settings() {
        $authorized = $this->authorize();

        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }

        try {
            return rest_ensure_response( $this->public_settings( $this->settings->get() ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->service_error();
        }
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function update_settings( \WP_REST_Request $request ) {
        $authorized = $this->authorize();

        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }

        $input  = $request->get_json_params();
        $input  = is_array( $input ) && ! empty( $input ) ? $input : $request->get_body_params();
        $values = [];

        foreach ( [ 'enabled', 'cache_duration', 'cache_filtered_results' ] as $key ) {
            if ( array_key_exists( $key, $input ) ) {
                $values[ $key ] = $input[ $key ];
            }
        }

        $result = $this->operations->execute( 'save_settings', $values );

        if ( empty( $result['success'] ) ) {
            return $this->operation_error( $result );
        }

        return rest_ensure_response( $this->public_settings( $result['settings'] ) );
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function cache_action( \WP_REST_Request $request ) {
        $authorized = $this->authorize();

        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }

        $input  = $request->get_json_params();
        $input  = is_array( $input ) && ! empty( $input ) ? $input : $request->get_body_params();
        $action = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '';

        if ( 'purge-all' === $action ) {
            $result = $this->operations->execute( 'purge', [] );
        } elseif ( in_array( $action, [ 'warm-all', 'warm-filtered', 'purge-filtered' ], true ) ) {
            if ( ! $this->jobs ) {
                return new \WP_Error( 'directorist_performance_job_unavailable', __( 'Background cache operations are unavailable.', 'directorist' ), [ 'status' => 409 ] );
            }

            $scope  = [
                'type'         => isset( $input['type'] ) ? $input['type'] : 'all',
                'search'       => isset( $input['search'] ) ? $input['search'] : '',
                'directory_id' => isset( $input['directory_id'] ) ? $input['directory_id'] : 0,
                'category_id'  => isset( $input['category_id'] ) ? $input['category_id'] : 0,
                'location_id'  => isset( $input['location_id'] ) ? $input['location_id'] : 0,
                'cache_state'  => isset( $input['cache_state'] ) ? $input['cache_state'] : '',
            ];
            $result = $this->jobs->start( 'purge-filtered' === $action ? 'purge' : 'warm', $scope );
        } elseif ( in_array( $action, [ 'purge-selected', 'warm-selected' ], true ) ) {
            $urls = $this->public_urls( isset( $input['urls'] ) ? $input['urls'] : [] );

            if ( empty( $urls ) ) {
                return new \WP_Error( 'directorist_performance_invalid_urls', __( 'Select valid Directorist pages.', 'directorist' ), [ 'status' => 400 ] );
            }

            $result = $this->operations->execute( 'purge-selected' === $action ? 'purge_urls' : 'warm_urls', [ 'urls' => $urls ] );
        } else {
            return new \WP_Error( 'directorist_performance_invalid_action', __( 'This cache action is not available.', 'directorist' ), [ 'status' => 400 ] );
        }

        return empty( $result['success'] ) ? $this->operation_error( $result ) : rest_ensure_response( $result );
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function get_job() {
        $authorized = $this->authorize();

        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }

        try {
            return rest_ensure_response( $this->jobs ? $this->jobs->get_current() : [ 'state' => 'idle', 'progress' => 0 ] );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->service_error();
        }
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function cancel_job( \WP_REST_Request $request ) {
        $authorized = $this->authorize();

        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }

        if ( ! $this->jobs ) {
            return new \WP_Error( 'directorist_performance_job_unavailable', __( 'Background cache operations are unavailable.', 'directorist' ), [ 'status' => 409 ] );
        }

        $job_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
        $result = $this->jobs->cancel( $job_id );

        return empty( $result['success'] ) ? $this->operation_error( $result ) : rest_ensure_response( $result );
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function get_listing_index() {
        $authorized = $this->authorize();

        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }

        try {
            return rest_ensure_response( $this->listing_index->get_summary() );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->service_error();
        }
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function get_listing_index_directories( \WP_REST_Request $request ) {
        $authorized = $this->authorize();

        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }

        try {
            return rest_ensure_response( $this->listing_index->get_directories( $request->get_params() ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->service_error();
        }
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function listing_index_action( \WP_REST_Request $request ) {
        $authorized = $this->authorize();

        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }

        $input  = $request->get_json_params();
        $input  = is_array( $input ) && ! empty( $input ) ? $input : $request->get_body_params();
        $action = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '';

        try {
            if ( 'regenerate-directory' === $action ) {
                $result = $this->listing_index->regenerate_directory( isset( $input['directory_id'] ) ? $input['directory_id'] : 0 );
            } elseif ( 'regenerate-all' === $action ) {
                $result = $this->listing_index->regenerate_all();
            } elseif ( 'repair' === $action ) {
                $result = $this->listing_index->repair();
            } elseif ( in_array( $action, [ 'enable-reads', 'disable-reads' ], true ) ) {
                $result = $this->listing_index->set_reads_enabled( 'enable-reads' === $action );
            } else {
                return new \WP_Error( 'directorist_performance_invalid_action', __( 'This Listing Index action is not available.', 'directorist' ), [ 'status' => 400 ] );
            }
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->service_error();
        }

        return empty( $result['success'] ) ? $this->operation_error( $result ) : rest_ensure_response( $result );
    }

    /** @return true|\WP_Error */
    private function authorize() {
        return $this->permissions_check();
    }

    private function public_settings( array $settings ) {
        return [
            'enabled'                => ! empty( $settings['enabled'] ),
            'cache_duration'         => isset( $settings['cache_duration'] ) ? (string) $settings['cache_duration'] : 'automatic',
            'cache_filtered_results' => ! empty( $settings['cache_filtered_results'] ),
            'duration_choices'       => [ 'automatic', '3600', '21600', '43200', '86400' ],
        ];
    }

    private function public_urls( $urls ) {
        $registry = new Warm_URL_Registry( home_url( '/' ), 50 );
        $registry->add( is_array( $urls ) ? $urls : [], 'performance-admin' );

        return $registry->all();
    }

    private function operation_error( array $result ) {
        $code     = sanitize_key( isset( $result['code'] ) ? $result['code'] : 'operation_failed' );
        $messages = [
            'no-resources'              => __( 'No matching Directorist pages were found.', 'directorist' ),
            'resource-discovery-failed' => __( 'Directorist could not find public pages for this operation. Try again shortly.', 'directorist' ),
            'dispatch-failed'           => __( 'The background operation could not be started. Try again shortly.', 'directorist' ),
            'job-conflict'              => __( 'Another cache operation is already running.', 'directorist' ),
        ];

        return new \WP_Error(
            'directorist_performance_' . $code,
            isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'The operation could not be completed.', 'directorist' ),
            [ 'status' => 409, 'operation' => $result ]
        );
    }

    private function service_error() {
        return new \WP_Error(
            'directorist_performance_temporarily_unavailable',
            __( 'Performance data is temporarily unavailable. Try again shortly.', 'directorist' ),
            [ 'status' => 503 ]
        );
    }

    private function list_args( array $types = [] ) {
        $args = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ],
            'search'   => [ 'type' => 'string', 'maxLength' => 100, 'default' => '' ],
        ];

        if ( ! empty( $types ) ) {
            $args['type'] = [ 'type' => 'string', 'enum' => $types, 'default' => 'all' ];
        }

        return $args;
    }

    private function listing_filter_args() {
        return [
            'directory_id' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
            'category_id'  => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
            'location_id'  => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
        ];
    }

    private function provider_label( $provider_id ) {
        $labels = [
            'directorist-cache' => __( 'Directorist Cache', 'directorist' ),
            'wp-super-cache'    => __( 'WP Super Cache', 'directorist' ),
            'litespeed-cache'   => __( 'LiteSpeed Cache', 'directorist' ),
            'wp-rocket'         => __( 'WP Rocket', 'directorist' ),
            'wp-fastest-cache'  => __( 'WP Fastest Cache', 'directorist' ),
            'cache-enabler'     => __( 'Cache Enabler', 'directorist' ),
        ];

        return isset( $labels[ $provider_id ] ) ? $labels[ $provider_id ] : __( 'Cache provider', 'directorist' );
    }
}
