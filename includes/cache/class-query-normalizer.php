<?php

namespace Directorist\Cache;

/**
 * Canonicalizes public Directorist query variation for cache identities.
 */
final class Query_Normalizer {
    /** @var string[] */
    private $private_arguments = [
        '_wpnonce',
        'atbdp_nonce',
        'atbdp_nonce_js',
        'nonce',
        'security',
    ];

    /**
     * @param string $route_type Directorist route type.
     * @param array  $query_args Parsed query arguments.
     * @param string $raw_query Raw query string, when available.
     * @return Query_Normalization_Result
     */
    public function normalize( $route_type, array $query_args, $raw_query = '' ) {
        $route_type = sanitize_key( (string) $route_type );

        foreach ( array_keys( $query_args ) as $name ) {
            if ( $this->is_private_argument( $name ) ) {
                return new Query_Normalization_Result( false, [], 'private_query_argument', (string) $name );
            }
        }

        $duplicate = $this->find_ambiguous_duplicate( (string) $raw_query );

        if ( '' !== $duplicate ) {
            return new Query_Normalization_Result( false, [], 'duplicate_query_argument', $duplicate );
        }

        $allowlist = $this->get_allowlist( $route_type );

        foreach ( array_keys( $query_args ) as $name ) {
            if ( ! in_array( (string) $name, $allowlist, true ) ) {
                return new Query_Normalization_Result( false, [], 'unsupported_query_argument', (string) $name );
            }
        }

        $normalized = [];

        foreach ( $query_args as $name => $value ) {
            $value = $this->normalize_value( $value, (string) $name, 0 );

            if ( null === $value ) {
                return new Query_Normalization_Result( false, [], 'invalid_query_value', (string) $name );
            }

            $normalized[ (string) $name ] = $value;
        }

        ksort( $normalized, SORT_STRING );

        return new Query_Normalization_Result( true, $normalized );
    }

    /**
     * @param string $route_type Route type.
     * @return string[]
     */
    private function get_allowlist( $route_type ) {
        $collection_routes = [
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
        $allowlist         = [];

        if ( in_array( $route_type, $collection_routes, true ) ) {
            $allowlist = [
                'paged',
                'page',
                'q',
                'in_cat',
                'in_loc',
                'in_tag',
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
                'custom_field',
                'price',
                'price_range',
                'website',
                'email',
                'phone',
                'fax',
                'miles',
                'address',
                'cityLat',
                'cityLng',
                'zip',
                'zip_cityLat',
                'zip_cityLng',
                'search_by_rating',
            ];
        }

        /**
         * Filters public query arguments represented in a Directorist cache key.
         *
         * This filter cannot make nonce/security arguments cacheable.
         *
         * @param string[] $allowlist Allowed argument names.
         * @param string   $route_type Directorist route type.
         */
        $allowlist = apply_filters( 'directorist_page_cache_query_allowlist', $allowlist, $route_type );
        $allowlist = is_array( $allowlist ) ? array_map( 'strval', $allowlist ) : [];

        return array_values( array_unique( $allowlist ) );
    }

    /**
     * @param mixed  $value Current value.
     * @param string $root_name Top-level argument name.
     * @param int    $depth Nesting depth.
     * @return array|string|null
     */
    private function normalize_value( $value, $root_name, $depth ) {
        if ( $depth > 4 ) {
            return null;
        }

        if ( is_scalar( $value ) ) {
            return trim( (string) $value );
        }

        if ( ! is_array( $value ) ) {
            return null;
        }

        $is_list    = $this->is_list( $value );
        $normalized = [];

        foreach ( $value as $key => $item ) {
            $item = $this->normalize_value( $item, $root_name, $depth + 1 );

            if ( null === $item ) {
                return null;
            }

            $normalized[ $is_list ? (int) $key : sanitize_key( (string) $key ) ] = $item;
        }

        if ( $is_list ) {
            $normalized = array_values( $normalized );

            if ( 'price' !== $root_name ) {
                sort( $normalized, SORT_STRING );
            }
        } else {
            ksort( $normalized, SORT_STRING );
        }

        return $normalized;
    }

    /**
     * @param array $value Value to inspect.
     * @return bool
     */
    private function is_list( array $value ) {
        if ( [] === $value ) {
            return true;
        }

        return array_keys( $value ) === range( 0, count( $value ) - 1 );
    }

    /**
     * @param string $name Query argument name.
     * @return bool
     */
    private function is_private_argument( $name ) {
        $name = strtolower( (string) $name );

        return in_array( $name, $this->private_arguments, true ) || false !== strpos( $name, 'nonce' );
    }

    /**
     * @param string $raw_query Raw query string.
     * @return string Duplicate scalar key, or empty string.
     */
    private function find_ambiguous_duplicate( $raw_query ) {
        if ( '' === $raw_query ) {
            return '';
        }

        $seen = [];

        foreach ( explode( '&', $raw_query ) as $pair ) {
            if ( '' === $pair ) {
                continue;
            }

            $raw_name = rawurldecode( str_replace( '+', ' ', explode( '=', $pair, 2 )[0] ) );
            $is_list  = '[]' === substr( $raw_name, -2 );
            $name     = preg_replace( '/\[\]$/', '', $raw_name );

            if ( isset( $seen[ $name ] ) && ( ! $is_list || ! $seen[ $name ] ) ) {
                return (string) $name;
            }

            $seen[ $name ] = $is_list;
        }

        return '';
    }
}
