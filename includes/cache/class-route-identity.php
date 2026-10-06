<?php

namespace Directorist\Cache;

/**
 * Stable identity for a public Directorist frontend response.
 */
final class Route_Identity {
    /** @var int */
    private $site_id;

    /** @var string */
    private $home_url;

    /** @var string */
    private $route_type;

    /** @var int */
    private $object_id;

    /** @var int */
    private $page_id;

    /** @var int */
    private $page_number;

    /** @var array */
    private $variation;

    /** @var array */
    private $entities;

    /** @var string */
    private $language;

    /**
     * @param array $data Route identity data.
     */
    public function __construct( array $data = [] ) {
        $data = wp_parse_args(
            $data,
            [
                'site_id'     => 1,
                'home_url'    => '',
                'route_type'  => '',
                'object_id'   => 0,
                'page_id'     => 0,
                'page_number' => 1,
                'variation'   => [],
                'entities'    => [],
                'language'    => '',
            ]
        );

        $this->site_id     = max( 1, absint( $data['site_id'] ) );
        $this->home_url    = $this->normalize_home_url( $data['home_url'] );
        $this->route_type  = sanitize_key( (string) $data['route_type'] );
        $this->object_id   = absint( $data['object_id'] );
        $this->page_id     = absint( $data['page_id'] );
        $this->page_number = max( 1, absint( $data['page_number'] ) );
        $this->variation   = $this->normalize_variation( is_array( $data['variation'] ) ? $data['variation'] : [] );
        $this->entities    = $this->normalize_entities( is_array( $data['entities'] ) ? $data['entities'] : [] );
        $this->language    = sanitize_key( (string) $data['language'] );
    }

    /** @return int */
    public function get_site_id() {
        return $this->site_id;
    }

    /** @return string */
    public function get_route_type() {
        return $this->route_type;
    }

    /** @return int */
    public function get_object_id() {
        return $this->object_id;
    }

    /** @return int */
    public function get_page_id() {
        return $this->page_id;
    }

    /** @return int */
    public function get_page_number() {
        return $this->page_number;
    }

    /** @return array */
    public function get_variation() {
        return $this->variation;
    }

    /** @return string */
    public function get_language() {
        return $this->language;
    }

    /**
     * @param string $type Entity type.
     * @return int[]
     */
    public function get_entity_ids( $type ) {
        $type = sanitize_key( (string) $type );

        return isset( $this->entities[ $type ] ) ? $this->entities[ $type ] : [];
    }

    /** @return string */
    public function get_cache_key() {
        $material = [
            'home_url'    => $this->home_url,
            'route_type'  => $this->route_type,
            'object_id'   => $this->object_id,
            'page_id'     => $this->page_id,
            'page_number' => $this->page_number,
            'variation'   => $this->variation,
            'language'    => $this->language,
        ];

        return sprintf(
            'directorist:page:v1:site:%d:%s',
            $this->site_id,
            hash( 'sha256', wp_json_encode( $material ) )
        );
    }

    /**
     * @param array $variation Query variation.
     * @return array
     */
    private function normalize_variation( array $variation ) {
        foreach ( $variation as $key => $value ) {
            if ( is_array( $value ) && ! $this->is_list( $value ) ) {
                $variation[ $key ] = $this->normalize_variation( $value );
            }
        }

        ksort( $variation, SORT_STRING );

        return $variation;
    }

    /**
     * @param string $home_url Site home URL.
     * @return string
     */
    private function normalize_home_url( $home_url ) {
        $parts = wp_parse_url( (string) $home_url );

        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return untrailingslashit( (string) $home_url );
        }

        $normalized  = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) . '://' : '//';
        $normalized .= strtolower( $parts['host'] );

        if ( isset( $parts['port'] ) ) {
            $normalized .= ':' . absint( $parts['port'] );
        }

        $normalized .= isset( $parts['path'] ) ? $parts['path'] : '';

        return untrailingslashit( $normalized );
    }

    /**
     * @param array $entities Entity IDs by type.
     * @return array
     */
    private function normalize_entities( array $entities ) {
        $normalized = [];

        foreach ( $entities as $type => $ids ) {
            $type = sanitize_key( (string) $type );

            if ( '' === $type ) {
                continue;
            }

            $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
            sort( $ids, SORT_NUMERIC );
            $normalized[ $type ] = $ids;
        }

        ksort( $normalized, SORT_STRING );

        return $normalized;
    }

    /**
     * @param array $value Value to inspect.
     * @return bool
     */
    private function is_list( array $value ) {
        return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
    }
}
