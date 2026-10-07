<?php

namespace Directorist\Cache\Built_In;

/**
 * Reads and atomically updates the owned early-loader configuration.
 */
final class Early_Config_Manager {
    const MAX_BYTES = 32768;

    /** @var string */
    private $path;

    /** @var Atomic_Writer */
    private $writer;

    /**
     * @param string             $path Generated config path.
     * @param Atomic_Writer|null $writer Atomic writer.
     */
    public function __construct( $path, Atomic_Writer $writer = null ) {
        $this->path   = (string) $path;
        $this->writer = $writer ?: new Atomic_Writer();
    }

    /** @return array */
    public function status() {
        $read = $this->read();

        if ( empty( $read['success'] ) ) {
            return $read;
        }

        return [
            'success' => true,
            'code'    => ! empty( $read['config']['enabled'] ) || ! array_key_exists( 'enabled', $read['config'] ) ? 'config_enabled' : 'config_disabled',
            'enabled' => ! array_key_exists( 'enabled', $read['config'] ) || ! empty( $read['config']['enabled'] ),
            'path'    => $this->path,
        ];
    }

    /**
     * @param bool $enabled Desired early-cache state.
     * @return array
     */
    public function set_enabled( $enabled ) {
        $read = $this->read();

        if ( empty( $read['success'] ) ) {
            return $read;
        }

        $enabled = (bool) $enabled;
        $current = ! array_key_exists( 'enabled', $read['config'] ) || ! empty( $read['config']['enabled'] );

        if ( $current === $enabled ) {
            return [
                'success' => true,
                'code'    => $enabled ? 'enabled' : 'disabled',
                'enabled' => $enabled,
                'changed' => false,
                'path'    => $this->path,
            ];
        }

        $config            = $read['config'];
        $config['enabled'] = $enabled;
        $encoded           = json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        if ( ! is_string( $encoded ) || self::MAX_BYTES < strlen( $encoded ) ) {
            return $this->result( false, 'config_encode_failed' );
        }

        $written = $this->writer->write( $this->path, $encoded . "\n" );

        if ( empty( $written['success'] ) ) {
            return $this->result( false, isset( $written['code'] ) ? $written['code'] : 'config_write_failed' );
        }

        return [
            'success' => true,
            'code'    => $enabled ? 'enabled' : 'disabled',
            'enabled' => $enabled,
            'changed' => true,
            'path'    => $this->path,
        ];
    }

    /**
     * Atomically synchronize the generated early cookie policy.
     *
     * @param array $policy Core-exported policy.
     * @return array
     */
    public function set_cookie_policy( array $policy ) {
        $read = $this->read();

        if ( empty( $read['success'] ) ) {
            return $read;
        }

        $normalized = ( new Cookie_Policy( $policy ) )->to_array();
        $current    = isset( $read['config']['cookie_policy'] ) && is_array( $read['config']['cookie_policy'] )
            ? ( new Cookie_Policy( $read['config']['cookie_policy'] ) )->to_array()
            : ( new Cookie_Policy() )->to_array();

        if ( $current === $normalized ) {
            return [
                'success' => true,
                'code'    => 'cookie_policy_current',
                'changed' => false,
                'path'    => $this->path,
            ];
        }

        $config                  = $read['config'];
        $config['cookie_policy'] = $normalized;
        $written                 = $this->write_config( $config );

        return [
            'success' => $written,
            'code'    => $written ? 'cookie_policy_updated' : 'config_write_failed',
            'changed' => $written,
            'path'    => $this->path,
        ];
    }

