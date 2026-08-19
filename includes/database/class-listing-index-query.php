<?php
/**
 * Guarded WP_Query integration for Directorist listing lookup tables.
 *
 * @since 8.9.0
 */

namespace Directorist\database;

use WP_Query;

defined( 'ABSPATH' ) || exit;

class Listing_Index_Query {
    private static $hooks_registered = false;

    private static $last_decision = [];

    public static function register_hooks() {
        if ( self::$hooks_registered && has_filter( 'posts_clauses', [ __CLASS__, 'apply_query_plan' ] ) ) {
            return;
        }

        self::$hooks_registered = true;
        add_filter( 'posts_clauses', [ __CLASS__, 'apply_query_plan' ], 20, 2 );
    }

    public static function prepare_args( array $args ) {
        $original_args = $args;
        $decision      = [
            'status' => 'disabled',
            'reason' => 'index_not_ready_or_disabled',
        ];

        if ( ! Listing_Index::is_enabled( $args ) ) {
            return self::record_decision( $args, $decision );
        }

        $post_types = isset( $args['post_type'] ) ? (array) $args['post_type'] : [];

        if ( [ ATBDP_POST_TYPE ] !== array_values( $post_types ) ) {
            $decision['reason'] = 'unsupported_post_type';
            return self::record_decision( $args, $decision );
        }

        if ( ! empty( $args['suppress_filters'] ) ) {
            $decision['reason'] = 'query_filters_suppressed';
            return self::record_decision( $args, $decision );
        }

        $plan = [
            'predicates'       => [],
            'field_predicates' => [],
            'geo'              => null,
            'orders'           => [],
            'converted'        => [],
            'directory_id'     => 0,
            'generation'       => 0,
            'post_status'      => '',
        ];

        $geo_requested = ! empty( $args['atbdp_geo_query'] );

        if ( $geo_requested ) {
            $plan['geo'] = self::convert_geo_query( $args['atbdp_geo_query'] );
        }

        $meta_query = ! empty( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : [];
        $relation   = isset( $meta_query['relation'] ) ? strtoupper( $meta_query['relation'] ) : 'AND';

        if ( 'AND' !== $relation ) {
            $decision['reason'] = 'unsupported_meta_relation';
            return self::record_decision( $args, $decision );
        }

        $directory_id         = self::directory_id_from_meta_query( $meta_query );
        $generation           = $directory_id ? Listing_Index_Directory_State::query_generation( $directory_id ) : 0;
        $plan['directory_id'] = $directory_id;
        $plan['generation']   = $generation;
        $remaining            = [];

        foreach ( $meta_query as $name => $clause ) {
            if ( 'relation' === $name ) {
                continue;
            }

            $predicate = self::convert_meta_clause( $clause, $directory_id );

            if ( ! $predicate ) {
                $remaining[ $name ] = $clause;
                continue;
            }

            if ( 'field' === $predicate['source'] ) {
                $plan['field_predicates'][] = $predicate;
            } else {
                $plan['predicates'][] = $predicate;
            }

            $plan['converted'][ (string) $name ] = $predicate;
        }

        $primary_meta_key = isset( $args['meta_key'] ) ? (string) $args['meta_key'] : '';

        if ( $primary_meta_key ) {
            $primary = self::convert_meta_clause(
                [
                    'key'     => $primary_meta_key,
                    'compare' => 'EXISTS',
                ],
                $directory_id
            );

            if ( $primary ) {
                $plan['predicates'][]                = $primary;
                $plan['converted']['meta_value']     = $primary;
                $plan['converted']['meta_value_num'] = $primary;
            }
        }

        if ( ! $directory_id && '_price' === $primary_meta_key && 'publish' === ( $args['post_status'] ?? '' ) ) {
            $plan['post_status'] = 'publish';
        }

        $plan['predicates'] = self::unique_predicates( $plan['predicates'] );

        if ( empty( $plan['predicates'] ) && empty( $plan['field_predicates'] ) && empty( $plan['geo'] ) ) {
            $decision['reason'] = 'no_supported_predicates';
            return self::record_decision( $args, $decision );
        }

        $order_plan = self::build_order_plan( isset( $args['orderby'] ) ? $args['orderby'] : '', $args, $plan['converted'], ! empty( $plan['geo'] ) );

        if ( false === $order_plan ) {
            $decision['reason'] = 'unsupported_meta_order';
            return self::record_decision( $args, $decision );
        }

        $plan['orders'] = $order_plan;

        $prepared_args = $args;

        if ( $primary_meta_key && isset( $plan['converted']['meta_value'] ) ) {
            unset( $prepared_args['meta_key'] );
        }

        if ( $plan['geo'] ) {
            unset( $prepared_args['atbdp_geo_query'] );
        }

        $partially_converted = $remaining || ( $geo_requested && ! $plan['geo'] );

        if ( $partially_converted ) {
            $remaining['relation']       = 'AND';
            $prepared_args['meta_query'] = $remaining;
            $decision['status']          = 'partial';
        } else {
            unset( $prepared_args['meta_query'] );
            $decision['status'] = 'optimized';
        }

        if ( $plan['orders'] ) {
            $prepared_args['orderby'] = 'none';
        }

        $plan = apply_filters( 'directorist_listing_index_query_plan', $plan, $prepared_args );

        if ( ! self::plan_is_executable( $plan ) ) {
            $decision['status'] = 'fallback';
            $decision['reason'] = 'plan_rejected';
            return self::record_decision( $original_args, $decision );
        }

        $prepared_args['directorist_listing_index_plan']       = $plan;
        $prepared_args['directorist_listing_index_generation'] = $directory_id . ':' . $generation;
        $decision['reason']                                    = $partially_converted ? 'supported_clauses_only' : 'all_meta_clauses_supported';
        $decision['converted']                                 = array_keys( $plan['converted'] );

        return self::record_decision( $prepared_args, $decision );
    }

    public static function apply_query_plan( array $clauses, WP_Query $query ) {
        $plan = $query->get( 'directorist_listing_index_plan' );

        if ( ! is_array( $plan ) ) {
            return $clauses;
        }

        global $wpdb;

        $listing_table    = Listing_Index_Schema::listing_table();
        $clauses['join'] .= " INNER JOIN {$listing_table} dli ON dli.listing_id = {$wpdb->posts}.ID";

        if ( ! empty( $plan['post_status'] ) ) {
            $clauses['where'] .= $wpdb->prepare( ' AND dli.post_status = %s', $plan['post_status'] );
        }

        foreach ( $plan['predicates'] as $predicate ) {
            $sql = self::predicate_sql( $predicate, 'dli' );

            if ( $sql ) {
                $clauses['where'] .= ' AND ' . $sql;
            }
        }

        foreach ( $plan['field_predicates'] as $position => $predicate ) {
            $alias = 'dlfi' . (int) $position;
            $sql   = self::field_predicate_sql( $predicate, $alias );

            if ( $sql ) {
                $clauses['where'] .= ' AND ' . $sql;
            }
        }

        $distance_sql = '';

        if ( ! empty( $plan['geo'] ) ) {
            $geo_sql = self::geo_sql( $plan['geo'] );

            if ( $geo_sql ) {
                $clauses['where'] .= ' AND ' . $geo_sql['where'];
                $distance_sql      = $geo_sql['distance'];
            }
        }

        if ( ! empty( $plan['orders'] ) ) {
            $orders             = array_map(
                static function( $order ) use ( $distance_sql ) {
                    return str_replace( '__directorist_distance__', $distance_sql, $order );
                },
                $plan['orders']
            );
            $clauses['orderby'] = implode( ', ', $orders );
        }

        return apply_filters( 'directorist_listing_index_sql_clauses', $clauses, $query, $plan );
    }

    public static function get_last_decision() {
        return self::$last_decision;
    }

    private static function plan_is_executable( $plan ) {
        if ( ! is_array( $plan ) ) {
            return false;
        }

        foreach ( [ 'predicates', 'field_predicates', 'orders', 'converted' ] as $key ) {
            if ( ! isset( $plan[ $key ] ) || ! is_array( $plan[ $key ] ) ) {
                return false;
            }
        }

        $has_constraint = false;

        foreach ( $plan['predicates'] as $predicate ) {
            if ( ! self::core_predicate_is_executable( $predicate ) ) {
                return false;
            }

            $has_constraint = true;
        }

        foreach ( $plan['field_predicates'] as $position => $predicate ) {
            if ( ! self::field_predicate_is_executable( $predicate, 'dlfi' . (int) $position ) ) {
                return false;
            }

            $has_constraint = true;
        }

        $geo = $plan['geo'] ?? null;

        if ( null !== $geo ) {
            if ( ! self::geo_is_executable( $geo ) ) {
                return false;
            }

            $has_constraint = true;
        }

        foreach ( $plan['orders'] as $order ) {
            if ( ! is_string( $order ) || '' === trim( $order ) || ( false !== strpos( $order, '__directorist_distance__' ) && null === $geo ) ) {
                return false;
            }
        }

        return $has_constraint;
    }

    private static function core_predicate_is_executable( $predicate ) {
        if ( ! is_array( $predicate ) || 'core' !== ( $predicate['source'] ?? '' ) ) {
            return false;
        }

        if ( ! self::array_has_keys( $predicate, [ 'meta_key', 'column', 'presence_column', 'compare', 'cast' ] ) ) {
            return false;
        }

        $mapping = self::core_meta_mapping( $predicate['meta_key'] );

        if ( ! $mapping || Listing_Index::is_core_meta_ambiguous( $predicate['meta_key'] ) || ! self::operator_supported( $predicate['compare'], $mapping['type'] ) ) {
            return false;
        }

        if ( ! is_string( $predicate['cast'] ) || self::query_cast( $predicate['cast'] ) !== $predicate['cast'] ) {
            return false;
        }

        $uses_signed_column = ! empty( $mapping['signed_column'] ) && $mapping['signed_column'] === $predicate['column'];

        if ( $mapping['presence_column'] !== $predicate['presence_column'] || ( $mapping['column'] !== $predicate['column'] && ! $uses_signed_column ) ) {
            return false;
        }

        if ( 'EXISTS' !== $predicate['compare'] ) {
            if ( ! self::array_has_keys( $predicate, [ 'value', 'value_type' ] ) || ! self::comparison_value_supported( $predicate['compare'], $predicate['value'] ) ) {
                return false;
            }

            if ( $uses_signed_column ) {
                if ( '' !== $predicate['cast'] || $mapping['type'] !== $predicate['value_type'] ) {
                    return false;
                }
            } elseif ( ! self::core_cast_supported( $mapping, $predicate['cast'] ) || self::value_type_for_cast( $mapping['type'], $predicate['cast'] ) !== $predicate['value_type'] ) {
                return false;
            }
        }

        return '' !== self::predicate_sql( $predicate, 'dli' );
    }

    private static function field_predicate_is_executable( $predicate, $alias ) {
        if ( ! is_array( $predicate ) || 'field' !== ( $predicate['source'] ?? '' ) ) {
            return false;
        }

        if ( ! self::array_has_keys( $predicate, [ 'match_mode', 'meta_key', 'field_type', 'directory_id', 'generation' ] ) ) {
            return false;
        }

        if ( ! is_string( $predicate['meta_key'] ) || '' === $predicate['meta_key'] || 1 > (int) $predicate['directory_id'] || 1 > (int) $predicate['generation'] ) {
            return false;
        }

        $directory_id = (int) $predicate['directory_id'];
        $generation   = (int) $predicate['generation'];
        $manifest     = Listing_Index_Directory_State::active_manifest( $directory_id );

        if ( $generation !== Listing_Index_Directory_State::query_generation( $directory_id ) || empty( $manifest[ $predicate['meta_key'] ] ) || $manifest[ $predicate['meta_key'] ]['field_type'] !== $predicate['field_type'] ) {
            return false;
        }

        if ( 'fulltext' === $predicate['match_mode'] ) {
            if ( ! in_array( $predicate['field_type'], [ 'text', 'textarea', 'url' ], true ) ) {
                return false;
            }

            if ( 'MATCH' !== ( $predicate['compare'] ?? '' ) || ! isset( $predicate['value'] ) || ! is_scalar( $predicate['value'] ) || '' === (string) $predicate['value'] ) {
                return false;
            }

            return '' !== self::field_predicate_sql( $predicate, $alias );
        }

        if ( ! self::array_has_keys( $predicate, [ 'value_type', 'cast', 'compare', 'value' ] ) ) {
            return false;
        }

        if ( ! is_string( $predicate['cast'] ) || self::query_cast( $predicate['cast'] ) !== $predicate['cast'] || ! self::comparison_value_supported( $predicate['compare'], $predicate['value'] ) ) {
            return false;
        }

        if ( ! self::custom_field_operator_supported( $predicate['compare'], $predicate['field_type'] ) ) {
            return false;
        }

        if ( 'string' === $predicate['value_type'] && ! self::indexable_string_values( $predicate['value'] ) ) {
            return false;
        }

        $valid_field = false;

        if ( in_array( $predicate['field_type'], [ 'select', 'radio', 'switch', 'time' ], true ) ) {
            $valid_field = 'comparison' === $predicate['match_mode'] && 'string' === $predicate['value_type'];
        } elseif ( 'checkbox' === $predicate['field_type'] ) {
            $valid_field = 'membership' === $predicate['match_mode'] && 'string' === $predicate['value_type'];
        } elseif ( 'number' === $predicate['field_type'] ) {
            $valid_field = 'comparison' === $predicate['match_mode'] && in_array( $predicate['value_type'], [ 'string', 'number' ], true );
        } elseif ( 'date' === $predicate['field_type'] ) {
            $valid_field = 'comparison' === $predicate['match_mode'] && in_array( $predicate['value_type'], [ 'string', 'date', 'datetime' ], true );
        }

        return $valid_field && '' !== self::field_predicate_sql( $predicate, $alias );
    }

    private static function geo_is_executable( $geo ) {
        if ( ! is_array( $geo ) || ! self::array_has_keys( $geo, [ 'latitude', 'longitude', 'min_distance', 'max_distance', 'radius' ] ) ) {
            return false;
        }

        foreach ( [ 'latitude', 'longitude', 'min_distance', 'max_distance', 'radius' ] as $key ) {
            if ( ! is_numeric( $geo[ $key ] ) ) {
                return false;
            }
        }

        if ( 0 >= (float) $geo['radius'] || 0 > (float) $geo['min_distance'] || (float) $geo['max_distance'] < (float) $geo['min_distance'] ) {
            return false;
        }

        return (bool) self::geo_sql( $geo );
    }

    private static function array_has_keys( array $value, array $keys ) {
        return ! array_diff_key( array_flip( $keys ), $value );
    }

    private static function indexable_string_values( $value ) {
        foreach ( is_array( $value ) ? $value : [ $value ] as $item ) {
            if ( ! is_scalar( $item ) || 191 < strlen( (string) $item ) ) {
                return false;
            }
        }

        return true;
    }

    private static function convert_meta_clause( $clause, $directory_id ) {
        if ( ! is_array( $clause ) ) {
            return null;
        }

        if ( empty( $clause['key'] ) ) {
            return self::convert_custom_field_group( $clause, $directory_id );
        }

        $meta_key  = (string) $clause['key'];
        $has_value = array_key_exists( 'value', $clause );
        $value     = $has_value ? $clause['value'] : null;
        $compare   = isset( $clause['compare'] ) ? strtoupper( $clause['compare'] ) : ( $has_value && is_array( $value ) ? 'IN' : '=' );
        $mapping   = self::core_meta_mapping( $meta_key );
        $cast      = self::query_cast( $clause['type'] ?? '' );

        if ( ! $has_value ) {
            $compare = 'EXISTS';
        } elseif ( 'EXISTS' === $compare ) {
            $compare = '=';
        }

        if ( $mapping && Listing_Index::is_core_meta_ambiguous( $meta_key ) ) {
            return null;
        }

        if ( $mapping && self::operator_supported( $compare, $mapping['type'] ) ) {
            if ( ! self::comparison_value_supported( $compare, $value ) ) {
                return null;
            }

            if ( 'EXISTS' !== $compare && ! self::core_cast_supported( $mapping, $cast ) ) {
                return null;
            }

            $column         = $mapping['column'];
            $predicate_cast = $cast;

            if ( 'SIGNED' === $cast && ! empty( $mapping['signed_column'] ) ) {
                $column         = $mapping['signed_column'];
                $predicate_cast = '';
            }

            return [
                'source'          => 'core',
                'meta_key'        => $meta_key,
                'column'          => $column,
                'presence_column' => $mapping['presence_column'],
                'value_type'      => self::value_type_for_cast( $mapping['type'], $cast ),
                'cast'            => $predicate_cast,
                'compare'         => $compare,
                'value'           => $value,
            ];
        }

        if ( ! $directory_id ) {
            return null;
        }

        if ( Listing_Index::is_core_meta_ambiguous( '_directory_type' ) ) {
            return null;
        }

        $generation = Listing_Index_Directory_State::query_generation( $directory_id );

        if ( ! $generation ) {
            return null;
        }

        $definitions = Listing_Index_Directory_State::active_manifest( $directory_id );

        if ( empty( $definitions[ $meta_key ] ) ) {
            return null;
        }

        $field_type = $definitions[ $meta_key ]['field_type'];
        $value_type = 'string';
        $match_mode = isset( $clause['directorist_index_match'] ) ? sanitize_key( $clause['directorist_index_match'] ) : '';

        if ( 'checkbox' === $field_type && 'membership' === $match_mode && 'LIKE' === $compare ) {
            $compare = is_array( $value ) ? 'IN' : '=';
        } elseif ( in_array( $field_type, [ 'text', 'textarea', 'url' ], true ) && 'fulltext' === $match_mode && 'LIKE' === $compare && is_scalar( $value ) ) {
            $search_query = Listing_Index_Text::boolean_query( $directory_id, $generation, $meta_key, $value );

            if ( ! $search_query ) {
                return null;
            }

            return [
                'source'       => 'field',
                'match_mode'   => 'fulltext',
                'meta_key'     => $meta_key,
                'field_type'   => $field_type,
                'value_type'   => 'string',
                'cast'         => '',
                'compare'      => 'MATCH',
                'value'        => $search_query,
                'directory_id' => (int) $directory_id,
                'generation'   => $generation,
            ];
        } elseif ( in_array( $field_type, [ 'checkbox', 'text', 'textarea', 'url' ], true ) ) {
            return null;
        }

        if ( ! self::comparison_value_supported( $compare, $value ) ) {
            return null;
        }

        if ( 'number' === $field_type && self::is_numeric_cast( $cast ) ) {
            $value_type = 'number';
        } elseif ( 'date' === $field_type && in_array( $cast, [ 'DATE', 'DATETIME' ], true ) ) {
            $value_type = strtolower( $cast );
        } elseif ( $cast && 'CHAR' !== $cast ) {
            return null;
        }

        if ( ! self::custom_field_operator_supported( $compare, $field_type ) ) {
            return null;
        }

        $values = is_array( $value ) ? $value : [ $value ];

        $unindexable_values = array_filter(
            $values,
            static function( $item ) {
                return ! is_scalar( $item ) || 191 < strlen( (string) $item );
            }
        );

        if ( 'string' === $value_type && $unindexable_values ) {
            return null;
        }

        return [
            'source'       => 'field',
            'match_mode'   => 'checkbox' === $field_type ? 'membership' : 'comparison',
            'meta_key'     => $meta_key,
            'field_type'   => $field_type,
            'value_type'   => $value_type,
            'cast'         => $cast,
            'compare'      => $compare,
            'value'        => $value,
            'directory_id' => (int) $directory_id,
            'generation'   => $generation,
        ];
    }

    private static function core_meta_mapping( $meta_key ) {
        $rating_key = function_exists( 'directorist_get_rating_field_meta_key' ) ? directorist_get_rating_field_meta_key() : '_directorist_listing_rating';
        $views_key  = function_exists( 'directorist_get_listing_views_count_meta_key' ) ? directorist_get_listing_views_count_meta_key() : '_atbdp_post_views_count';
        $mappings   = [
            '_directory_type' => [ 'column' => 'directory_id', 'presence_column' => 'directory_set', 'type' => 'integer', 'untyped_safe' => true ],
            '_featured'       => [ 'column' => 'featured', 'presence_column' => 'featured_set', 'type' => 'integer', 'untyped_safe' => true ],
            '_price'          => [ 'column' => 'price', 'signed_column' => 'price_signed', 'presence_column' => 'price_set', 'type' => 'number' ],
            '_price_range'    => [ 'column' => 'price_range', 'presence_column' => 'price_range_set', 'type' => 'string' ],
            '_expiry_date'    => [ 'column' => 'expiry_date', 'presence_column' => 'expiry_date_set', 'type' => 'date' ],
            '_never_expire'   => [ 'column' => 'never_expire', 'presence_column' => 'never_expire_set', 'type' => 'integer', 'untyped_safe' => true ],
            $rating_key       => [ 'column' => 'rating', 'signed_column' => 'rating_signed', 'presence_column' => 'rating_set', 'type' => 'number' ],
            $views_key        => [ 'column' => 'view_count', 'signed_column' => 'view_count_signed', 'presence_column' => 'view_count_set', 'type' => 'integer' ],
        ];

        return isset( $mappings[ $meta_key ] ) ? $mappings[ $meta_key ] : null;
    }

    private static function core_cast_supported( array $mapping, $cast ) {
        if ( ! $cast ) {
            return 'string' === $mapping['type'] || ! empty( $mapping['untyped_safe'] );
        }

        if ( 'string' === $mapping['type'] ) {
            return 'CHAR' === $cast;
        }

        if ( 'date' === $mapping['type'] ) {
            return in_array( $cast, [ 'DATE', 'DATETIME' ], true );
        }

        return self::is_numeric_cast( $cast );
    }

    private static function query_cast( $type ) {
        $cast = strtoupper( trim( (string) $type ) );

        if ( ! $cast ) {
            return '';
        }

        if ( ! preg_match( '/^(?:BINARY|CHAR|DATE|DATETIME|SIGNED|UNSIGNED|TIME|NUMERIC(?:\(\d+(?:,\s?\d+)?\))?|DECIMAL(?:\(\d+(?:,\s?\d+)?\))?)$/', $cast ) ) {
            return 'CHAR';
        }

        return 'NUMERIC' === $cast ? 'SIGNED' : $cast;
    }

    private static function is_numeric_cast( $cast ) {
        return (bool) preg_match( '/^(?:SIGNED|NUMERIC(?:\(|$)|DECIMAL(?:\(|$))/', $cast );
    }

    private static function value_type_for_cast( $default, $cast ) {
        if ( in_array( $cast, [ 'DATE', 'DATETIME' ], true ) ) {
            return strtolower( $cast );
        }

        return $default;
    }

    private static function operator_supported( $compare, $type ) {
        $operators = [ '=', 'IN', 'BETWEEN', '>', '>=', '<', '<=', 'EXISTS' ];

        if ( 'string' === $type ) {
            $operators = [ '=', 'IN', 'EXISTS' ];
        }

        return in_array( $compare, $operators, true );
    }

    private static function unique_predicates( array $predicates ) {
        $unique = [];

        foreach ( $predicates as $predicate ) {
            $key            = md5( serialize( $predicate ) );
            $unique[ $key ] = $predicate;
        }

        return array_values( $unique );
    }

    private static function custom_field_operator_supported( $compare, $field_type ) {
        if ( in_array( $field_type, [ 'select', 'radio', 'switch', 'time' ], true ) ) {
            return in_array( $compare, [ '=', 'IN' ], true );
        }

        if ( in_array( $field_type, [ 'number', 'date' ], true ) ) {
            return in_array( $compare, [ '=', 'IN', 'BETWEEN', '>', '>=', '<', '<=' ], true );
        }

        if ( 'checkbox' === $field_type ) {
            return in_array( $compare, [ '=', 'IN' ], true );
        }

        return false;
    }

    private static function comparison_value_supported( $compare, $value ) {
        if ( 'EXISTS' === $compare ) {
            return true;
        }

        if ( 'IN' === $compare ) {
            if ( ! is_array( $value ) || ! $value ) {
                return false;
            }

            foreach ( $value as $item ) {
                if ( ! is_scalar( $item ) ) {
                    return false;
                }
            }

            return true;
        }

        if ( 'BETWEEN' === $compare ) {
            return is_array( $value ) && 2 === count( $value ) && is_scalar( $value[0] ) && is_scalar( $value[1] );
        }

        return is_scalar( $value );
    }

    private static function convert_custom_field_group( array $group, $directory_id ) {
        $relation = isset( $group['relation'] ) ? strtoupper( $group['relation'] ) : 'AND';

        if ( 'OR' !== $relation ) {
            return null;
        }

        $predicates = [];

        foreach ( $group as $name => $clause ) {
            if ( 'relation' === $name ) {
                continue;
            }

            $predicate = self::convert_meta_clause( $clause, $directory_id );

            if ( ! $predicate || 'membership' !== ( $predicate['match_mode'] ?? '' ) || is_array( $predicate['value'] ) ) {
                return null;
            }

            $predicates[] = $predicate;
        }

        if ( ! $predicates ) {
            return null;
        }

        $first = reset( $predicates );

        foreach ( $predicates as $predicate ) {
            if ( $predicate['meta_key'] !== $first['meta_key'] || $predicate['directory_id'] !== $first['directory_id'] || $predicate['generation'] !== $first['generation'] ) {
                return null;
            }
        }

        $first['compare'] = 'IN';
        $first['value']   = array_values( array_unique( wp_list_pluck( $predicates, 'value' ), SORT_REGULAR ) );

        return $first;
    }

    private static function directory_id_from_meta_query( array $meta_query ) {
        foreach ( $meta_query as $clause ) {
            if ( ! is_array( $clause ) || empty( $clause['key'] ) || '_directory_type' !== $clause['key'] ) {
                continue;
            }

            $compare = ! empty( $clause['compare'] ) ? strtoupper( $clause['compare'] ) : '=';

            if ( '=' === $compare && ! empty( $clause['value'] ) && is_scalar( $clause['value'] ) ) {
                return (int) $clause['value'];
            }
        }

        return 0;
    }

    private static function build_order_plan( $orderby, array $args, array $converted, $has_geo = false ) {
        if ( empty( $orderby ) || 'none' === $orderby ) {
            return [];
        }

        $default_order = ! empty( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
        $items         = is_array( $orderby ) ? $orderby : self::parse_order_string( $orderby, $default_order );
        $uses_lookup   = false;
        $orders        = [];

        foreach ( array_keys( $items ) as $key ) {
            if ( isset( $converted[ (string) $key ] ) || ( $has_geo && 'distance' === $key ) ) {
                $uses_lookup = true;
                break;
            }
        }

        if ( ! $uses_lookup ) {
            return [];
        }

        foreach ( $items as $key => $direction ) {
            $direction = 'ASC' === strtoupper( $direction ) ? 'ASC' : 'DESC';

            if ( isset( $converted[ (string) $key ] ) ) {
                if ( 'core' !== $converted[ (string) $key ]['source'] || empty( $converted[ (string) $key ]['column'] ) ) {
                    return false;
                }

                $column   = self::cast_column( 'dli.' . $converted[ (string) $key ]['column'], $converted[ (string) $key ]['cast'] ?? '' );
                $orders[] = $column . ' ' . $direction;
                continue;
            }

            if ( $has_geo && 'distance' === $key ) {
                $orders[] = '__directorist_distance__ ' . $direction;
                continue;
            }

            $regular = self::regular_order_sql( $key, $direction );

            if ( ! $regular ) {
                return false;
            }

            $orders[] = $regular;
        }

        return $orders;
    }

    private static function parse_order_string( $orderby, $direction ) {
        $items = [];

        foreach ( preg_split( '/\s+/', trim( (string) $orderby ) ) as $item ) {
            if ( '' !== $item ) {
                $items[ $item ] = $direction;
            }
        }

        return $items;
    }

    private static function regular_order_sql( $key, $direction ) {
        global $wpdb;

        $mapping = [
            'ID'         => "{$wpdb->posts}.ID",
            'id'         => "{$wpdb->posts}.ID",
            'author'     => "{$wpdb->posts}.post_author",
            'title'      => "{$wpdb->posts}.post_title",
            'name'       => "{$wpdb->posts}.post_name",
            'date'       => "{$wpdb->posts}.post_date",
            'modified'   => "{$wpdb->posts}.post_modified",
            'menu_order' => "{$wpdb->posts}.menu_order",
            'rand'       => 'RAND()',
        ];

        return isset( $mapping[ $key ] ) ? $mapping[ $key ] . ( 'rand' === $key ? '' : ' ' . $direction ) : null;
    }

    private static function predicate_sql( array $predicate, $alias ) {
        $allowed_columns = [
            'directory_id', 'directory_set', 'featured', 'featured_set', 'price', 'price_signed', 'price_set', 'price_range',
            'price_range_set', 'expiry_date', 'expiry_date_set', 'never_expire', 'never_expire_set', 'rating', 'rating_signed',
            'rating_set', 'view_count', 'view_count_signed', 'view_count_set',
        ];

        if ( ! in_array( $predicate['column'], $allowed_columns, true ) ) {
            return '';
        }

        if ( 'EXISTS' === $predicate['compare'] ) {
            if ( $predicate['presence_column'] && in_array( $predicate['presence_column'], $allowed_columns, true ) ) {
                return $alias . '.' . $predicate['presence_column'] . ' = 1';
            }

            return $alias . '.' . $predicate['column'] . ' IS NOT NULL';
        }

        $column     = self::cast_column( $alias . '.' . $predicate['column'], $predicate['cast'] ?? '' );
        $comparison = self::comparison_sql( $column, $predicate );

        if ( $comparison && $predicate['presence_column'] && in_array( $predicate['presence_column'], $allowed_columns, true ) ) {
            return '( ' . $alias . '.' . $predicate['presence_column'] . ' = 1 AND ' . $comparison . ' )';
        }

        return $comparison;
    }

    private static function field_predicate_sql( array $predicate, $alias ) {
        global $wpdb;

        if ( 'fulltext' === ( $predicate['match_mode'] ?? '' ) ) {
            $field_table = Listing_Index_Schema::text_field_table();
            $comparison  = $wpdb->prepare(
                "MATCH({$alias}.search_document) AGAINST (%s IN BOOLEAN MODE)",
                $predicate['value']
            );

            return $wpdb->prepare(
                "EXISTS (SELECT 1 FROM {$field_table} {$alias} WHERE {$alias}.listing_id = dli.listing_id AND {$alias}.directory_id = %d AND {$alias}.generation = %d AND {$alias}.field_key = %s AND {$comparison})",
                $predicate['directory_id'],
                $predicate['generation'],
                $predicate['meta_key']
            );
        }

        $value_column = 'value_string';
        $field_table  = Listing_Index_Schema::field_table();

        if ( 'number' === $predicate['field_type'] ) {
            $field_table = Listing_Index_Schema::number_field_table();

            if ( 'number' === $predicate['value_type'] ) {
                $value_column = 'SIGNED' === ( $predicate['cast'] ?? '' ) ? 'value_signed' : 'value_num';
            }
        } elseif ( 'date' === $predicate['field_type'] ) {
            $field_table = Listing_Index_Schema::date_field_table();

            if ( in_array( $predicate['value_type'], [ 'date', 'datetime' ], true ) ) {
                $value_column = 'value_date';
            }
        }

        $cast       = 'value_signed' === $value_column ? '' : ( $predicate['cast'] ?? '' );
        $column     = self::cast_column( $alias . '.' . $value_column, $cast );
        $comparison = self::comparison_sql( $column, $predicate );

        if ( ! $comparison ) {
            return '';
        }

        return $wpdb->prepare(
            "EXISTS (SELECT 1 FROM {$field_table} {$alias} WHERE {$alias}.listing_id = dli.listing_id AND {$alias}.directory_id = %d AND {$alias}.generation = %d AND {$alias}.field_key = %s AND {$comparison})",
            $predicate['directory_id'],
            $predicate['generation'],
            $predicate['meta_key']
        );
    }

    private static function convert_geo_query( $geo_query ) {
        if ( ! is_array( $geo_query ) ) {
            return null;
        }

        $lat_field = ! empty( $geo_query['lat_field'] ) ? $geo_query['lat_field'] : '_manual_lat';
        $lng_field = ! empty( $geo_query['lng_field'] ) ? $geo_query['lng_field'] : '_manual_lng';

        if ( '_manual_lat' !== $lat_field || '_manual_lng' !== $lng_field ) {
            return null;
        }

        if ( Listing_Index::is_core_meta_ambiguous( '_manual_lat' ) || Listing_Index::is_core_meta_ambiguous( '_manual_lng' ) ) {
            return null;
        }

        if ( ! isset( $geo_query['latitude'], $geo_query['longitude'] ) || ! is_numeric( $geo_query['latitude'] ) || ! is_numeric( $geo_query['longitude'] ) ) {
            return null;
        }

        $units        = ! empty( $geo_query['units'] ) ? strtolower( $geo_query['units'] ) : 'miles';
        $min_distance = isset( $geo_query['min_distance'] ) ? max( 0, (float) $geo_query['min_distance'] ) : 0;
        $max_distance = isset( $geo_query['max_distance'] ) ? max( 0, (float) $geo_query['max_distance'] ) : 100;

        if ( $max_distance < $min_distance ) {
            return null;
        }

        return [
            'latitude'     => (float) $geo_query['latitude'],
            'longitude'    => (float) $geo_query['longitude'],
            'min_distance' => $min_distance,
            'max_distance' => $max_distance,
            'radius'       => in_array( $units, [ 'km', 'kilometers' ], true ) ? 6371 : 3959,
        ];
    }

    private static function geo_sql( array $geo ) {
        global $wpdb;

        $latitude        = $geo['latitude'];
        $longitude       = $geo['longitude'];
        $radius          = $geo['radius'];
        $latitude_delta  = rad2deg( $geo['max_distance'] / $radius );
        $longitude_scale = max( 0.01, abs( cos( deg2rad( $latitude ) ) ) );
        $longitude_delta = $latitude_delta / $longitude_scale;

        $distance = $wpdb->prepare(
            '( %f * ACOS( LEAST( 1, GREATEST( -1, COS( RADIANS(%f) ) * COS( RADIANS(dli.latitude) ) * COS( RADIANS(dli.longitude) - RADIANS(%f) ) + SIN( RADIANS(%f) ) * SIN( RADIANS(dli.latitude) ) ) ) ) )',
            $radius,
            $latitude,
            $longitude,
            $latitude
        );
        $where    = $wpdb->prepare(
            '( dli.latitude IS NOT NULL AND dli.longitude IS NOT NULL AND dli.latitude BETWEEN %f AND %f AND dli.longitude BETWEEN %f AND %f AND ' . $distance . ' BETWEEN %f AND %f )',
            $latitude - $latitude_delta,
            $latitude + $latitude_delta,
            $longitude - $longitude_delta,
            $longitude + $longitude_delta,
            $geo['min_distance'],
            $geo['max_distance']
        );

        return [
            'distance' => $distance,
            'where'    => $where,
        ];
    }

    private static function comparison_sql( $column, array $predicate ) {
        global $wpdb;

        $compare = $predicate['compare'];
        $value   = self::normalize_query_value( $predicate['value'], $predicate['value_type'], $compare );
        $format  = 'number' === $predicate['value_type'] ? '%f' : ( 'integer' === $predicate['value_type'] ? '%d' : '%s' );

        if ( 'IN' === $compare ) {
            $values = is_array( $value ) ? array_values( $value ) : [ $value ];

            if ( ! $values ) {
                return '1 = 0';
            }

            $placeholders = implode( ', ', array_fill( 0, count( $values ), $format ) );
            return $wpdb->prepare( "{$column} IN ({$placeholders})", $values );
        }

        if ( 'BETWEEN' === $compare ) {
            $values = is_array( $value ) ? array_values( $value ) : [];

            if ( 2 !== count( $values ) ) {
                return '';
            }

            return $wpdb->prepare( "{$column} BETWEEN {$format} AND {$format}", $values[0], $values[1] );
        }

        if ( ! in_array( $compare, [ '=', '>', '>=', '<', '<=' ], true ) || is_array( $value ) ) {
            return '';
        }

        return $wpdb->prepare( "{$column} {$compare} {$format}", $value );
    }

    private static function cast_column( $column, $cast ) {
        if ( ! $cast || 'CHAR' === $cast ) {
            return $column;
        }

        return "CAST({$column} AS {$cast})";
    }

    private static function normalize_query_value( $value, $type, $compare = '=' ) {
        if ( is_array( $value ) ) {
            return array_map(
                static function( $item ) use ( $type, $compare ) {
                    return self::normalize_query_value( $item, $type, $compare );
                },
                $value
            );
        }

        if ( 'integer' === $type ) {
            return (int) $value;
        }

        if ( 'number' === $type ) {
            return (float) $value;
        }

        if ( 'date' === $type ) {
            $timestamp = strtotime( (string) $value );
            return false === $timestamp ? (string) $value : gmdate( 'Y-m-d', $timestamp );
        }

        if ( 'datetime' === $type ) {
            $timestamp = strtotime( (string) $value );
            return false === $timestamp ? (string) $value : gmdate( 'Y-m-d H:i:s', $timestamp );
        }

        $value = (string) $value;

        return in_array( $compare, [ 'IN', 'BETWEEN' ], true ) ? $value : trim( $value );
    }

    private static function record_decision( array $args, array $decision ) {
        self::$last_decision                        = apply_filters( 'directorist_listing_index_planner_decision', $decision, $args );
        $args['directorist_listing_index_decision'] = self::$last_decision;

        return $args;
    }
}
