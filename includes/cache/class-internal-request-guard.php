<?php

namespace Directorist\Cache;

/**
 * Recognizes authenticated Directorist cache-control worker requests.
 */
final class Internal_Request_Guard {
    /** @var callable */
    private $nonce_verifier;

    /**
     * @param callable|null $nonce_verifier Nonce verification boundary.
     */
    public function __construct( $nonce_verifier = null ) {
        $this->nonce_verifier = is_callable( $nonce_verifier ) ? $nonce_verifier : 'wp_verify_nonce';
    }

    /**
     * @param array $request Request data.
     * @param bool  $doing_ajax AJAX execution state.
     * @param int   $site_id Current site ID.
     * @return bool
     */
    public function is_control_request( array $request, $doing_ajax, $site_id ) {
        if ( ! $doing_ajax ) {
            return false;
        }

        $action = isset( $request['action'] ) && is_scalar( $request['action'] )
            ? sanitize_key( (string) $request['action'] )
            : '';
        $nonce  = isset( $request['nonce'] ) && is_scalar( $request['nonce'] )
            ? (string) $request['nonce']
            : '';
        $prefix = 'wp_' . max( 1, absint( $site_id ) ) . '_directorist_page_cache_';

        $allowed = [
            $prefix . 'warm',
            $prefix . 'cleanup',
            'wp_' . max( 1, absint( $site_id ) ) . '_' . Performance_Job_Process::ACTION,
            'wp_' . max( 1, absint( $site_id ) ) . '_' . Performance_Resource_Process::ACTION,
        ];

        if ( '' === $nonce || ! in_array( $action, $allowed, true ) ) {
            return false;
        }

        try {
            return (bool) call_user_func( $this->nonce_verifier, $nonce, $action );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }
    }
}
