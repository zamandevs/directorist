<?php

namespace Directorist\Cache;

/**
 * Stable logical identities for WPML, Polylang, and integration-owned variants.
 */
final class Performance_Translation_Resolver {
    public function post( $post_id, $post_type ) {
        return $this->resolve( $post_id, 'post_' . sanitize_key( (string) $post_type ), 'post' );
    }

    public function term( \WP_Term $term ) {
        return $this->resolve( $term->term_taxonomy_id, 'tax_' . $term->taxonomy, 'term-' . $term->taxonomy, $term->term_id );
    }

    private function resolve( $element_id, $element_type, $fallback_prefix, $object_id = 0 ) {
        $element_id = absint( $element_id );
        $object_id  = 0 < (int) $object_id ? absint( $object_id ) : $element_id;
        $trid       = apply_filters( 'wpml_element_trid', null, $element_id, $element_type );
        $details    = apply_filters(
            'wpml_element_language_details',
            null,
            [ 'element_id' => $element_id, 'element_type' => $element_type ]
        );
        $language   = is_object( $details ) && isset( $details->language_code ) ? sanitize_key( (string) $details->language_code ) : '';

        if ( null !== $trid && '' !== (string) $trid ) {
            return [ 'logical_key' => 'wpml-' . sanitize_key( (string) $trid ), 'language' => $language ];
        }

        if ( 'post' === $fallback_prefix && function_exists( 'pll_get_post_language' ) ) {
            $language     = sanitize_key( (string) pll_get_post_language( $object_id, 'slug' ) );
            $translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $object_id ) : [];

            if ( is_array( $translations ) && ! empty( $translations ) ) {
                $translation_ids = array_map( 'absint', $translations );
                sort( $translation_ids, SORT_NUMERIC );

                return [ 'logical_key' => 'pll-' . reset( $translation_ids ), 'language' => $language ];
            }
        }

        if ( 0 === strpos( $fallback_prefix, 'term-' ) && function_exists( 'pll_get_term_language' ) ) {
            $language     = sanitize_key( (string) pll_get_term_language( $object_id, 'slug' ) );
            $translations = function_exists( 'pll_get_term_translations' ) ? pll_get_term_translations( $object_id ) : [];

            if ( is_array( $translations ) && ! empty( $translations ) ) {
                $translation_ids = array_map( 'absint', $translations );
                sort( $translation_ids, SORT_NUMERIC );

                return [ 'logical_key' => 'pll-term-' . reset( $translation_ids ), 'language' => $language ];
            }
        }

        $identity = [ 'logical_key' => sanitize_key( $fallback_prefix . '-' . $object_id ), 'language' => $language ];
        $identity = apply_filters( 'directorist_performance_resource_translation_identity', $identity, $object_id, $element_type );

        return [
            'logical_key' => isset( $identity['logical_key'] ) && '' !== (string) $identity['logical_key'] ? sanitize_key( (string) $identity['logical_key'] ) : sanitize_key( $fallback_prefix . '-' . $object_id ),
            'language'    => isset( $identity['language'] ) ? sanitize_key( (string) $identity['language'] ) : '',
        ];
    }
}
