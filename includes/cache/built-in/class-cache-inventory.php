<?php

namespace Directorist\Cache\Built_In;

/**
 * Read-only bounded cache-file health inventory for explicit diagnostics.
 */
final class Cache_Inventory {
    const MAX_FILES       = 500;
    const MAX_DIRECTORIES = 128;
    const MAX_META_BYTES  = 1048576;
    const MAX_PER_PAGE    = 50;

    /** @var string */
    private $root;

    /** @var callable */
    private $clock;

    /** @var Generation_Store */
    private $generations;

    /**
     * @param string        $root Owned cache root.
     * @param callable|null $clock Unix timestamp provider.
     */
    public function __construct( $root, $clock = null ) {
        $this->root        = is_string( $root ) ? rtrim( $root, '/\\' ) : '';
        $this->clock       = is_callable( $clock ) ? $clock : 'time';
        $this->generations = new Generation_Store( new Cache_Paths( $this->root ) );
    }

    /**
     * @param array $args Bounded list arguments.
     * @return array
     */
    public function status( array $args = [] ) {
        if ( '' === $this->root || ! file_exists( $this->root ) ) {
            return $this->result( false, 'cache_root_missing' );
        }

        if ( is_link( $this->root ) ) {
            return $this->result( false, 'cache_root_symlink' );
        }

        if ( ! is_dir( $this->root ) || ! is_readable( $this->root ) ) {
            return $this->result( false, 'cache_root_unreadable' );
        }

        $args        = $this->normalize_args( $args );
        $pages       = $this->page_counts( $args['route_type'], $args['canonical_url'] );
        $generations = $pages['truncated']
            ? [ 'count' => 0, 'bytes' => 0, 'examined' => 0, 'truncated' => true ]
            : $this->generation_count( self::MAX_FILES - $pages['examined'] );
        $truncated   = $pages['truncated'] || $generations['truncated'];
        $code        = 0 < $pages['orphans'] ? 'cache_orphans' : ( $truncated ? 'inventory_truncated' : 'ready' );
        $matched     = count( $pages['items'] );
        $page_count  = max( 1, (int) ceil( $matched / $args['per_page'] ) );
        $page        = min( $args['page'], $page_count );
        $offset      = ( $page - 1 ) * $args['per_page'];

        return [
            'success'          => true,
            'code'             => $code,
            'writable'         => is_writable( $this->root ),
            'entries'          => $pages['entries'],
            'cached_entries'   => $pages['cached_entries'],
            'inactive_entries' => $pages['inactive_entries'],
            'cached_bytes'     => $pages['cached_bytes'],
            'inactive_bytes'   => $pages['inactive_bytes'],
            'orphans'          => $pages['orphans'],
            'generations'      => $generations['count'],
            'bytes'            => $pages['bytes'] + $generations['bytes'],
            'examined'         => $pages['examined'] + $generations['examined'],
            'truncated'        => $truncated,
            'matched_entries'  => $matched,
            'groups'           => $pages['groups'],
            'items'            => array_slice( $pages['items'], $offset, $args['per_page'] ),
            'page'             => $page,
            'per_page'         => $args['per_page'],
            'pages'            => $page_count,
            'route_type'       => $args['route_type'],
            'canonical_url'    => $args['canonical_url'],
            'generated_at'     => (int) call_user_func( $this->clock ),
        ];
    }

