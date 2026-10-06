<?php

namespace Directorist\Cache;

/**
 * Request-local, coalesced set of completed semantic mutations.
 */
final class Change_Set {
    /** @var int */
    private $site_id;

    /** @var array<string,array> */
    private $changes = [];

    /**
     * @param int $site_id WordPress blog ID.
     */
    public function __construct( $site_id = 1 ) {
        $this->site_id = max( 1, absint( $site_id ) );
    }

    /** @return int */
    public function get_site_id() {
        return $this->site_id;
    }

    /**
     * Record or merge one completed semantic mutation.
     *
     * @param string     $type Change type.
     * @param int|string $identifier Entity identifier.
     * @param array      $context Mutation context.
     * @return bool
     */
    public function record( $type, $identifier, array $context = [] ) {
        $type = sanitize_key( (string) $type );

        if ( ! Change_Type::is_valid( $type ) ) {
            return false;
        }

        $identifier = Change_Type::normalize_identifier( $type, $identifier );

        if ( '' === (string) $identifier || 0 === $identifier ) {
            return false;
        }

        $key     = $type . ':' . $identifier;
        $context = $this->normalize_context( $context );

        if ( ! isset( $this->changes[ $key ] ) ) {
            $this->changes[ $key ] = [
                'type'       => $type,
                'identifier' => $identifier,
                'context'    => $context,
            ];
        } else {
            $this->changes[ $key ]['context'] = $this->merge_context(
                $this->changes[ $key ]['context'],
                $context
            );
        }

        return true;
    }

    /**
     * @param string     $type Change type.
     * @param int|string $identifier Entity identifier.
     * @return array
     */
    public function get( $type, $identifier ) {
        $type       = sanitize_key( (string) $type );
        $identifier = Change_Type::normalize_identifier( $type, $identifier );
        $key        = $type . ':' . $identifier;

        return isset( $this->changes[ $key ] ) ? $this->changes[ $key ] : [];
    }

    /** @return array[] */
    public function all() {
        $changes = $this->changes;
        ksort( $changes, SORT_STRING );

        return array_values( $changes );
    }

    /**
     * @param string $type Optional change type.
     * @return int
     */
    public function count( $type = '' ) {
        $type = sanitize_key( (string) $type );

        if ( '' === $type ) {
            return count( $this->changes );
        }

        return count(
            array_filter(
                $this->changes,
                static function ( $change ) use ( $type ) {
                    return $type === $change['type'];
                }
            )
        );
    }

    /** @return bool */
    public function is_empty() {
        return empty( $this->changes );
    }

    /** @return void */
    public function reset() {
        $this->changes = [];
    }

    /**
     * @param array $context Mutation context.
     * @return array
     */
    private function normalize_context( array $context ) {
        $normalized = [];

        foreach ( $context as $key => $value ) {
            $key = sanitize_key( (string) $key );

            if ( '' === $key ) {
                continue;
            }

            if ( in_array( $key, [ 'reasons', 'urls' ], true ) ) {
                $value = array_values( array_unique( array_filter( array_map( 'strval', (array) $value ) ) ) );
                sort( $value, SORT_STRING );
            } elseif ( in_array( $key, [ 'listing_ids', 'term_ids', 'directory_ids', 'author_ids', 'page_ids' ], true ) ) {
                $value = array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
                sort( $value, SORT_NUMERIC );
            } elseif ( in_array( $key, [ 'collection', 'site' ], true ) ) {
                $value = (bool) $value;
            }

            $normalized[ $key ] = $value;
        }

        ksort( $normalized, SORT_STRING );

        return $normalized;
    }

    /**
     * @param array $current Existing context.
     * @param array $incoming New context.
     * @return array
     */
    private function merge_context( array $current, array $incoming ) {
        foreach ( $incoming as $key => $value ) {
            if ( 'before' === $key && isset( $current['before'] ) ) {
                continue;
            }

            if ( 'after' === $key ) {
                $current[ $key ] = $value;
                continue;
            }

            if ( isset( $current[ $key ] ) && is_bool( $current[ $key ] ) && is_bool( $value ) ) {
                $current[ $key ] = $current[ $key ] || $value;
                continue;
            }

            if ( isset( $current[ $key ] ) && is_array( $current[ $key ] ) && is_array( $value ) && $this->is_list( $current[ $key ] ) && $this->is_list( $value ) ) {
                $current[ $key ] = $this->unique_list( array_merge( $current[ $key ], $value ) );
                continue;
            }

            $current[ $key ] = $value;
        }

        ksort( $current, SORT_STRING );

        return $current;
    }

    /**
     * @param array $values Values to deduplicate.
     * @return array
     */
    private function unique_list( array $values ) {
        $unique = [];

        foreach ( $values as $value ) {
            $key            = is_scalar( $value ) ? gettype( $value ) . ':' . (string) $value : wp_json_encode( $value );
            $unique[ $key ] = $value;
        }

        $values = array_values( $unique );

        if ( [] === $values || count( array_filter( $values, 'is_numeric' ) ) === count( $values ) ) {
            sort( $values, SORT_NUMERIC );
        } elseif ( count( array_filter( $values, 'is_string' ) ) === count( $values ) ) {
            sort( $values, SORT_STRING );
        }

        return $values;
    }

    /**
     * @param array $value Value to inspect.
     * @return bool
     */
    private function is_list( array $value ) {
        return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
    }
}
