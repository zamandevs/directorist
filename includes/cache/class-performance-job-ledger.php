<?php

namespace Directorist\Cache;

/**
 * Temporary, chunked URL ledger for resumable Performance cache jobs.
 */
final class Performance_Job_Ledger {
    const OPTION_PREFIX = 'directorist_performance_job_urls_';
    const CHUNK_SIZE    = 50;

    /**
     * Persist URLs accepted by the warm worker without growing one large option.
     *
     * @param string   $job_id Job identity.
     * @param string[] $urls Accepted public URLs.
     * @return int|false Number of newly persisted URLs, or false on failure.
     */
    public function append( $job_id, array $urls ) {
        $urls = $this->normalize_urls( $urls );

        if ( empty( $urls ) ) {
            return 0;
        }

        $existing = $this->stored_hashes( $job_id );
        $urls     = array_values(
            array_filter(
                $urls,
                static function ( $url ) use ( $existing ) {
                    return ! isset( $existing[ hash( 'sha256', $url ) ] );
                }
            )
        );

        if ( empty( $urls ) ) {
            return 0;
        }

        $created = [];

        foreach ( array_chunk( $urls, self::CHUNK_SIZE ) as $chunk ) {
            $records = [];

            foreach ( $chunk as $url ) {
                $records[ hash( 'sha256', $url ) ] = $url;
            }

            $name  = $this->option_prefix( $job_id ) . wp_generate_password( 12, false, false );
            $value = [ 'created_at' => time(), 'urls' => $records ];

            if ( ! add_option( $name, $value, '', false ) ) {
                foreach ( $created as $created_name ) {
                    delete_option( $created_name );
                }

                return false;
            }

            $created[] = $name;
        }

        return count( $urls );
    }

    /**
     * Return accepted URLs which have not produced terminal callbacks.
     *
     * @param string $job_id Job identity.
     * @param array  $terminal_results URL hash map from the job.
     * @param int    $limit Maximum URLs to return.
     * @return string[]
     */
    public function pending( $job_id, array $terminal_results, $limit = 50 ) {
        global $wpdb;

        $limit   = max( 1, min( 500, (int) $limit ) );
        $prefix  = $this->option_prefix( $job_id );
        $rows    = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC",
                $wpdb->esc_like( $prefix ) . '%'
            )
        );
        $pending = [];

        foreach ( $rows as $serialized ) {
            $chunk = maybe_unserialize( $serialized );
            $urls  = is_array( $chunk ) && isset( $chunk['urls'] ) && is_array( $chunk['urls'] ) ? $chunk['urls'] : [];

            foreach ( $urls as $hash => $url ) {
                if ( isset( $terminal_results[ $hash ] ) || ! is_string( $url ) || '' === $url ) {
                    continue;
                }

                $pending[] = $url;

                if ( $limit <= count( $pending ) ) {
                    return $pending;
                }
            }
        }

        return $pending;
    }

    /** @return bool */
    public function has_entries( $job_id ) {
        global $wpdb;

        $prefix = $this->option_prefix( $job_id );
        $count  = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $prefix ) . '%'
            )
        );

        return 0 < (int) $count;
    }

    /** @return int */
    public function cleanup( $job_id ) {
        global $wpdb;

        $prefix = $this->option_prefix( $job_id );

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $prefix ) . '%'
            )
        );
    }

    /** @return int */
    public static function cleanup_all() {
        global $wpdb;

        if ( ! isset( $wpdb->options ) ) {
            return 0;
        }

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::OPTION_PREFIX ) . '%'
            )
        );
    }

    /** @return string */
    private function option_prefix( $job_id ) {
        return self::OPTION_PREFIX . substr( hash( 'sha256', (string) $job_id ), 0, 24 ) . '_';
    }

    /** @return string[] */
    private function normalize_urls( array $urls ) {
        $normalized = [];

        foreach ( $urls as $url ) {
            $url = is_string( $url ) ? esc_url_raw( $url ) : '';

            if ( '' !== $url ) {
                $normalized[ hash( 'sha256', $url ) ] = $url;
            }
        }

        return array_values( $normalized );
    }

    /** @return array<string, true> */
    private function stored_hashes( $job_id ) {
        global $wpdb;

        $prefix = $this->option_prefix( $job_id );
        $rows   = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $prefix ) . '%'
            )
        );
        $hashes = [];

        foreach ( $rows as $serialized ) {
            $chunk = maybe_unserialize( $serialized );
            $urls  = is_array( $chunk ) && isset( $chunk['urls'] ) && is_array( $chunk['urls'] ) ? $chunk['urls'] : [];

            foreach ( array_keys( $urls ) as $hash ) {
                $hashes[ $hash ] = true;
            }
        }

        return $hashes;
    }
}