    /**
     * @param string $route_type Optional route filter.
     * @param string $canonical_url Optional canonical URL filter.
     * @return array
     */
    private function page_counts( $route_type, $canonical_url ) {
        $pairs       = [];
        $examined    = 0;
        $directories = 0;
        $truncated   = false;
        $bytes       = 0;

        foreach ( $this->hex_directories( $this->root . '/pages' ) as $first ) {
            foreach ( $this->hex_directories( $this->root . '/pages/' . $first ) as $second ) {
                ++$directories;
                $directory = $this->root . '/pages/' . $first . '/' . $second;

                foreach ( scandir( $directory ) as $name ) {
                    if ( ! preg_match( '/^([a-f0-9]{64})\.(json|body)$/', $name, $matches ) ) {
                        continue;
                    }

                    if ( self::MAX_FILES <= $examined ) {
                        $truncated = true;
                        break 3;
                    }

                    ++$examined;

                    $path = $directory . '/' . $name;

                    if ( is_link( $path ) || ! is_file( $path ) ) {
                        $pairs[ $matches[1] ]['unsafe'] = true;
                        continue;
                    }

                    $size = filesize( $path );

                    if ( false !== $size ) {
                        $size                          = max( 0, (int) $size );
                        $bytes                        += $size;
                        $pairs[ $matches[1] ]['bytes'] = isset( $pairs[ $matches[1] ]['bytes'] ) ? $pairs[ $matches[1] ]['bytes'] + $size : $size;
                    }

                    $pairs[ $matches[1] ][ $matches[2] ] = $path;
                }

                if ( self::MAX_DIRECTORIES <= $directories ) {
                    $truncated = true;
                    break 2;
                }
            }
        }

        $entries          = 0;
        $orphans          = 0;
        $inactive_entries = 0;
        $cached_bytes     = 0;
        $inactive_bytes   = 0;
        $active_items     = [];

        foreach ( $pairs as $hash => $pair ) {
            if ( ! empty( $pair['json'] ) && ! empty( $pair['body'] ) && empty( $pair['unsafe'] ) ) {
                ++$entries;
                $item = $this->entry( $hash, $pair['json'], $pair['body'] );

                if ( ! is_array( $item ) || ! in_array( $item['state'], [ 'current', 'stale' ], true ) ) {
                    ++$inactive_entries;
                    $inactive_bytes += isset( $pair['bytes'] ) ? $pair['bytes'] : 0;
                } else {
                    $active_items[] = $item;
                    $cached_bytes  += isset( $pair['bytes'] ) ? $pair['bytes'] : 0;
                }
            } else {
                ++$orphans;
            }
        }

        usort(
            $active_items,
            static function ( $first, $second ) {
                if ( $first['created_at'] === $second['created_at'] ) {
                    return strcmp( $first['hash'], $second['hash'] );
                }

                return $first['created_at'] > $second['created_at'] ? -1 : 1;
            }
        );

        $groups = [];

        foreach ( $active_items as $item ) {
            if ( ! isset( $groups[ $item['route_type'] ] ) ) {
                $groups[ $item['route_type'] ] = [
                    'route_type' => $item['route_type'],
                    'entries'    => 0,
                    'bytes'      => 0,
                ];
            }

            ++$groups[ $item['route_type'] ]['entries'];
            $groups[ $item['route_type'] ]['bytes'] += $item['body_size'];
        }

        ksort( $groups, SORT_STRING );

        $cached_entries     = count( $active_items );
        $canonical_identity = $this->url_identity( $canonical_url );
        $items              = array_values(
            array_filter(
                $active_items,
                function ( $item ) use ( $route_type, $canonical_identity ) {
                    if ( '' !== $route_type && $route_type !== $item['route_type'] ) {
                        return false;
                    }

                    return '' === $canonical_identity || $canonical_identity === $this->url_identity( $item['canonical_url'] );
                }
            )
        );

        return compact( 'entries', 'cached_entries', 'inactive_entries', 'cached_bytes', 'inactive_bytes', 'orphans', 'bytes', 'examined', 'truncated', 'items', 'groups' );
    }

    /**
     * @param string $hash Request hash.
     * @param string $metadata_path Metadata path.
     * @param string $body_path Body path.
     * @return array|null
     */
    private function entry( $hash, $metadata_path, $body_path ) {
        $size = filesize( $metadata_path );

        if ( false === $size || self::MAX_META_BYTES < $size || ! is_readable( $metadata_path ) || ! is_readable( $body_path ) ) {
            return null;
        }

        $source   = file_get_contents( $metadata_path );
        $metadata = is_string( $source ) ? json_decode( $source, true ) : null;

        if ( ! is_array( $metadata )
            || 'directorist-page-cache' !== ( isset( $metadata['owner'] ) ? $metadata['owner'] : '' )
            || $hash !== ( isset( $metadata['request_hash'] ) ? $metadata['request_hash'] : '' )
            || empty( $metadata['canonical_url'] )
            || ! isset( $metadata['created_at'], $metadata['expires_at'], $metadata['stale_until'] )
        ) {
            return null;
        }

        $url        = (string) $metadata['canonical_url'];
        $url_parts  = parse_url( $url );
        $route_type = isset( $metadata['route_type'] ) ? strtolower( (string) $metadata['route_type'] ) : '';

        if ( ! is_array( $url_parts ) || empty( $url_parts['host'] ) || empty( $url_parts['scheme'] ) || ! in_array( strtolower( $url_parts['scheme'] ), [ 'http', 'https' ], true ) || ! preg_match( '/^[a-z0-9-]{1,64}$/', $route_type ) ) {
            return null;
        }

        $created_at   = max( 0, (int) $metadata['created_at'] );
        $expires_at   = max( 0, (int) $metadata['expires_at'] );
        $stale_until  = max( 0, (int) $metadata['stale_until'] );
        $body_size    = isset( $metadata['body_size'] ) ? max( 0, (int) $metadata['body_size'] ) : 0;
        $dependencies = isset( $metadata['generations'] ) && is_array( $metadata['generations'] ) ? $metadata['generations'] : [];
        $snapshot     = $this->generations->snapshot( array_keys( $dependencies ) );
        $now          = (int) call_user_func( $this->clock );
        $state        = false === $snapshot || $snapshot !== $dependencies
            ? 'invalidated'
            : ( $now > $stale_until ? 'expired' : ( $now > $expires_at ? 'stale' : 'current' ) );
        $language     = isset( $metadata['language'] ) && is_string( $metadata['language'] ) && preg_match( '/^[a-z0-9-]{1,64}$/', $metadata['language'] ) ? $metadata['language'] : '';

        foreach ( '' === $language ? array_keys( $dependencies ) : [] as $dependency ) {
            if ( preg_match( '/^directorist:[0-9]+:language:([a-z0-9-]+)$/', $dependency, $matches ) ) {
                $language = $matches[1];
                break;
            }
        }

        return [
            'hash'          => $hash,
            'canonical_url' => $url,
            'route_type'    => $route_type,
            'created_at'    => $created_at,
            'expires_at'    => $expires_at,
            'stale_until'   => $stale_until,
            'body_size'     => $body_size,
            'state'         => $state,
            'language'      => $language,
            'has_query'     => ! empty( $url_parts['query'] ),
            'site_id'       => isset( $metadata['site_id'] ) ? max( 0, (int) $metadata['site_id'] ) : 0,
            'object_id'     => isset( $metadata['object_id'] ) ? max( 0, (int) $metadata['object_id'] ) : 0,
            'page_id'       => isset( $metadata['page_id'] ) ? max( 0, (int) $metadata['page_id'] ) : 0,
        ];
    }

