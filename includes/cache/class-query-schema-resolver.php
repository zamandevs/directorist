<?php

namespace Directorist\Cache;

/**
 * Resolves public query arguments from active Directory Builder search fields.
 */
final class Query_Schema_Resolver {
    /** @var array<string,array> */
    private $directory_schema_cache = [];

    /** @var string[] */
    private $collection_routes = [
        'listings',
        'search',
        'category',
        'location',
        'tag',
        'author',
        'categories',
        'locations',
        'search-form',
        'embedded',
    ];

    /** @var string[] */
    private $custom_widgets = [
        'checkbox',
        'color',
        'color_picker',
        'date',
        'number',
        'radio',
        'select',
        'text',
        'textarea',
        'time',
        'url',
    ];

    /**
     * @param string $route_type Directorist route type.
     * @param array  $query_args Parsed query arguments.
     * @param int[]  $context_directory_ids Directory IDs resolved from the route.
     * @return array{arguments:string[],nested_arguments:array,directory_ids:int[]}
     */
    public function resolve( $route_type, array $query_args = [], array $context_directory_ids = [] ) {
        $route_type = sanitize_key( (string) $route_type );
        $schema     = [
            'arguments'        => [],
            'nested_arguments' => [],
        ];

        if ( in_array( $route_type, $this->collection_routes, true ) ) {
            $schema['arguments'] = $this->universal_arguments();
        }

        $directory_ids = [];
        $dynamic_names = array_diff( array_map( 'strval', array_keys( $query_args ) ), $schema['arguments'] );

        if ( ! empty( $dynamic_names ) && in_array( $route_type, $this->collection_routes, true ) ) {
            $directory_ids = $this->resolve_directory_ids( $query_args, $context_directory_ids );

            foreach ( $directory_ids as $directory_id ) {
                $schema = $this->merge_schema( $schema, $this->directory_schema( $directory_id ) );
            }
        }

        /**
         * Filters the builder-derived query schema used in Directorist page-cache keys.
         *
         * Extensions should add arguments only when their corresponding builder field
         * or public renderer is active. Nested arguments may contain an allowed-key
         * list or boolean true when the extension validates all nested keys itself.
         *
         * @param array $schema Query schema.
         * @param string $route_type Directorist route type.
         * @param int[] $directory_ids Resolved Directory Type IDs.
         * @param array $query_args Parsed request query arguments.
         */
        $schema = apply_filters( 'directorist_page_cache_query_schema', $schema, $route_type, $directory_ids, $query_args );
        $schema = is_array( $schema ) ? $schema : [];

        $arguments = isset( $schema['arguments'] ) && is_array( $schema['arguments'] ) ? $schema['arguments'] : [];

        /**
         * Filters public top-level arguments represented in a Directorist cache key.
         *
         * @deprecated Prefer directorist_page_cache_query_schema for nested arguments.
         *
         * @param string[] $arguments Allowed argument names.
         * @param string $route_type Directorist route type.
         */
        $schema['arguments'] = apply_filters( 'directorist_page_cache_query_allowlist', $arguments, $route_type );

        return $this->normalize_schema( $schema, $directory_ids );
    }

    /** @return string[] */
    private function universal_arguments() {
        return [
            'paged',
            'page',
            'cat_id',
            'loc_id',
            'category',
            'location',
            'tag',
            'directory_type',
            'directory-type',
            'sort',
            'order',
            'view',
            'ids',
        ];
    }

    /**
     * @param array $query_args Parsed query arguments.
     * @param int[] $context_directory_ids Route-owned Directory Type IDs.
     * @return int[]
     */
    private function resolve_directory_ids( array $query_args, array $context_directory_ids ) {
        $has_explicit_directory = isset( $query_args['directory_type'] ) || isset( $query_args['directory-type'] );
        $requested              = isset( $query_args['directory_type'] ) ? $query_args['directory_type'] : ( $query_args['directory-type'] ?? '' );

        if ( $has_explicit_directory ) {
            return $this->directory_ids_from_request( $requested );
        }

        $directory_ids = $this->valid_directory_ids( $context_directory_ids );

        if ( ! empty( $directory_ids ) ) {
            return $directory_ids;
        }

        $default_directory = function_exists( 'directorist_get_default_directory' ) ? absint( directorist_get_default_directory() ) : 0;

        return $this->valid_directory_ids( [ $default_directory ] );
    }

