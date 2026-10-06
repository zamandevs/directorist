<?php

namespace Directorist\Cache;

/**
 * Immutable result of a page-cache request-policy decision.
 */
final class Eligibility_Result {
    const ELIGIBLE             = 'eligible';
    const CACHE_DISABLED       = 'cache_disabled';
    const UNSAFE_METHOD        = 'unsafe_method';
    const UNKNOWN_ROUTE        = 'unknown_route';
    const AUTHENTICATED_USER   = 'authenticated_user';
    const PRIVATE_CONTEXT      = 'private_context';
    const AUTHORIZATION_HEADER = 'authorization_header';
    const BYPASS_HEADER        = 'bypass_header';
    const REJECTED_COOKIE      = 'rejected_cookie';
    const INVALID_COOKIE_VARIATION = 'invalid_cookie_variation';
    const UNSUPPORTED_QUERY    = 'unsupported_query';
    const FILTERED_RESULTS_DISABLED = 'filtered_results_disabled';
    const INTEGRATION_VETO     = 'integration_veto';

    /**
     * Whether the request passed policy.
     *
     * @var bool
     */
    private $eligible;

    /**
     * Stable decision reason.
     *
     * @var string
     */
    private $reason;

    /**
     * Optional non-sensitive decision detail.
     *
     * @var string
     */
    private $detail;

    /**
     * @param bool   $eligible Whether the request passed policy.
     * @param string $reason Stable decision reason.
     * @param string $detail Optional non-sensitive detail.
     */
    public function __construct( $eligible, $reason, $detail = '' ) {
        $this->eligible = (bool) $eligible;
        $this->reason   = (string) $reason;
        $this->detail   = (string) $detail;
    }

    /**
     * @return bool
     */
    public function is_eligible() {
        return $this->eligible;
    }

    /**
     * @return string
     */
    public function get_reason() {
        return $this->reason;
    }

    /**
     * @return string
     */
    public function get_detail() {
        return $this->detail;
    }
}
