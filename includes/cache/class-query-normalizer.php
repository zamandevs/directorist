<?php

namespace Directorist\Cache;

/**
 * Canonicalizes public Directorist query variation for cache identities.
 */
final class Query_Normalizer {
    /** @var Query_Schema_Resolver */
    private $schema_resolver;

    /** @var string[] */
    private $private_arguments = [
        '_wpnonce',
        'atbdp_nonce',
        'atbdp_nonce_js',
        'nonce',
        'security',
    ];

    /**
     * @param Query_Schema_Resolver|null $schema_resolver Query schema resolver.
     */
    public function __construct( Query_Schema_Resolver $schema_resolver = null ) {
        $this->schema_resolver = $schema_resolver ?: new Query_Schema_Resolver();
    }

    /**
     * @param string $route_type Directorist route type.
     * @param array  $query_args Parsed query arguments.
     * @param string $raw_query Raw query string, when available.
     * @param int[]  $directory_ids Directory IDs resolved from the route.
     * @return Query_Normalization_Result
     */
    public function normalize( $route_type, array $query_args, $raw_query = '', array $directory_ids = [] ) {
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

        $schema = $this->schema_resolver->resolve( $route_type, $query_args, $directory_ids );
        $allowlist = $schema['arguments'];

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

            if ( isset( $schema['nested_arguments'][ $name ] ) ) {
                $unsupported_key = $this->find_unsupported_nested_key( $name, $value, $schema['nested_arguments'][ $name ] );

                if ( '' !== $unsupported_key ) {
                    return new Query_Normalization_Result( false, [], 'unsupported_query_argument', $unsupported_key );
                }
            }
        }

        ksort( $normalized, SORT_STRING );

        return new Query_Normalization_Result( true, $normalized );
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

            $normalized_key = $is_list ? (int) $key : sanitize_key( (string) $key );

            if ( ! $is_list && ( '' === $normalized_key || array_key_exists( $normalized_key, $normalized ) ) ) {
                return null;
            }

            $normalized[ $normalized_key ] = $item;
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
     * @param string       $argument Top-level query argument.
     * @param mixed        $value Normalized query value.
     * @param array|true   $allowed_keys Allowed first-level nested keys.
     * @return string Unsupported query path, or an empty string.
     */
    private function find_unsupported_nested_key( $argument, $value, $allowed_keys ) {
        if ( true === $allowed_keys ) {
            return '';
        }

        if ( ! is_array( $value ) || $this->is_list( $value ) ) {
            return (string) $argument;
        }

        $allowed_keys = is_array( $allowed_keys ) ? $allowed_keys : [];

        foreach ( array_keys( $value ) as $key ) {
            if ( ! in_array( (string) $key, $allowed_keys, true ) ) {
                return sprintf( '%s[%s]', $argument, $key );
            }
        }

        return '';
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