    /**
     * @param mixed $requested Requested Directory Type identifiers.
     * @return int[]
     */
    private function directory_ids_from_request( $requested ) {
        $tokens = [];

        foreach ( (array) $requested as $value ) {
            if ( ! is_scalar( $value ) ) {
                continue;
            }

            $tokens = array_merge( $tokens, array_map( 'trim', explode( ',', (string) $value ) ) );
        }

        $tokens = array_values( array_unique( array_filter( $tokens, 'strlen' ) ) );

        if ( in_array( 'all', array_map( 'strtolower', $tokens ), true ) ) {
            $ids = get_terms(
                [
                    'taxonomy'   => ATBDP_DIRECTORY_TYPE,
                    'hide_empty' => false,
                    'fields'     => 'ids',
                ]
            );

            return is_wp_error( $ids ) ? [] : $this->valid_directory_ids( $ids );
        }

        $directory_ids = [];

        foreach ( $tokens as $token ) {
            if ( ctype_digit( $token ) ) {
                $directory_ids[] = absint( $token );
                continue;
            }

            $term = get_term_by( 'slug', sanitize_title( $token ), ATBDP_DIRECTORY_TYPE );

            if ( $term instanceof \WP_Term ) {
                $directory_ids[] = (int) $term->term_id;
            }
        }

        return $this->valid_directory_ids( $directory_ids );
    }

    /**
     * @param int[] $directory_ids Candidate Directory Type IDs.
     * @return int[]
     */
    private function valid_directory_ids( array $directory_ids ) {
        $valid = [];

        foreach ( array_unique( array_filter( array_map( 'absint', $directory_ids ) ) ) as $directory_id ) {
            $term = get_term( $directory_id, ATBDP_DIRECTORY_TYPE );

            if ( $term instanceof \WP_Term ) {
                $valid[] = $directory_id;
            }
        }

        sort( $valid, SORT_NUMERIC );

        return $valid;
    }

    /**
     * @param int $directory_id Directory Type term ID.
     * @return array
     */
    private function directory_schema( $directory_id ) {
        $search_config     = get_term_meta( $directory_id, 'search_form_fields', true );
        $submission_config = get_term_meta( $directory_id, 'submission_form_fields', true );
        $signature         = md5( maybe_serialize( [ $search_config, $submission_config ] ) );
        $cache_key         = get_current_blog_id() . ':' . $directory_id . ':' . $signature;

        if ( isset( $this->directory_schema_cache[ $cache_key ] ) ) {
            return $this->directory_schema_cache[ $cache_key ];
        }

        $schema            = [ 'arguments' => [], 'nested_arguments' => [] ];
        $search_fields     = ! empty( $search_config['fields'] ) && is_array( $search_config['fields'] ) ? $search_config['fields'] : [];
        $submission_fields = ! empty( $submission_config['fields'] ) && is_array( $submission_config['fields'] ) ? $submission_config['fields'] : [];
        $active_keys       = $this->active_search_field_keys( $search_config );

        foreach ( $active_keys as $search_field_key ) {
            if ( empty( $search_fields[ $search_field_key ] ) || ! is_array( $search_fields[ $search_field_key ] ) ) {
                continue;
            }

            $field            = $search_fields[ $search_field_key ];
            $submission_key   = ! empty( $field['original_widget_key'] ) ? (string) $field['original_widget_key'] : (string) $search_field_key;
            $submission_field = ! empty( $submission_fields[ $submission_key ] ) && is_array( $submission_fields[ $submission_key ] ) ? $submission_fields[ $submission_key ] : [];
            $field_schema     = $this->field_schema( $field, $submission_field );

            /**
             * Filters query arguments produced by one active Directory Builder field.
             *
             * The filter runs only for fields placed in Search Bar or Search Filter.
             *
             * @param array $field_schema Query schema for this field.
             * @param array $field Search form field configuration.
             * @param array $submission_field Linked submission field configuration.
             * @param int $directory_id Directory Type term ID.
             */
            $field_schema = apply_filters( 'directorist_page_cache_field_query_schema', $field_schema, $field, $submission_field, $directory_id );

            if ( is_array( $field_schema ) ) {
                $schema = $this->merge_schema( $schema, $field_schema );
            }
        }

        $this->directory_schema_cache[ $cache_key ] = $schema;

        return $schema;
    }

