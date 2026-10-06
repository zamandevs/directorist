<?php

namespace Directorist\Cache;

use Directorist\Cache\Built_In\Cache_Storage;
use Directorist\Cache\Built_In\Request_Key;

/**
 * Paginated canonical Directorist resources for the Performance screen.
 */
final class Performance_Resource_Catalog {
    const MAX_PER_PAGE        = 50;
    const MAX_PAGINATED_PAGES = 5;
    const MAX_FILTER_OPTIONS  = 50;

    /** @var Cache_Provider */
    private $provider;

    /** @var callable */
    private $source;

    /** @var callable */
    private $state_resolver;

    /** @var callable */
    private $variant_resolver;

    /** @var string|null */
    private $resolved_provider_id;

    /** @var bool|null */
    private $resolved_provider_available;

    /** @var array */
    private $resolved_capabilities = [];

    /** @var Performance_Resource_Store|null */
    private $store;

    /** @var bool */
    private $use_persistent_source;

    public function __construct( Cache_Provider $provider, $source = null, $state_resolver = null, $variant_resolver = null, Performance_Resource_Store $store = null ) {
        $this->provider              = $provider;
        $this->use_persistent_source = ! is_callable( $source );
        $this->source                = is_callable( $source ) ? $source : [ $this, 'canonical_source' ];
        $this->state_resolver        = is_callable( $state_resolver ) ? $state_resolver : [ $this, 'resolve_cache_state' ];
        $this->variant_resolver      = is_callable( $variant_resolver ) ? $variant_resolver : [ $this, 'resolve_variants' ];
        $this->store                 = $store ?: ( $this->use_persistent_source ? new Performance_Resource_Store() : null );
    }