    /**
     * Atomically synchronize values used before WordPress loads.
     *
     * @param int  $ttl Cache lifetime in seconds.
     * @param bool  $cache_filtered_results Whether query variants may be cached.
     * @param array|null $refresh_policy Route-aware soft/hard lifetime policy.
     * @param string|null $refresh_endpoint Trusted WordPress refresh receiver.
     * @param string|null $refresh_token Signed refresh token.
     * @return array
     */
    public function set_runtime_policy( $ttl, $cache_filtered_results, $refresh_policy = null, $refresh_endpoint = null, $refresh_token = null ) {
        $read = $this->read();

        if ( empty( $read['success'] ) ) {
            return $read;
        }

        $ttl                    = min( DAY_IN_SECONDS, max( HOUR_IN_SECONDS, (int) $ttl ) );
        $cache_filtered_results = (bool) $cache_filtered_results;
        $current_ttl            = isset( $read['config']['ttl'] ) ? (int) $read['config']['ttl'] : HOUR_IN_SECONDS;
        $current_filtered       = ! array_key_exists( 'cache_filtered_results', $read['config'] ) || ! empty( $read['config']['cache_filtered_results'] );
        $current_policy         = isset( $read['config']['refresh_policy'] ) && is_array( $read['config']['refresh_policy'] ) ? $read['config']['refresh_policy'] : [];
        $current_endpoint       = isset( $read['config']['refresh_endpoint'] ) ? (string) $read['config']['refresh_endpoint'] : '';
        $current_token          = isset( $read['config']['refresh_token'] ) ? (string) $read['config']['refresh_token'] : '';
        $refresh_policy         = is_array( $refresh_policy ) ? $refresh_policy : $current_policy;
        $refresh_endpoint       = is_string( $refresh_endpoint ) ? $refresh_endpoint : $current_endpoint;
        $refresh_token          = is_string( $refresh_token ) ? $refresh_token : $current_token;

        if ( $current_ttl === $ttl && $current_filtered === $cache_filtered_results && $current_policy === $refresh_policy && $current_endpoint === $refresh_endpoint && $current_token === $refresh_token ) {
            return [
                'success' => true,
                'code'    => 'runtime_policy_current',
                'changed' => false,
                'path'    => $this->path,
            ];
        }

        $config                           = $read['config'];
        $config['ttl']                    = $ttl;
        $config['cache_filtered_results'] = $cache_filtered_results;
        $config['refresh_policy']         = $refresh_policy;
        $config['refresh_endpoint']       = $refresh_endpoint;
        $config['refresh_token']          = $refresh_token;
        $written                          = $this->write_config( $config );

        return [
            'success' => $written,
            'code'    => $written ? 'runtime_policy_updated' : 'config_write_failed',
            'changed' => $written,
            'path'    => $this->path,
        ];
    }

    /**
     * @param array $config Complete owned configuration.
     * @return bool
     */
    private function write_config( array $config ) {
        $encoded = json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        if ( ! is_string( $encoded ) || self::MAX_BYTES < strlen( $encoded ) ) {
            return false;
        }

        $written = $this->writer->write( $this->path, $encoded . "\n" );

        return ! empty( $written['success'] );
    }

    /** @return array */
    private function read() {
        if ( is_link( $this->path ) ) {
            return $this->result( false, 'config_symlink' );
        }

        if ( ! file_exists( $this->path ) ) {
            return $this->result( false, 'config_missing' );
        }

        if ( ! is_file( $this->path ) || ! is_readable( $this->path ) ) {
            return $this->result( false, 'config_unreadable' );
        }

        $size = filesize( $this->path );

        if ( false === $size || self::MAX_BYTES < $size ) {
            return $this->result( false, 'config_invalid' );
        }

        $source = file_get_contents( $this->path );
        $config = is_string( $source ) ? json_decode( $source, true ) : null;

        if ( ! Ownership::owns_config( $this->path ) ) {
            return $this->result( false, 'config_foreign' );
        }

        if ( ! Early_Config::is_valid( $config ) ) {
            return $this->result( false, 'config_invalid' );
        }

        return [
            'success' => true,
            'code'    => 'config_owned',
            'config'  => $config,
            'path'    => $this->path,
        ];
    }

    /**
     * @param bool   $success Result state.
     * @param string $code Stable code.
     * @return array
     */
    private function result( $success, $code ) {
        return [
            'success' => (bool) $success,
            'code'    => (string) $code,
            'enabled' => false,
            'path'    => $this->path,
        ];
    }
}