    /**
     * @param array $search_config Stored search builder configuration.
     * @return string[]
     */
    private function active_search_field_keys( array $search_config ) {
        $groups = ! empty( $search_config['groups'] ) && is_array( $search_config['groups'] ) ? array_slice( $search_config['groups'], 0, 2 ) : [];
        $keys   = [];

        foreach ( $groups as $group ) {
            if ( empty( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
                continue;
            }

            foreach ( $group['fields'] as $field_key ) {
                if ( is_scalar( $field_key ) && '' !== (string) $field_key ) {
                    $keys[] = (string) $field_key;
                }
            }
        }

        return array_values( array_unique( $keys ) );
    }

    /**
     * @param array $field Search field configuration.
     * @param array $submission_field Linked submission field configuration.
     * @return array
     */
    private function field_schema( array $field, array $submission_field ) {
        $schema      = [ 'arguments' => [], 'nested_arguments' => [] ];
        $widget_name = isset( $field['widget_name'] ) ? sanitize_key( (string) $field['widget_name'] ) : '';

        if ( in_array( $widget_name, $this->custom_widgets, true ) ) {
            $field_key = ! empty( $field['field_key'] ) ? $field['field_key'] : ( $submission_field['field_key'] ?? '' );
            $field_key = sanitize_key( (string) $field_key );

            if ( '' === $field_key ) {
                return $schema;
            }

            $schema['arguments'][]                        = 'custom_field';
            $schema['nested_arguments']['custom_field'][] = $field_key;

            if ( 'number' === $widget_name && 'range' === sanitize_key( (string) ( $field['type'] ?? '' ) ) ) {
                foreach ( [ 'directorist-custom-range-slider__value__min', 'directorist-custom-range-slider__value__max' ] as $argument ) {
                    $schema['arguments'][]                   = $argument;
                    $schema['nested_arguments'][ $argument ] = [ $field_key ];
                }
            }

            return $schema;
        }

        $argument_map = [
            'title'       => [ 'q' ],
            'category'    => [ 'in_cat' ],
            'tag'         => [ 'in_tag' ],
            'pricing'     => [ 'price', 'price_range' ],
            'price'       => [ 'price', 'price_range' ],
            'price_range' => [ 'price_range' ],
            'website'     => [ 'website' ],
            'email'       => [ 'email' ],
            'phone'       => [ 'phone' ],
            'phone2'      => [ 'phone2' ],
            'fax'         => [ 'fax' ],
            'address'     => [ 'address' ],
            'zip'         => [ 'zip', 'zip_cityLat', 'zip_cityLng' ],
            'review'      => [ 'search_by_rating' ],
            'rating'      => [ 'search_by_rating' ],
        ];

        if ( 'location' === $widget_name ) {
            $location_source = sanitize_key( (string) ( $field['location_source'] ?? '' ) );

            if ( 'from_listing_location' === $location_source ) {
                $schema['arguments'][] = 'in_loc';
            } elseif ( 'from_map_api' === $location_source ) {
                $schema['arguments'] = array_merge( $schema['arguments'], [ 'address', 'cityLat', 'cityLng' ] );
            } else {
                $schema['arguments'] = array_merge( $schema['arguments'], [ 'in_loc', 'address', 'cityLat', 'cityLng' ] );
            }
        } elseif ( 'radius_search' === $widget_name ) {
            $based_on            = sanitize_key( (string) ( $field['radius_search_based_on'] ?? '' ) );
            $schema['arguments'] = [ 'radius-search-based-on', 'miles' ];

            if ( 'address' === $based_on ) {
                $schema['arguments'] = array_merge( $schema['arguments'], [ 'address', 'cityLat', 'cityLng' ] );
            } elseif ( 'zip' === $based_on ) {
                $schema['arguments'] = array_merge( $schema['arguments'], [ 'zip', 'zip_cityLat', 'zip_cityLng' ] );
            } else {
                $schema['arguments'] = array_merge( $schema['arguments'], [ 'address', 'cityLat', 'cityLng', 'zip', 'zip_cityLat', 'zip_cityLng' ] );
            }
        } elseif ( isset( $argument_map[ $widget_name ] ) ) {
            $schema['arguments'] = $argument_map[ $widget_name ];
        }

        return $schema;
    }

    /**
     * @param array $base Base schema.
     * @param array $addition Schema to merge.
     * @return array
     */
    private function merge_schema( array $base, array $addition ) {
        $base_arguments    = isset( $base['arguments'] ) && is_array( $base['arguments'] ) ? $base['arguments'] : [];
        $added_arguments   = isset( $addition['arguments'] ) && is_array( $addition['arguments'] ) ? $addition['arguments'] : [];
        $base['arguments'] = array_merge( $base_arguments, $added_arguments );
        $base_nested       = isset( $base['nested_arguments'] ) && is_array( $base['nested_arguments'] ) ? $base['nested_arguments'] : [];
        $addition_nested   = isset( $addition['nested_arguments'] ) && is_array( $addition['nested_arguments'] ) ? $addition['nested_arguments'] : [];

        foreach ( $addition_nested as $argument => $allowed_keys ) {
            if ( true === $allowed_keys || ( isset( $base_nested[ $argument ] ) && true === $base_nested[ $argument ] ) ) {
                $base_nested[ $argument ] = true;
                continue;
            }

            $existing                 = isset( $base_nested[ $argument ] ) && is_array( $base_nested[ $argument ] ) ? $base_nested[ $argument ] : [];
            $base_nested[ $argument ] = array_merge( $existing, is_array( $allowed_keys ) ? $allowed_keys : [] );
        }

        $base['nested_arguments'] = $base_nested;

        return $base;
    }

    /**
     * @param array $schema Raw schema.
     * @param int[] $directory_ids Resolved Directory Type IDs.
     * @return array{arguments:string[],nested_arguments:array,directory_ids:int[]}
     */
    private function normalize_schema( array $schema, array $directory_ids ) {
        $arguments = isset( $schema['arguments'] ) && is_array( $schema['arguments'] ) ? array_map( 'strval', $schema['arguments'] ) : [];
        $arguments = array_values( array_unique( array_filter( $arguments, 'strlen' ) ) );
        $nested    = isset( $schema['nested_arguments'] ) && is_array( $schema['nested_arguments'] ) ? $schema['nested_arguments'] : [];

        foreach ( $nested as $argument => $allowed_keys ) {
            $argument = (string) $argument;

            if ( '' === $argument || ! in_array( $argument, $arguments, true ) ) {
                unset( $nested[ $argument ] );
                continue;
            }

            if ( true === $allowed_keys ) {
                continue;
            }

            $keys                = is_array( $allowed_keys ) ? array_map( 'sanitize_key', array_map( 'strval', $allowed_keys ) ) : [];
            $nested[ $argument ] = array_values( array_unique( array_filter( $keys, 'strlen' ) ) );
        }

        sort( $arguments, SORT_STRING );

        return [
            'arguments'        => $arguments,
            'nested_arguments' => $nested,
            'directory_ids'    => $directory_ids,
        ];
    }
}