    /**
     * @param array $args List filters and pagination.
     * @return array
     */
    public function get_items( array $args = [] ) {
        $args                   = $this->normalize_args( $args );
        $state_query            = '' !== $args['cache_state'] || 'cache_state' === $args['orderby'];
        $source_args            = $args;
        $store_status           = $this->store instanceof Performance_Resource_Store ? $this->store->status() : [];
        $active_generation      = 'ready' === ( isset( $store_status['state'] ) ? $store_status['state'] : '' )
            ? ( isset( $store_status['generation'] ) ? (int) $store_status['generation'] : 0 )
            : ( isset( $store_status['active_generation'] ) ? (int) $store_status['active_generation'] : 0 );
        $persistent_state_query = $state_query && $this->use_persistent_source && $this->store instanceof Performance_Resource_Store && $this->store->exists() && 0 < $active_generation;

        if ( $state_query && ! $persistent_state_query ) {
            $source_args['page']     = 1;
            $source_args['per_page'] = 5000;
        }

        $result  = $this->source_result( $source_args );
        $items   = [];
        $actions = [
            'warm'  => $this->provider_supports( Provider_Capabilities::WARM_URLS ),
            'purge' => $this->provider_supports( Provider_Capabilities::PURGE_URL ) || $this->provider_supports( Provider_Capabilities::PURGE_URLS ),
        ];

        foreach ( isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : [] as $resource ) {
            $resource = $this->normalize_resource( $resource );

            if ( empty( $resource ) ) {
                continue;
            }

            if ( 'directorist-cache' === $this->provider_id() && isset( $resource['stored_cache'] ) && is_array( $resource['stored_cache'] ) ) {
                $state             = $resource['stored_cache'];
                $state['exact']    = true;
                $state['provider'] = 'directorist-cache';
            } else {
                try {
                    $state = call_user_func( $this->state_resolver, $resource, $this->provider );
                } catch ( \Throwable $exception ) {
                    unset( $exception );
                    $state = [ 'state' => 'unavailable', 'provider' => $this->provider_id() ];
                }
            }

            $resource['cache']   = $this->normalize_cache_state( $state );
            $resource['actions'] = $actions;
            $items[]             = $resource;
        }

        $state_applied = ! empty( $result['state_applied'] );

        if ( '' !== $args['cache_state'] && ! $state_applied ) {
            $items = array_values(
                array_filter(
                    $items,
                    static function ( array $resource ) use ( $args ) {
                        if ( ! isset( $resource['cache']['state'] ) ) {
                            return false;
                        }

                        return 'needs-refresh' === $args['cache_state']
                            ? in_array( $resource['cache']['state'], [ 'uncached', 'stale', 'expired', 'invalidated', 'invalid', 'failed' ], true )
                            : $args['cache_state'] === $resource['cache']['state'];
                    }
                )
            );
        }

        if ( 'cache_state' === $args['orderby'] && ! $state_applied ) {
            usort(
                $items,
                static function ( array $left, array $right ) use ( $args ) {
                    $priority   = [ 'current' => 0, 'stale' => 1, 'expired' => 2, 'invalidated' => 3, 'uncached' => 4, 'failed' => 5, 'invalid' => 6, 'managed' => 7, 'unavailable' => 8 ];
                    $left_rank  = isset( $priority[ $left['cache']['state'] ] ) ? $priority[ $left['cache']['state'] ] : 9;
                    $right_rank = isset( $priority[ $right['cache']['state'] ] ) ? $priority[ $right['cache']['state'] ] : 9;
                    $comparison = $left_rank <=> $right_rank;

                    if ( 0 === $comparison ) {
                        $comparison = strcmp( $left['id'], $right['id'] );
                    }

                    return 'DESC' === $args['order'] ? -$comparison : $comparison;
                }
            );
        }

        if ( $state_query && ! $state_applied ) {
            $total = count( $items );
            $items = array_slice( $items, ( $args['page'] - 1 ) * $args['per_page'], $args['per_page'] );
        } else {
            $total = isset( $result['total'] ) ? max( 0, (int) $result['total'] ) : count( $items );
        }

        $pages = max( 1, (int) ceil( $total / $args['per_page'] ) );

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => min( $args['page'], $pages ),
            'per_page'    => $args['per_page'],
            'pages'       => $pages,
            'type'        => $args['type'],
            'search'      => $args['search'],
            'orderby'     => $args['orderby'],
            'order'       => $args['order'],
            'cache_state' => $args['cache_state'],
        ];
    }

    /** @return array */
    public function get_cache_coverage() {
        $coverage = [
            'exact'         => false,
            'ready'         => false,
            'total'         => 0,
            'cached'        => 0,
            'needs_refresh' => 0,
            'types'         => [],
        ];

        foreach ( [ 'listing', 'archive', 'page', 'search' ] as $type ) {
            $coverage['types'][ $type ] = [ 'total' => 0, 'cached' => 0, 'needs_refresh' => 0 ];
        }

        if ( ! $this->use_persistent_source || ! $this->store instanceof Performance_Resource_Store ) {
            return $coverage;
        }

        $stored          = $this->store->coverage();
        $stored['exact'] = 'directorist-cache' === $this->provider_id();

        return array_merge( $coverage, $stored );
    }

    /**
     * Return the exact number of currently failed resources in one catalog scope.
     *
     * A null result means the persistent catalog cannot make an exact decision.
     *
     * @param array $scope Resource filters.
     * @return int|null
     */
    public function get_failed_resource_count( array $scope = [] ) {
        if ( ! $this->use_persistent_source || ! $this->store instanceof Performance_Resource_Store ) {
            return null;
        }

        $status     = $this->store->status();
        $state      = isset( $status['state'] ) ? $status['state'] : '';
        $generation = 'ready' === $state
            ? ( isset( $status['generation'] ) ? max( 0, (int) $status['generation'] ) : 0 )
            : ( isset( $status['active_generation'] ) ? max( 0, (int) $status['active_generation'] ) : 0 );

        if ( ! $this->store->exists() || 1 > $generation ) {
            return null;
        }

        $result = $this->store->query(
            array_merge(
                $scope,
                [
                    'cache_state' => 'failed',
                    'page'        => 1,
                    'per_page'    => 1,
                ]
            )
        );

        return ! empty( $result['ready'] ) ? max( 0, (int) $result['total'] ) : null;
    }

    /**
     * Return one bounded canonical URL page without resolving cache state.
     *
     * @param array $args List filters and pagination.
     * @return array
     */
    public function get_urls( array $args = [] ) {
        $args           = $this->normalize_args( $args );
        $result         = $this->source_result( $args );
        $urls           = [];
        $contexts       = [];
        $resource_count = 0;

        foreach ( isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : [] as $resource ) {
            $resource = $this->normalize_resource( $resource );

            if ( ! empty( $resource['url'] ) ) {
                ++$resource_count;

                foreach ( $this->resource_urls( $resource ) as $url ) {
                    $urls[] = $url;
                    $hash   = hash( 'sha256', $url );

                    if ( ! isset( $contexts[ $hash ] ) ) {
                        $contexts[ $hash ] = [
                            'title' => $resource['title'],
                            'type'  => $resource['type'],
                        ];
                    }
                }
            }
        }

        $total = isset( $result['total'] ) ? max( 0, (int) $result['total'] ) : count( $urls );

        return [
            'urls'      => array_values( array_unique( $urls ) ),
            'contexts'  => $contexts,
            'total'     => $total,
            'page'      => $args['page'],
            'per_page'  => $args['per_page'],
            'pages'     => max( 1, (int) ceil( $total / $args['per_page'] ) ),
            'resources' => $resource_count,
        ];
    }

    /**
     * Return one bounded set of public-listing filter options.
     *
     * @param array $args Filter kind, search text, and selected term ID.
     * @return array
     */
    public function get_filter_options( array $args = [] ) {
        $kind       = isset( $args['kind'] ) ? sanitize_key( (string) $args['kind'] ) : '';
        $search     = isset( $args['search'] ) ? substr( sanitize_text_field( (string) $args['search'] ), 0, 100 ) : '';
        $selected   = isset( $args['selected'] ) ? absint( $args['selected'] ) : 0;
        $taxonomies = [
            'directory' => ATBDP_DIRECTORY_TYPE,
            'category'  => ATBDP_CATEGORY,
            'location'  => ATBDP_LOCATION,
        ];

        if ( ! isset( $taxonomies[ $kind ] ) || ! taxonomy_exists( $taxonomies[ $kind ] ) ) {
            return [ 'items' => [], 'total' => 0, 'kind' => $kind, 'search' => $search, 'has_more' => false ];
        }

        $taxonomy  = $taxonomies[ $kind ];
        $term_args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'number'     => self::MAX_FILTER_OPTIONS + 1,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];

        if ( '' !== $search ) {
            $term_args['search'] = $search;
        }

        $terms    = get_terms( $term_args );
        $terms    = is_wp_error( $terms ) || ! is_array( $terms ) ? [] : $terms;
        $has_more = count( $terms ) > self::MAX_FILTER_OPTIONS;
        $terms    = array_slice( $terms, 0, self::MAX_FILTER_OPTIONS );

        if ( 0 < $selected && ! in_array( $selected, wp_list_pluck( $terms, 'term_id' ), true ) ) {
            $selected_term = get_term( $selected, $taxonomy );

            if ( $selected_term instanceof \WP_Term && 0 < (int) $selected_term->count ) {
                array_unshift( $terms, $selected_term );
                $terms = array_slice( $terms, 0, self::MAX_FILTER_OPTIONS );
            }
        }

        $items = array_map(
            static function ( $term ) {
                return [
                    'value' => (string) $term->term_id,
                    'label' => sanitize_text_field( $term->name ),
                    'count' => max( 0, (int) $term->count ),
                ];
            },
            $terms
        );

        return [
            'items'    => array_values( $items ),
            'total'    => count( $items ),
            'kind'     => $kind,
            'search'   => $search,
            'has_more' => $has_more,
        ];
    }

    /**
     * Return bounded variant metadata only after an explicit row-details request.
     *
     * @param string $url Canonical public resource URL.
     * @param string $route_type Directorist route type.
     * @return array
     */
    public function get_variants( $url, $route_type ) {
        $url         = esc_url_raw( (string) $url );
        $route_type  = sanitize_key( (string) $route_type );
        $provider_id = $this->provider_id();

        if ( ! $this->is_public_url( $url ) || ! preg_match( '/^[a-z0-9-]{1,64}$/', $route_type ) ) {
            return [ 'exact' => false, 'provider' => $provider_id, 'items' => [], 'code' => 'invalid-resource' ];
        }

        if ( 'unknown' === $provider_id ) {
            return [ 'exact' => false, 'provider' => $provider_id, 'items' => [], 'code' => 'provider-unavailable' ];
        }

        if ( 'directorist-cache' !== $provider_id ) {
            return [ 'exact' => false, 'provider' => $provider_id, 'items' => [], 'code' => 'provider-managed' ];
        }

        try {
            $variants = call_user_func( $this->variant_resolver, $url, $route_type );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return [ 'exact' => false, 'provider' => $provider_id, 'items' => [], 'code' => 'provider-unavailable' ];
        }
        $items = [];

        foreach ( array_slice( is_array( $variants ) ? $variants : [], 0, 50 ) as $variant ) {
            $variant = $this->normalize_variant( $variant );

            if ( ! empty( $variant ) ) {
                $items[] = $variant;
            }
        }

        return [
            'exact'    => true,
            'provider' => $provider_id,
            'items'    => $items,
            'code'     => empty( $items ) ? 'uncached' : 'ready',
        ];
    }

    /**
     * @param array $args Normalized source arguments.
     * @return array
     */
    public function canonical_source( array $args ) {
        if ( $this->use_persistent_source && $this->store instanceof Performance_Resource_Store ) {
            $stored = $this->store->query( $args );

            if ( ! empty( $stored['ready'] ) ) {
                return $stored;
            }

            if ( function_exists( 'directorist_page_cache_schedule_resource_reconciliation' ) ) {
                directorist_page_cache_schedule_resource_reconciliation();
            }
        }

        $offset          = ( $args['page'] - 1 ) * $args['per_page'];
        $limit           = $args['per_page'];
        $type            = $args['type'];
        $search          = $args['search'];
        $listing_filters = $this->listing_filters( $args );

        if ( 'listing' === $type ) {
            return $this->listing_resources( $offset, $limit, $search, $listing_filters, false, $args['orderby'], $args['order'] );
        }

        if ( 'archive' === $type ) {
            return $this->term_resources( $offset, $limit, $search );
        }

        $fixed = $this->configured_resources( $search, $type );

        if ( in_array( $type, [ 'page', 'search' ], true ) ) {
            return [
                'items' => array_slice( $fixed, $offset, $limit ),
                'total' => count( $fixed ),
            ];
        }

        $listing_count = $this->listing_resources( 0, 1, $search, $listing_filters, true, $args['orderby'], $args['order'] )['total'];
        $term_count    = $this->term_resources( 0, 1, $search, true )['total'];
        $items         = [];
        $fixed_count   = count( $fixed );

        if ( $offset < $fixed_count ) {
            $items = array_slice( $fixed, $offset, $limit );
        }

        $remaining = $limit - count( $items );

        if ( 0 < $remaining ) {
            $listing_offset = max( 0, $offset - $fixed_count );

            if ( $listing_offset < $listing_count ) {
                $listing = $this->listing_resources( $listing_offset, $remaining, $search, $listing_filters, false, $args['orderby'], $args['order'] );
                $items   = array_merge( $items, $listing['items'] );
            }
        }

        $remaining = $limit - count( $items );

        if ( 0 < $remaining ) {
            $term_offset = max( 0, $offset - $fixed_count - $listing_count );

            if ( $term_offset < $term_count ) {
                $terms = $this->term_resources( $term_offset, $remaining, $search );
                $items = array_merge( $items, $terms['items'] );
            }
        }

        return [
            'items' => $items,
            'total' => $fixed_count + $listing_count + $term_count,
        ];
    }

    private function listing_resources( $offset, $limit, $search, array $filters = [], $count_only = false, $orderby = 'id', $order = 'ASC' ) {
        $query_order      = 'DESC' === $order ? 'DESC' : 'ASC';
        $query_args       = [
            'post_type'              => ATBDP_POST_TYPE,
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'orderby'                => 'modified' === $orderby ? [ 'modified' => $query_order, 'ID' => $query_order ] : 'ID',
            'order'                  => $query_order,
            'fields'                 => 'ids',
            's'                      => $search,
            'no_found_rows'          => true,
            'suppress_filters'       => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];
        $tax_query        = [];
        $taxonomy_filters = [
            'directory_id' => [ ATBDP_DIRECTORY_TYPE, false ],
            'category_id'  => [ ATBDP_CATEGORY, true ],
            'location_id'  => [ ATBDP_LOCATION, true ],
        ];

        foreach ( $taxonomy_filters as $key => $taxonomy ) {
            if ( empty( $filters[ $key ] ) ) {
                continue;
            }

            $tax_query[] = [
                'taxonomy'         => $taxonomy[0],
                'field'            => 'term_id',
                'terms'            => [ $filters[ $key ] ],
                'include_children' => $taxonomy[1],
            ];
        }

        if ( ! empty( $tax_query ) ) {
            $query_args['tax_query'] = array_merge( [ 'relation' => 'AND' ], $tax_query );
        }

        $query  = new \WP_Query( $query_args );
        $groups = [];

        foreach ( $query->posts as $listing_id ) {
            $translation = $this->translation_identity( $listing_id, 'post_' . ATBDP_POST_TYPE );
            $logical_key = $translation['logical_key'];
            $url         = get_permalink( $listing_id );

            if ( ! $url ) {
                continue;
            }

            if ( ! isset( $groups[ $logical_key ] ) ) {
                $groups[ $logical_key ] = [
                    'id'           => 0 === strpos( $logical_key, 'post:' ) ? 'listing-' . (int) $listing_id : 'listing-' . sanitize_key( $logical_key ),
                    'title'        => get_the_title( $listing_id ),
                    'url'          => $url,
                    'type'         => 'listing',
                    'route_type'   => 'listing',
                    'object_id'    => (int) $listing_id,
                    'modified_at'  => (int) get_post_modified_time( 'U', true, $listing_id ),
                    'logical_key'  => $logical_key,
                    'variant_urls' => [],
                    'languages'    => [],
                ];
            }

            $groups[ $logical_key ]['variant_urls'][] = $url;

            if ( '' !== $translation['language'] ) {
                $groups[ $logical_key ]['languages'][] = $translation['language'];
            }

            $modified = (int) get_post_modified_time( 'U', true, $listing_id );

            if ( $modified > $groups[ $logical_key ]['modified_at'] ) {
                $groups[ $logical_key ]['modified_at'] = $modified;
            }
        }

        $items = array_values( $groups );

        foreach ( $items as &$item ) {
            $item['variant_urls'] = array_values( array_unique( $item['variant_urls'] ) );
            $item['languages']    = array_values( array_unique( $item['languages'] ) );
            $item['variants']     = count( $item['variant_urls'] );
        }
        unset( $item );

        if ( 'modified' === $orderby ) {
            usort(
                $items,
                static function ( array $left, array $right ) use ( $query_order ) {
                    $comparison = $left['modified_at'] <=> $right['modified_at'];

                    if ( 0 === $comparison ) {
                        $comparison = $left['object_id'] <=> $right['object_id'];
                    }

                    return 'DESC' === $query_order ? -$comparison : $comparison;
                }
            );
        }

        $total = count( $items );

        if ( $count_only ) {
            return [ 'items' => [], 'total' => $total ];
        }

        return [
            'items' => array_slice( $items, max( 0, (int) $offset ), max( 1, (int) $limit ) ),
            'total' => $total,
        ];
    }

    private function term_resources( $offset, $limit, $search, $count_only = false ) {
        $taxonomies = [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ];
        $term_args  = [
            'taxonomy'   => $taxonomies,
            'hide_empty' => false,
            'search'     => $search,
            'number'     => 0,
            'orderby'    => 'term_id',
            'order'      => 'ASC',
        ];
        $terms      = get_terms( $term_args );
        $items      = [];

        foreach ( is_wp_error( $terms ) ? [] : $terms as $term ) {
            $url = get_term_link( $term );

            if ( is_wp_error( $url ) || ! is_string( $url ) || ! $this->is_public_url( $url ) ) {
                continue;
            }

            $route_type = ATBDP_LOCATION === $term->taxonomy ? 'location' : ( ATBDP_TAGS === $term->taxonomy ? 'tag' : 'category' );
            $items[]    = [
                'id'          => 'term-' . (int) $term->term_id,
                'title'       => $term->name,
                'url'         => $url,
                'type'        => 'archive',
                'route_type'  => $route_type,
                'object_id'   => (int) $term->term_id,
                'modified_at' => 0,
            ];
        }

        $total = count( $items );

        if ( $count_only ) {
            return [ 'items' => [], 'total' => $total ];
        }

        return [
            'items' => array_slice( $items, max( 0, (int) $offset ), max( 1, (int) $limit ) ),
            'total' => $total,
        ];
    }

    private function configured_resources( $search, $type ) {
        $resources = [];
        $seen      = [];
        $pages     = [
            'listings'   => [ 'type' => 'page', 'route' => 'listings' ],
            'results'    => [ 'type' => 'search', 'route' => 'search' ],
            'category'   => [ 'type' => 'page', 'route' => 'category' ],
            'location'   => [ 'type' => 'page', 'route' => 'location' ],
            'tag'        => [ 'type' => 'page', 'route' => 'tag' ],
            'author'     => [ 'type' => 'page', 'route' => 'author' ],
            'categories' => [ 'type' => 'page', 'route' => 'categories' ],
            'locations'  => [ 'type' => 'page', 'route' => 'locations' ],
            'search'     => [ 'type' => 'search', 'route' => 'search-form' ],
        ];

        foreach ( $pages as $page_name => $descriptor ) {
            if ( 'all' !== $type && $descriptor['type'] !== $type ) {
                continue;
            }

            $page = get_post( directorist_get_page_id( $page_name ) );

            if ( ! $page instanceof \WP_Post || 'publish' !== $page->post_status ) {
                continue;
            }

            $url = get_permalink( $page );

            if ( ! $url || isset( $seen[ $url ] ) || ( '' !== $search && false === stripos( $page->post_title, $search ) ) ) {
                continue;
            }

            $seen[ $url ] = true;
            $resources[]  = [
                'id'          => 'page-' . (int) $page->ID,
                'title'       => get_the_title( $page ),
                'url'         => $url,
                'type'        => $descriptor['type'],
                'route_type'  => $descriptor['route'],
                'object_id'   => (int) $page->ID,
                'modified_at' => (int) get_post_modified_time( 'U', true, $page ),
            ];
        }

        foreach ( $this->discovered_directorist_pages() as $resource ) {
            if ( 'all' !== $type && $resource['type'] !== $type ) {
                continue;
            }

            if ( isset( $seen[ $resource['url'] ] ) || ( '' !== $search && false === stripos( $resource['title'], $search ) ) ) {
                continue;
            }

            $seen[ $resource['url'] ] = true;
            $resources[]              = $resource;
        }

        if ( in_array( $type, [ 'all', 'search' ], true ) && ! $this->has_route_type( $resources, 'search' ) ) {
            $url   = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_search_result_page_link() : home_url( '/' );
            $title = __( 'Search results', 'directorist' );

            if ( ! is_string( $url ) || '' === $url ) {
                $url = home_url( '/' );
            }

            if ( $this->is_public_url( $url ) && ! isset( $seen[ $url ] ) && ( '' === $search || false !== stripos( $title, $search ) ) ) {
                $resources[] = [
                    'id'          => 'search-fallback',
                    'title'       => $title,
                    'url'         => $url,
                    'type'        => 'search',
                    'route_type'  => 'search',
                    'object_id'   => 0,
                    'modified_at' => 0,
                ];
            }
        }

        return $resources;
    }

    /** @return array */
    private function discovered_directorist_pages() {
        $query     = new \WP_Query(
            [
                'post_type'              => 'page',
                'post_status'            => 'publish',
                'posts_per_page'         => -1,
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'suppress_filters'       => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
            ]
        );
        $resources = [];

        foreach ( $query->posts as $page ) {
            if ( ! $page instanceof \WP_Post || ! $this->is_directorist_page( $page ) ) {
                continue;
            }

            $url = get_permalink( $page );

            if ( ! $url ) {
                continue;
            }

            $route_type    = $this->page_route_type( $page );
            $resource_type = in_array( $route_type, [ 'search', 'search-form' ], true ) ? 'search' : 'page';
            $resources[]   = [
                'id'          => 'page-' . (int) $page->ID,
                'title'       => get_the_title( $page ),
                'url'         => $url,
                'type'        => $resource_type,
                'route_type'  => $route_type,
                'object_id'   => (int) $page->ID,
                'modified_at' => (int) get_post_modified_time( 'U', true, $page ),
            ];
        }

        /**
         * Registers integration-owned public Directorist resources.
         *
         * @param array $resources Normalized resource candidates.
         */
        $registered = apply_filters( 'directorist_performance_cache_registered_resources', [], $this );

        if ( is_array( $registered ) ) {
            $resources = array_merge( $resources, $registered );
        }

        return $resources;
    }

    private function is_directorist_page( \WP_Post $page ) {
        $content = (string) $page->post_content;

        if ( preg_match( '/\[(?:directorist|atbdp)[a-z0-9_-]*(?:\s|\])/', $content ) ) {
            return true;
        }

        foreach ( parse_blocks( $content ) as $block ) {
            if ( $this->block_contains_directorist( $block ) ) {
                return true;
            }
        }

        $elementor = get_post_meta( $page->ID, '_elementor_data', true );

        return is_string( $elementor ) && false !== stripos( $elementor, 'directorist' );
    }

    private function block_contains_directorist( array $block ) {
        $name = isset( $block['blockName'] ) ? strtolower( (string) $block['blockName'] ) : '';

        if ( 0 === strpos( $name, 'directorist/' ) || false !== strpos( $name, 'directorist' ) ) {
            return true;
        }

        foreach ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [] as $inner ) {
            if ( is_array( $inner ) && $this->block_contains_directorist( $inner ) ) {
                return true;
            }
        }

        return false;
    }

    private function page_route_type( \WP_Post $page ) {
        $content = strtolower( (string) $page->post_content );

        if ( false !== strpos( $content, 'search_result' ) || false !== strpos( $content, 'search-result' ) ) {
            return 'search';
        }

        if ( false !== strpos( $content, 'search_listing' ) || false !== strpos( $content, 'search-form' ) ) {
            return 'search-form';
        }

        if ( false !== strpos( $content, 'all_listing' ) || false !== strpos( $content, 'all-listing' ) ) {
            return 'listings';
        }

        return 'page';
    }

    private function has_route_type( array $resources, $route_type ) {
        foreach ( $resources as $resource ) {
            if ( isset( $resource['route_type'] ) && $route_type === $resource['route_type'] ) {
                return true;
            }
        }

        return false;
    }

    private function translation_identity( $object_id, $element_type ) {
        $post_type = 0 === strpos( $element_type, 'post_' ) ? substr( $element_type, 5 ) : get_post_type( $object_id );

        return ( new Performance_Translation_Resolver() )->post( $object_id, $post_type );
    }

    private function resolve_cache_state( array $resource ) {
        $provider_id = $this->provider_id();

        if ( 'directorist-cache' !== $provider_id ) {
            return [
                'state'    => $this->provider_available() ? 'managed' : 'unavailable',
                'exact'    => false,
                'provider' => $provider_id,
            ];
        }

        $urls     = isset( $resource['variant_urls'] ) && is_array( $resource['variant_urls'] ) ? $resource['variant_urls'] : [ $resource['url'] ];
        $storage  = new Cache_Storage( WP_CONTENT_DIR . '/cache/directorist-page-cache' );
        $states   = [];
        $priority = [ 'invalid' => 0, 'failed' => 1, 'uncached' => 2, 'invalidated' => 3, 'expired' => 4, 'stale' => 5, 'current' => 6 ];

        foreach ( $urls as $url ) {
            $key = ( new Request_Key() )->from_url( $url );

            if ( empty( $key['success'] ) ) {
                $state = [ 'state' => 'invalid' ];
            } else {
                $state = $storage->inspect( $key );
            }

            $states[] = $state;

        }

        usort(
            $states,
            static function ( array $left, array $right ) use ( $priority ) {
                $left_rank  = isset( $priority[ $left['state'] ] ) ? $priority[ $left['state'] ] : -1;
                $right_rank = isset( $priority[ $right['state'] ] ) ? $priority[ $right['state'] ] : -1;

                return $left_rank <=> $right_rank;
            }
        );
        $status             = isset( $states[0] ) ? $states[0] : [ 'state' => 'uncached' ];
        $status['exact']    = true;
        $status['provider'] = $provider_id;
        $status['variants'] = count( $urls );

        return $status;
    }

    private function resolve_variants( $url, $route_type ) {
        $variants = $this->store instanceof Performance_Resource_Store
            ? $this->store->get_variants( $url, $route_type )
            : [];

        if ( empty( $variants ) ) {
            $variants = [ [ 'url' => $url, 'language' => '' ] ];
        }

        $storage = new Cache_Storage( WP_CONTENT_DIR . '/cache/directorist-page-cache' );
        $items   = [];

        foreach ( array_slice( $variants, 0, 50 ) as $variant ) {
            $variant_url = isset( $variant['url'] ) ? esc_url_raw( (string) $variant['url'] ) : '';
            $key         = ( new Request_Key() )->from_url( $variant_url );

            if ( empty( $key['success'] ) ) {
                continue;
            }

            $state = $storage->inspect( $key );

            if ( empty( $state['success'] ) || $route_type !== ( isset( $state['route_type'] ) ? $state['route_type'] : '' ) ) {
                continue;
            }

            $state['canonical_url'] = $key['canonical_url'];
            $state['has_query']     = '' !== $key['query'];

            if ( empty( $state['language'] ) && ! empty( $variant['language'] ) ) {
                $state['language'] = sanitize_key( (string) $variant['language'] );
            }

            $items[] = $state;
        }

        return $items;
    }

    private function normalize_args( array $args ) {
        $types           = [ 'all', 'listing', 'archive', 'page', 'search' ];
        $type            = isset( $args['type'] ) && in_array( $args['type'], $types, true ) ? $args['type'] : 'all';
        $orderby         = isset( $args['orderby'] ) && in_array( $args['orderby'], [ 'modified', 'cache_state' ], true ) ? $args['orderby'] : 'id';
        $order           = isset( $args['order'] ) && 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
        $cache_states    = [ 'needs-refresh', 'uncached', 'current', 'stale', 'expired', 'invalidated', 'invalid', 'failed', 'managed', 'unavailable' ];
        $cache_state     = isset( $args['cache_state'] ) && in_array( $args['cache_state'], $cache_states, true ) ? $args['cache_state'] : '';
        $listing_filters = $this->listing_filters( $args );

        if ( array_filter( $listing_filters ) || 'modified' === $orderby ) {
            $type = 'listing';
        }

        return array_merge(
            [
                'page'        => isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1,
                'per_page'    => isset( $args['per_page'] ) ? min( self::MAX_PER_PAGE, max( 1, absint( $args['per_page'] ) ) ) : 20,
                'type'        => $type,
                'search'      => isset( $args['search'] ) ? substr( sanitize_text_field( (string) $args['search'] ), 0, 100 ) : '',
                'orderby'     => $orderby,
                'order'       => $order,
                'cache_state' => $cache_state,
            ],
            $listing_filters
        );
    }

    private function listing_filters( array $args ) {
        return [
            'directory_id' => isset( $args['directory_id'] ) ? absint( $args['directory_id'] ) : 0,
            'category_id'  => isset( $args['category_id'] ) ? absint( $args['category_id'] ) : 0,
            'location_id'  => isset( $args['location_id'] ) ? absint( $args['location_id'] ) : 0,
        ];
    }

    private function normalize_resource( $resource ) {
        if ( ! is_array( $resource ) ) {
            return [];
        }

        $id         = isset( $resource['id'] ) ? sanitize_key( (string) $resource['id'] ) : '';
        $title      = $this->normalize_title( isset( $resource['title'] ) ? $resource['title'] : '' );
        $url        = isset( $resource['url'] ) ? esc_url_raw( (string) $resource['url'] ) : '';
        $type       = isset( $resource['type'] ) ? sanitize_key( (string) $resource['type'] ) : '';
        $route_type = isset( $resource['route_type'] ) ? sanitize_key( (string) $resource['route_type'] ) : '';
        $parts      = wp_parse_url( $url );
        $home       = wp_parse_url( home_url( '/' ) );
        $path       = is_array( $parts ) && isset( $parts['path'] ) ? strtolower( $parts['path'] ) : '/';
        $object_id  = isset( $resource['object_id'] ) ? max( 0, (int) $resource['object_id'] ) : 0;

        if ( '' === $title ) {
            $title = __( '(no title)', 'directorist' );
        }

        if ( '' === $id || '' === $url || ! in_array( $type, [ 'listing', 'archive', 'page', 'search' ], true ) || '' === $route_type || ! is_array( $parts ) || ! is_array( $home ) || ! $this->is_public_url( $url ) || ! $this->is_public_post_resource( $type, $object_id ) || 0 === strpos( $path, '/wp-admin' ) || 0 === strpos( $path, '/wp-login.php' ) ) {
            return [];
        }

        $variant_urls = [];

        foreach ( isset( $resource['variant_urls'] ) && is_array( $resource['variant_urls'] ) ? $resource['variant_urls'] : [ $url ] as $variant_url ) {
            $variant_url = esc_url_raw( (string) $variant_url );

            if ( $this->is_public_url( $variant_url ) ) {
                $variant_urls[] = $variant_url;
            }
        }

        if ( empty( $variant_urls ) ) {
            $variant_urls[] = $url;
        }

        $normalized = [
            'id'           => $id,
            'title'        => $title,
            'url'          => $url,
            'type'         => $type,
            'route_type'   => $route_type,
            'object_id'    => $object_id,
            'modified_at'  => isset( $resource['modified_at'] ) ? max( 0, (int) $resource['modified_at'] ) : 0,
            'logical_key'  => isset( $resource['logical_key'] ) ? sanitize_key( (string) $resource['logical_key'] ) : $id,
            'variants'     => isset( $resource['variants'] ) ? max( 1, (int) $resource['variants'] ) : count( $variant_urls ),
            'variant_urls' => array_values( array_unique( $variant_urls ) ),
            'languages'    => isset( $resource['languages'] ) && is_array( $resource['languages'] ) ? array_values( array_unique( array_map( 'sanitize_key', $resource['languages'] ) ) ) : [],
        ];

        if ( isset( $resource['stored_cache'] ) && is_array( $resource['stored_cache'] ) ) {
            $normalized['stored_cache'] = $resource['stored_cache'];
        }

        $normalized['failures'] = [];

        foreach ( isset( $resource['failures'] ) && is_array( $resource['failures'] ) ? $resource['failures'] : [] as $failure ) {
            if ( ! is_array( $failure ) || empty( $failure['url'] ) || empty( $failure['code'] ) ) {
                continue;
            }

            $normalized['failures'][] = [
                'url'        => esc_url_raw( (string) $failure['url'] ),
                'language'   => isset( $failure['language'] ) ? sanitize_key( (string) $failure['language'] ) : '',
                'code'       => substr( sanitize_key( (string) $failure['code'] ), 0, 64 ),
                'checked_at' => isset( $failure['checked_at'] ) ? max( 0, (int) $failure['checked_at'] ) : 0,
            ];
        }

        return $normalized;
    }

    private function is_public_post_resource( $type, $object_id ) {
        if ( 1 > $object_id || ! in_array( $type, [ 'listing', 'page', 'search' ], true ) ) {
            return true;
        }

        $post = get_post( $object_id );

        if ( ! $post instanceof \WP_Post ) {
            return true;
        }

        return 'publish' === $post->post_status && ( 'listing' === $type ? ATBDP_POST_TYPE === $post->post_type : 'page' === $post->post_type );
    }

    private function normalize_title( $title ) {
        $charset = get_bloginfo( 'charset' );
        $charset = is_string( $charset ) && '' !== $charset ? $charset : 'UTF-8';

        return sanitize_text_field( html_entity_decode( (string) $title, ENT_QUOTES | ENT_HTML5, $charset ) );
    }

    private function normalize_variant( $variant ) {
        if ( ! is_array( $variant ) || empty( $variant['canonical_url'] ) || ! $this->is_public_url( $variant['canonical_url'] ) ) {
            return [];
        }

        $state = isset( $variant['state'] ) ? sanitize_key( (string) $variant['state'] ) : '';

        if ( ! in_array( $state, [ 'current', 'stale', 'expired', 'invalidated' ], true ) ) {
            return [];
        }

        return [
            'url'        => esc_url_raw( (string) $variant['canonical_url'] ),
            'state'      => $state,
            'created_at' => isset( $variant['created_at'] ) ? max( 0, (int) $variant['created_at'] ) : 0,
            'expires_at' => isset( $variant['expires_at'] ) ? max( 0, (int) $variant['expires_at'] ) : 0,
            'body_size'  => isset( $variant['body_size'] ) ? max( 0, (int) $variant['body_size'] ) : 0,
            'language'   => isset( $variant['language'] ) ? sanitize_key( (string) $variant['language'] ) : '',
            'filtered'   => ! empty( $variant['has_query'] ),
        ];
    }

    private function filter_source_result( array $result, array $args ) {
        try {
            $filtered = apply_filters( 'directorist_performance_cache_resources', $result, $args, $this );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $result;
        }

        return is_array( $filtered ) ? $filtered : $result;
    }

    private function is_public_url( $url ) {
        $parts       = wp_parse_url( esc_url_raw( (string) $url ) );
        $home        = wp_parse_url( home_url( '/' ) );
        $path        = is_array( $parts ) && isset( $parts['path'] ) ? strtolower( $parts['path'] ) : '/';
        $scheme      = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
        $home_scheme = is_array( $home ) && isset( $home['scheme'] ) ? strtolower( $home['scheme'] ) : '';
        $port        = is_array( $parts ) && isset( $parts['port'] ) ? absint( $parts['port'] ) : ( 'https' === $scheme ? 443 : 80 );
        $home_port   = is_array( $home ) && isset( $home['port'] ) ? absint( $home['port'] ) : ( 'https' === $home_scheme ? 443 : 80 );

        return is_array( $parts )
            && is_array( $home )
            && in_array( $scheme, [ 'http', 'https' ], true )
            && $scheme === $home_scheme
            && strtolower( isset( $parts['host'] ) ? $parts['host'] : '' ) === strtolower( isset( $home['host'] ) ? $home['host'] : '' )
            && $port === $home_port
            && ! isset( $parts['user'] )
            && ! isset( $parts['pass'] )
            && ! isset( $parts['fragment'] )
            && 0 !== strpos( $path, '/wp-admin' )
            && 0 !== strpos( $path, '/wp-login.php' )
            && 0 !== strpos( $path, '/wp-json' );
    }

    private function url_identity( $url ) {
        $parts = wp_parse_url( (string) $url );

        if ( ! is_array( $parts ) ) {
            return '';
        }

        $origin = strtolower( ( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) . '://' . ( isset( $parts['host'] ) ? $parts['host'] : '' ) );
        $path   = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

        return $origin . $path;
    }

    private function normalize_cache_state( $state ) {
        $state    = is_array( $state ) ? $state : [];
        $allowed  = [ 'uncached', 'current', 'stale', 'expired', 'invalidated', 'invalid', 'failed', 'managed', 'unavailable' ];
        $value    = isset( $state['state'] ) && in_array( $state['state'], $allowed, true ) ? $state['state'] : 'unavailable';
        $provider = isset( $state['provider'] ) ? sanitize_key( (string) $state['provider'] ) : $this->provider_id();

        return [
            'state'        => $value,
            'exact'        => ! empty( $state['exact'] ),
            'provider'     => $provider,
            'created_at'   => isset( $state['created_at'] ) ? max( 0, (int) $state['created_at'] ) : 0,
            'expires_at'   => isset( $state['expires_at'] ) ? max( 0, (int) $state['expires_at'] ) : 0,
            'body_size'    => isset( $state['body_size'] ) ? max( 0, (int) $state['body_size'] ) : 0,
            'variants'     => isset( $state['variants'] ) ? max( 0, (int) $state['variants'] ) : 0,
            'failure_code' => isset( $state['failure_code'] ) ? substr( sanitize_key( (string) $state['failure_code'] ), 0, 64 ) : '',
        ];
    }

    private function resource_urls( array $resource ) {
        $base_urls = isset( $resource['variant_urls'] ) && is_array( $resource['variant_urls'] ) ? $resource['variant_urls'] : [ $resource['url'] ];
        $urls      = $base_urls;
        $pages     = $this->resource_page_count( $resource );

        foreach ( $base_urls as $variant_url ) {
            for ( $page = 2; $page <= $pages; ++$page ) {
                $urls[] = $this->paged_url( $variant_url, $page );
            }
        }

        try {
            $extended = apply_filters( 'directorist_performance_cache_resource_urls', $urls, $resource, $this );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $extended = $urls;
        }

        $registry = new Warm_URL_Registry( home_url( '/' ), self::MAX_PER_PAGE * self::MAX_PAGINATED_PAGES );
        $registry->add( is_array( $extended ) ? $extended : $urls, 'performance-resource' );

        return $registry->all();
    }

    private function resource_page_count( array $resource ) {
        $total = 0;

        if ( 'archive' === $resource['type'] && ! empty( $resource['object_id'] ) ) {
            $term  = get_term( $resource['object_id'] );
            $total = $term instanceof \WP_Term ? max( 0, (int) $term->count ) : 0;
        } elseif ( 'listings' === $resource['route_type'] ) {
            $counts = wp_count_posts( ATBDP_POST_TYPE );
            $total  = isset( $counts->publish ) ? max( 0, (int) $counts->publish ) : 0;
        }

        if ( 1 > $total ) {
            return 1;
        }

        $per_page = max( 1, (int) get_directorist_option( 'all_listing_page_items', 6 ) );

        return min( self::MAX_PAGINATED_PAGES, max( 1, (int) ceil( $total / $per_page ) ) );
    }

    private function paged_url( $url, $page ) {
        if ( get_option( 'permalink_structure' ) ) {
            return user_trailingslashit( trailingslashit( $url ) . 'page/' . absint( $page ), 'paged' );
        }

        return add_query_arg( 'paged', absint( $page ), $url );
    }

    private function source_result( array $args ) {
        try {
            $result = call_user_func( $this->source, $args );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $result = [];
        }

        return $this->filter_source_result( is_array( $result ) ? $result : [], $args );
    }

    private function provider_id() {
        if ( null !== $this->resolved_provider_id ) {
            return $this->resolved_provider_id;
        }

        try {
            $provider_id = sanitize_key( (string) $this->provider->get_id() );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $provider_id = '';
        }

        $this->resolved_provider_id = '' === $provider_id ? 'unknown' : $provider_id;

        return $this->resolved_provider_id;
    }

    private function provider_available() {
        if ( null !== $this->resolved_provider_available ) {
            return $this->resolved_provider_available;
        }

        try {
            $this->resolved_provider_available = (bool) $this->provider->is_available();
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $this->resolved_provider_available = false;
        }

        return $this->resolved_provider_available;
    }

    private function provider_supports( $capability ) {
        $capability = sanitize_key( (string) $capability );

        if ( array_key_exists( $capability, $this->resolved_capabilities ) ) {
            return $this->resolved_capabilities[ $capability ];
        }

        try {
            $supported = (bool) $this->provider->supports( $capability );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $supported = false;
        }

        $this->resolved_capabilities[ $capability ] = $supported;

        return $supported;
    }
}
