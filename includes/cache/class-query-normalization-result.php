<?php

namespace Directorist\Cache;

/**
 * Immutable result of normalizing public cache-key query variation.
 */
final class Query_Normalization_Result {
    /** @var bool */
    private $valid;

    /** @var array */
    private $args;

    /** @var string */
    private $reason;

    /** @var string */
    private $detail;

    /**
     * @param bool   $valid Whether the query can be represented safely.
     * @param array  $args Normalized query arguments.
     * @param string $reason Stable decision reason.
     * @param string $detail Optional non-sensitive detail.
     */
    public function __construct( $valid, array $args = [], $reason = '', $detail = '' ) {
        $this->valid  = (bool) $valid;
        $this->args   = $args;
        $this->reason = (string) $reason;
        $this->detail = (string) $detail;
    }

    /** @return bool */
    public function is_valid() {
        return $this->valid;
    }

    /** @return array */
    public function get_args() {
        return $this->args;
    }

    /** @return string */
    public function get_reason() {
        return $this->reason;
    }

    /** @return string */
    public function get_detail() {
        return $this->detail;
    }
}
