<?php

namespace Directorist\Cache;

/**
 * Immutable provider-selection result.
 */
final class Provider_Selection {
    /** @var Cache_Provider|null */
    private $provider;

    /** @var string */
    private $code;

    /** @var string[] */
    private $candidate_ids;

    /** @var string */
    private $dropin_owner;

    /**
     * @param Cache_Provider|null $provider Selected provider.
     * @param string              $code Selection result code.
     * @param string[]            $candidate_ids Available provider IDs.
     * @param string              $dropin_owner Detected drop-in owner.
     */
    public function __construct( Cache_Provider $provider = null, $code = 'no_available_provider', array $candidate_ids = [], $dropin_owner = 'none' ) {
        $this->provider      = $provider;
        $this->code          = sanitize_key( (string) $code );
        $this->candidate_ids = array_values( array_unique( array_map( 'sanitize_key', $candidate_ids ) ) );
        $this->dropin_owner  = sanitize_key( (string) $dropin_owner );
    }

    /** @return bool */
    public function is_selected() {
        return $this->provider instanceof Cache_Provider;
    }

    /** @return Cache_Provider|null */
    public function get_provider() {
        return $this->provider;
    }

    /** @return string */
    public function get_code() {
        return $this->code;
    }

    /** @return string[] */
    public function get_candidate_ids() {
        return $this->candidate_ids;
    }

    /** @return string */
    public function get_dropin_owner() {
        return $this->dropin_owner;
    }

    /** @return array */
    public function to_array() {
        return [
            'selected'      => $this->is_selected(),
            'provider'      => $this->is_selected() ? $this->provider->get_id() : 'none',
            'code'          => $this->code,
            'candidate_ids' => $this->candidate_ids,
            'dropin_owner'  => $this->dropin_owner,
        ];
    }
}