    /**
     * @param array $args Raw list arguments.
     * @return array
     */
    private function normalize_args( array $args ) {
        $page          = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
        $per_page      = isset( $args['per_page'] ) ? min( self::MAX_PER_PAGE, max( 1, (int) $args['per_page'] ) ) : 20;
        $route_type    = isset( $args['route_type'] ) ? strtolower( (string) $args['route_type'] ) : '';
        $canonical_url = isset( $args['canonical_url'] ) ? esc_url_raw( (string) $args['canonical_url'] ) : '';

        if ( '' !== $route_type && ! preg_match( '/^[a-z0-9-]{1,64}$/', $route_type ) ) {
            $route_type = '';
        }

        if ( '' !== $canonical_url && '' === $this->url_identity( $canonical_url ) ) {
            $canonical_url = '';
        }

        return compact( 'page', 'per_page', 'route_type', 'canonical_url' );
    }

    /**
     * Match cache variants by origin and path while allowing their query strings to differ.
     *
     * @param string $url Public URL.
     * @return string
     */
    private function url_identity( $url ) {
        $parts = wp_parse_url( (string) $url );

        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return '';
        }

        $scheme = strtolower( (string) $parts['scheme'] );

        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return '';
        }

        $port = isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
        $path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

        return $scheme . '://' . strtolower( (string) $parts['host'] ) . $port . $path;
    }

    /**
     * @param int $limit Remaining file budget.
     * @return array
     */
    private function generation_count( $limit ) {
        $count     = 0;
        $examined  = 0;
        $truncated = false;
        $bytes     = 0;
        $limit     = max( 0, (int) $limit );

        foreach ( $this->hex_directories( $this->root . '/generations' ) as $directory ) {
            $path = $this->root . '/generations/' . $directory;

            foreach ( scandir( $path ) as $name ) {
                if ( ! preg_match( '/^[a-f0-9]{64}\.gen$/', $name ) ) {
                    continue;
                }

                if ( $limit <= $examined ) {
                    $truncated = true;
                    break 2;
                }

                ++$examined;

                $generation_path = $path . '/' . $name;

                if ( ! is_link( $generation_path ) && is_file( $generation_path ) ) {
                    ++$count;
                    $size = filesize( $generation_path );

                    if ( false !== $size ) {
                        $bytes += max( 0, (int) $size );
                    }
                }
            }
        }

        return compact( 'count', 'bytes', 'examined', 'truncated' );
    }

    /**
     * @param string $path Parent directory.
     * @return string[]
     */
    private function hex_directories( $path ) {
        if ( ! is_dir( $path ) || is_link( $path ) ) {
            return [];
        }

        $directories = [];

        foreach ( scandir( $path ) as $name ) {
            $candidate = $path . '/' . $name;

            if ( preg_match( '/^[a-f0-9]{2}$/', $name ) && is_dir( $candidate ) && ! is_link( $candidate ) ) {
                $directories[] = $name;
            }
        }

        sort( $directories, SORT_STRING );

        return $directories;
    }

    /**
     * @param bool   $success Result state.
     * @param string $code Stable code.
     * @return array
     */
    private function result( $success, $code ) {
        return [
            'success'          => (bool) $success,
            'code'             => (string) $code,
            'writable'         => false,
            'entries'          => 0,
            'cached_entries'   => 0,
            'inactive_entries' => 0,
            'cached_bytes'     => 0,
            'inactive_bytes'   => 0,
            'orphans'          => 0,
            'generations'      => 0,
            'bytes'            => 0,
            'examined'         => 0,
            'truncated'        => false,
            'matched_entries'  => 0,
            'groups'           => [],
            'items'            => [],
            'page'             => 1,
            'per_page'         => 20,
            'pages'            => 1,
            'route_type'       => '',
            'canonical_url'    => '',
            'generated_at'     => (int) call_user_func( $this->clock ),
        ];
    }
}
