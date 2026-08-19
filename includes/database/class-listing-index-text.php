<?php
/**
 * Deterministic FULLTEXT tokens for indexed Directorist fields.
 *
 * @since 8.9.0
 */

namespace Directorist\database;

defined( 'ABSPATH' ) || exit;

class Listing_Index_Text {
    const SCOPE_PREFIX = 'ds';

    const TERM_PREFIX = 'dt';

    public static function scope_token( $directory_id, $generation, $field_key ) {
        return self::SCOPE_PREFIX . md5( (int) $directory_id . "\0" . (int) $generation . "\0" . (string) $field_key );
    }

    public static function document( $directory_id, $generation, $field_key, $value ) {
        return implode( ' ', self::scoped_term_tokens( $directory_id, $generation, $field_key, $value ) );
    }

    public static function boolean_query( $directory_id, $generation, $field_key, $value ) {
        $tokens = self::scoped_term_tokens( $directory_id, $generation, $field_key, $value );

        if ( ! $tokens ) {
            return '';
        }

        return '+' . implode( ' +', $tokens );
    }

    private static function scoped_term_tokens( $directory_id, $generation, $field_key, $value ) {
        $scope  = self::scope_token( $directory_id, $generation, $field_key );
        $tokens = [];

        foreach ( self::term_tokens( $value ) as $token ) {
            $tokens[] = self::TERM_PREFIX . substr( hash( 'sha256', $scope . "\0" . $token ), 0, 32 );
        }

        return $tokens;
    }

    private static function term_tokens( $value ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        // Keep tokens stable if the site locale changes between indexing and querying.
        $value = remove_accents( $value, 'en_US' );
        $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
        $terms = preg_split( '/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY );

        if ( false === $terms ) {
            $terms = preg_split( '/[^a-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
        }

        $tokens = [];

        foreach ( array_unique( $terms ) as $term ) {
            $tokens[] = self::TERM_PREFIX . substr( hash( 'sha256', $term ), 0, 32 );
        }

        return $tokens;
    }
}
