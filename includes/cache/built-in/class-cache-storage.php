<?php

namespace Directorist\Cache\Built_In;

/**
 * Integrity-checked response and generation storage.
 */
final class Cache_Storage {
    const METADATA_SCHEMA = 1;
    const MAX_BODY_BYTES  = 10485760;
    const MAX_META_BYTES  = 1048576;

    /** @var Cache_Paths */
    private $paths;

    /** @var Atomic_File_Writer */
    private $writer;

    /** @var Generation_Store */
    private $generations;

    /** @var callable */
    private $clock;

    /** @var resource|null */
    private $regeneration_lock;

    /** @var string */
    private $regeneration_hash = '';

    /**
     * @param string        $root Cache root.
     * @param callable|null $clock Unix timestamp provider.
     */
    public function __construct( $root, $clock = null ) {
        $this->paths       = new Cache_Paths( $root );
        $this->writer      = new Atomic_File_Writer();
        $this->generations = new Generation_Store( $this->paths, $this->writer );
        $this->clock       = is_callable( $clock ) ? $clock : 'time';
    }

    /** @return bool */
    public function is_available() {
        $root = $this->paths->root();

        return '' !== $root && $this->writer->prepare_directory( $root );
    }

    /**
     * @param array  $key Canonical request key.
     * @param string $body Complete HTML body.
     * @param array  $descriptor Core response descriptor.
     * @param array  $headers Safe headers.
     * @param int    $ttl Fresh lifetime.
     * @param int    $stale_ttl Bounded stale lifetime.
     * @return array
     */
    public function store( array $key, $body, array $descriptor, array $headers, $ttl, $stale_ttl ) {
        $paths = $this->entry_paths( $key );

        if ( empty( $paths ) || ! is_string( $body ) || '' === $body || self::MAX_BODY_BYTES < strlen( $body ) ) {
            return $this->result( false, 'invalid_entry', $paths );
        }

        $dependencies = isset( $descriptor['dependencies'] ) && is_array( $descriptor['dependencies'] ) ? array_values( array_unique( $descriptor['dependencies'] ) ) : [];
        $generations  = $this->generations->snapshot( $dependencies );

        if ( false === $generations ) {
            return $this->result( false, 'invalid_dependencies', $paths );
        }

        $owned_lock = $this->regeneration_hash === $key['hash'] && is_resource( $this->regeneration_lock );
        $lock       = $owned_lock ? $this->regeneration_lock : $this->acquire_lock( $paths['lock'], true );

        if ( false === $lock ) {
            return $this->result( false, 'lock_contended', $paths );
        }

        $now      = $this->now();
        $metadata = [
            'schema'           => self::METADATA_SCHEMA,
            'owner'            => 'directorist-page-cache',
            'request_hash'     => $key['hash'],
            'canonical_url'    => $key['canonical_url'],
            'route_cache_key'  => isset( $descriptor['cache_key'] ) ? (string) $descriptor['cache_key'] : '',
            'site_id'          => isset( $descriptor['site_id'] ) ? (int) $descriptor['site_id'] : 0,
            'route_type'       => isset( $descriptor['route_type'] ) ? (string) $descriptor['route_type'] : '',
            'object_id'        => isset( $descriptor['object_id'] ) ? max( 0, (int) $descriptor['object_id'] ) : 0,
            'page_id'          => isset( $descriptor['page_id'] ) ? max( 0, (int) $descriptor['page_id'] ) : 0,
            'language'         => isset( $descriptor['language'] ) ? preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $descriptor['language'] ) ) : '',
            'variation'        => isset( $key['variation'] ) && is_array( $key['variation'] ) ? $key['variation'] : [],
            'refresh_endpoint' => isset( $descriptor['refresh_endpoint'] ) && is_string( $descriptor['refresh_endpoint'] ) ? $descriptor['refresh_endpoint'] : '',
            'created_at'       => $now,
            'expires_at'       => $now + max( 1, (int) $ttl ),
            'stale_until'      => $now + max( 1, (int) $ttl ) + max( 0, (int) $stale_ttl ),
            'body_size'        => strlen( $body ),
            'body_hash'        => hash( 'sha256', $body ),
            'headers'          => $headers,
            'generations'      => $generations,
        ];
        $encoded  = json_encode( $metadata, JSON_UNESCAPED_SLASHES );
        $stored   = is_string( $encoded )
            && $this->writer->write( $paths['body'], $body )
            && $this->writer->write( $paths['metadata'], $encoded );

        if ( ! $owned_lock ) {
            $this->release_lock( $lock );
        }

        if ( $stored && function_exists( 'do_action' ) ) {
            do_action( 'directorist_page_cache_entry_stored', $metadata );
        }

        if ( $stored ) {
            $this->remove_regular_file( $paths['refresh'] );
        }

        return $this->result( $stored, $stored ? 'stored' : 'write_failed', $paths );
    }

    /**
     * @param array $key Canonical request key.
     * @return array
     */
    public function load( array $key ) {
        $paths = $this->entry_paths( $key );

        if ( empty( $paths ) || ! $this->readable_regular_file( $paths['metadata'] ) || ! $this->readable_regular_file( $paths['body'] ) ) {
            return $this->miss( 'miss' );
        }

        $metadata_size = filesize( $paths['metadata'] );

        if ( false === $metadata_size || self::MAX_META_BYTES < $metadata_size ) {
            return $this->miss( 'invalid_metadata' );
        }

        $source   = file_get_contents( $paths['metadata'] );
        $metadata = is_string( $source ) ? json_decode( $source, true ) : null;

        if ( ! $this->valid_metadata( $metadata, $key ) ) {
            return $this->miss( 'invalid_metadata' );
        }

        $snapshot = $this->generations->snapshot( array_keys( $metadata['generations'] ) );

        if ( false === $snapshot || $snapshot !== $metadata['generations'] ) {
            return $this->miss( 'generation_mismatch' );
        }

        $now   = $this->now();
        $stale = false;
        $code  = 'hit';

        if ( $now > $metadata['stale_until'] ) {
            return $this->miss( 'stale_expired' );
        }

        if ( $now > $metadata['expires_at'] ) {
            $stale = true;
            $code  = $this->is_locked( $paths['lock'] ) ? 'stale_while_regenerating' : 'refresh_due';
        }

        $body_size = filesize( $paths['body'] );

        if ( false === $body_size || $body_size !== $metadata['body_size'] || self::MAX_BODY_BYTES < $body_size ) {
            return $this->miss( 'body_mismatch' );
        }

        $body = file_get_contents( $paths['body'] );

        if ( ! is_string( $body ) || hash( 'sha256', $body ) !== $metadata['body_hash'] ) {
            return $this->miss( 'body_mismatch' );
        }

        return [
            'hit'      => true,
            'stale'    => $stale,
            'code'     => $code,
            'body'     => $body,
            'metadata' => $metadata,
        ];
    }

    /**
     * Inspect one entry without reading its cached HTML body into memory.
     *
     * This is intended for bounded administration views. Public cache delivery
     * must continue using load(), which performs the complete body hash check.
     *
     * @param array $key Canonical request key.
     * @return array
     */
    public function inspect( array $key ) {
        $paths = $this->entry_paths( $key );

        if ( empty( $paths ) || ! $this->readable_regular_file( $paths['metadata'] ) || ! $this->readable_regular_file( $paths['body'] ) ) {
            return $this->inspection( false, 'uncached', 'miss' );
        }

        $metadata_size = filesize( $paths['metadata'] );

        if ( false === $metadata_size || self::MAX_META_BYTES < $metadata_size ) {
            return $this->inspection( false, 'invalid', 'invalid_metadata' );
        }

        $source   = file_get_contents( $paths['metadata'] );
        $metadata = is_string( $source ) ? json_decode( $source, true ) : null;

        if ( ! $this->valid_metadata( $metadata, $key ) ) {
            return $this->inspection( false, 'invalid', 'invalid_metadata' );
        }

        $body_size = filesize( $paths['body'] );

        if ( false === $body_size || $body_size !== $metadata['body_size'] || self::MAX_BODY_BYTES < $body_size ) {
            return $this->inspection( false, 'invalid', 'body_mismatch' );
        }

        $snapshot = $this->generations->snapshot( array_keys( $metadata['generations'] ) );

        if ( false === $snapshot || $snapshot !== $metadata['generations'] ) {
            return $this->inspection( true, 'invalidated', 'generation_mismatch', $metadata );
        }

        $now   = $this->now();
        $state = $now > $metadata['stale_until'] ? 'expired' : ( $now > $metadata['expires_at'] ? 'stale' : 'current' );

        return $this->inspection( true, $state, $state, $metadata );
    }

    /**
     * @param array $key Canonical request key.
     * @return array
     */
    public function begin_regeneration( array $key ) {
        $paths = $this->entry_paths( $key );

        if ( empty( $paths ) || is_resource( $this->regeneration_lock ) ) {
            return $this->result( false, 'lock_unavailable', $paths );
        }

        $lock = $this->acquire_lock( $paths['lock'], true );

        if ( false === $lock ) {
            return $this->result( false, 'lock_contended', $paths );
        }

        $this->regeneration_lock = $lock;
        $this->regeneration_hash = $key['hash'];

        return $this->result( true, 'lock_acquired', $paths );
    }

    /**
     * Claim one bounded soft-expiry refresh handoff without database access.
     *
     * @param array $key Canonical request key.
     * @param int   $lease Claim lifetime in seconds.
     * @return array
     */
    public function claim_refresh( array $key, $lease = 300 ) {
        $paths = $this->entry_paths( $key );
        $lease = min( HOUR_IN_SECONDS, max( 30, (int) $lease ) );

        if ( empty( $paths ) || is_link( $paths['refresh'] ) || ! $this->writer->prepare_directory( dirname( $paths['refresh'] ) ) ) {
            return $this->result( false, 'refresh_claim_unavailable', $paths );
        }

        $handle = @fopen( $paths['refresh'], 'c+' );

        if ( false === $handle || ! flock( $handle, LOCK_EX ) ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }

            return $this->result( false, 'refresh_claim_unavailable', $paths );
        }

        $source  = stream_get_contents( $handle, 4096 );
        $claim   = is_string( $source ) ? json_decode( $source, true ) : null;
        $now     = $this->now();
        $claimed = is_array( $claim ) && isset( $claim['claimed_at'] ) && (int) $claim['claimed_at'] + $lease >= $now;

        if ( $claimed ) {
            flock( $handle, LOCK_UN );
            fclose( $handle );

            return $this->result( false, 'refresh_claimed', $paths );
        }

        $encoded = json_encode(
            [
                'owner'      => 'directorist-page-cache',
                'hash'       => $key['hash'],
                'claimed_at' => $now,
            ],
            JSON_UNESCAPED_SLASHES
        );
        $written = is_string( $encoded )
            && rewind( $handle )
            && ftruncate( $handle, 0 )
            && false !== fwrite( $handle, $encoded . "\n" )
            && fflush( $handle );
        flock( $handle, LOCK_UN );
        fclose( $handle );

        return $this->result( $written, $written ? 'refresh_claim_created' : 'refresh_claim_failed', $paths );
    }

    /**
     * @param array $key Canonical request key.
     * @return bool
     */
    public function release_refresh_claim( array $key ) {
        $paths = $this->entry_paths( $key );

        return ! empty( $paths ) && $this->remove_regular_file( $paths['refresh'] );
    }

    /** @return void */
    public function release_regeneration() {
        if ( is_resource( $this->regeneration_lock ) ) {
            $this->release_lock( $this->regeneration_lock );
        }

        $this->regeneration_lock = null;
        $this->regeneration_hash = '';
    }

    /**
     * @param string[] $dependencies Dependency and generation keys.
     * @return array
     */
    public function bump_generations( array $dependencies ) {
        $dependencies = array_values( array_unique( array_filter( array_map( 'strval', $dependencies ) ) ) );

        if ( empty( $dependencies ) ) {
            return $this->result( true, 'no_generations' );
        }

        $bumped = $this->generations->bump( $dependencies );

        return $this->result( $bumped, $bumped ? 'generations_bumped' : 'generation_write_failed' );
    }

    /**
     * @param array $key Canonical request key.
     * @return array
     */
    public function purge( array $key ) {
        $paths = $this->entry_paths( $key );

        if ( empty( $paths ) ) {
            return $this->result( false, 'invalid_key' );
        }

        return $this->purge_paths( $paths );
    }

    /**
     * Purge one inventory-selected entry without accepting a filesystem path.
     *
     * @param string $hash Valid SHA-256 request hash.
     * @return array
     */
    public function purge_hash( $hash ) {
        if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
            return $this->result( false, 'invalid_hash' );
        }

        return $this->purge_paths( $this->paths->entry( $hash ) );
    }

    /**
     * @param array $paths Validated owned entry paths.
     * @return array
     */
    private function purge_paths( array $paths ) {
        if ( empty( $paths ) ) {
            return $this->result( false, 'invalid_paths' );
        }

        $lock = $this->acquire_lock( $paths['lock'], false );

        if ( false === $lock ) {
            return $this->result( false, 'lock_failed', $paths );
        }

        $success = $this->remove_regular_file( $paths['metadata'] )
            && $this->remove_regular_file( $paths['body'] )
            && $this->remove_regular_file( $paths['refresh'] );
        $this->release_lock( $lock );

        return $this->result( $success, $success ? 'purged' : 'purge_failed', $paths );
    }

    /**
     * @param bool       $success Inspection validity.
     * @param string     $state Public administration state.
     * @param string     $code Stable internal code.
     * @param array|null $metadata Validated metadata.
     * @return array
     */
    private function inspection( $success, $state, $code, array $metadata = null ) {
        return [
            'success'    => (bool) $success,
            'state'      => (string) $state,
            'code'       => (string) $code,
            'created_at' => null === $metadata ? 0 : max( 0, (int) $metadata['created_at'] ),
            'expires_at' => null === $metadata ? 0 : max( 0, (int) $metadata['expires_at'] ),
            'body_size'  => null === $metadata ? 0 : max( 0, (int) $metadata['body_size'] ),
            'route_type' => null === $metadata ? '' : preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $metadata['route_type'] ) ),
            'language'   => null === $metadata ? '' : preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $metadata['language'] ) ),
        ];
    }

    /**
     * @param array $key Canonical request key.
     * @return array
     */
    private function entry_paths( array $key ) {
        return ! empty( $key['success'] ) && ! empty( $key['hash'] ) ? $this->paths->entry( $key['hash'] ) : [];
    }

    /**
     * @param mixed $metadata Decoded metadata.
     * @param array $key Canonical request key.
     * @return bool
     */
    private function valid_metadata( $metadata, array $key ) {
        return is_array( $metadata )
            && isset( $metadata['schema'], $metadata['owner'], $metadata['request_hash'], $metadata['canonical_url'], $metadata['created_at'], $metadata['expires_at'], $metadata['stale_until'], $metadata['body_size'], $metadata['body_hash'], $metadata['headers'], $metadata['generations'] )
            && self::METADATA_SCHEMA === $metadata['schema']
            && 'directorist-page-cache' === $metadata['owner']
            && $key['hash'] === $metadata['request_hash']
            && $key['canonical_url'] === $metadata['canonical_url']
            && is_int( $metadata['created_at'] )
            && is_int( $metadata['expires_at'] )
            && is_int( $metadata['stale_until'] )
            && is_int( $metadata['body_size'] )
            && is_string( $metadata['body_hash'] )
            && preg_match( '/^[a-f0-9]{64}$/', $metadata['body_hash'] )
            && 0 < $metadata['body_size']
            && self::MAX_BODY_BYTES >= $metadata['body_size']
            && $metadata['expires_at'] >= $metadata['created_at']
            && $metadata['stale_until'] >= $metadata['expires_at']
            && $this->valid_headers( $metadata['headers'] )
            && is_array( $metadata['generations'] )
            && ( ! isset( $metadata['variation'] ) || $this->valid_variation( $metadata['variation'], $key ) );
    }

    /**
     * @param mixed $variation Persisted cache-key variation.
     * @param array $key Canonical request key.
     * @return bool
     */
    private function valid_variation( $variation, array $key ) {
        if ( ! is_array( $variation ) || 8 < count( $variation ) ) {
            return false;
        }

        $normalized = [];

        foreach ( $variation as $name => $value ) {
            if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $name ) || ! is_string( $value ) || 64 < strlen( $value ) || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
                return false;
            }

            $normalized[ $name ] = $value;
        }

        ksort( $normalized, SORT_STRING );

        return isset( $key['variation'] ) && is_array( $key['variation'] ) && $normalized === $key['variation'];
    }

    /**
     * @param mixed $headers Persisted replay headers.
     * @return bool
     */
    private function valid_headers( $headers ) {
        if ( ! is_array( $headers ) || empty( $headers['content-type'] ) ) {
            return false;
        }

        foreach ( $headers as $name => $value ) {
            if ( ! in_array( $name, [ 'content-type', 'content-language' ], true ) || ! is_string( $value ) || '' === $value || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
                return false;
            }
        }

        $content_type = strtolower( $headers['content-type'] );

        return 0 === strpos( $content_type, 'text/html' ) || 0 === strpos( $content_type, 'application/xhtml+xml' );
    }

    /**
     * @param string $path Lock path.
     * @param bool   $nonblocking Whether lock acquisition is nonblocking.
     * @return resource|false
     */
    private function acquire_lock( $path, $nonblocking ) {
        if ( ! $this->writer->prepare_directory( dirname( $path ) ) || is_link( $path ) ) {
            return false;
        }

        $handle = @fopen( $path, 'c+' );
        $mode   = LOCK_EX | ( $nonblocking ? LOCK_NB : 0 );

        if ( false === $handle || ! flock( $handle, $mode ) ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }

            return false;
        }

        return $handle;
    }

    /**
     * @param resource $lock Lock handle.
     * @return void
     */
    private function release_lock( $lock ) {
        flock( $lock, LOCK_UN );
        fclose( $lock );
    }

    /**
     * @param string $path Lock path.
     * @return bool
     */
    private function is_locked( $path ) {
        $lock = $this->acquire_lock( $path, true );

        if ( false === $lock ) {
            return true;
        }

        $this->release_lock( $lock );

        return false;
    }

    /**
     * @param string $path File path.
     * @return bool
     */
    private function readable_regular_file( $path ) {
        return ! is_link( $path ) && is_file( $path ) && is_readable( $path );
    }

    /**
     * @param string $path File path.
     * @return bool
     */
    private function remove_regular_file( $path ) {
        if ( ! file_exists( $path ) && ! is_link( $path ) ) {
            return true;
        }

        return ! is_link( $path ) && is_file( $path ) && @unlink( $path );
    }

    /** @return int */
    private function now() {
        return (int) call_user_func( $this->clock );
    }

    /**
     * @param bool   $success Operation state.
     * @param string $code Stable code.
     * @param array  $paths Entry paths.
     * @return array
     */
    private function result( $success, $code, array $paths = [] ) {
        return [
            'success' => (bool) $success,
            'code'    => $code,
            'paths'   => $paths,
        ];
    }

    /**
     * @param string $code Miss code.
     * @return array
     */
    private function miss( $code ) {
        return [
            'hit'      => false,
            'stale'    => false,
            'code'     => $code,
            'body'     => '',
            'metadata' => [],
        ];
    }
}
