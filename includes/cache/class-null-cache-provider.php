<?php

namespace Directorist\Cache;

/**
 * Explicit no-op provider used when no compatible cache is selected.
 */
final class Null_Cache_Provider implements Cache_Provider {
    /** @return string */
    public function get_id() {
        return 'none';
    }

    /** @return bool */
    public function is_available() {
        return false;
    }

    /** @return array */
    public function get_capabilities() {
        return [];
    }

    /**
     * @param string $capability Operation identifier.
     * @return bool
     */
    public function supports( $capability ) {
        unset( $capability );

        return false;
    }

    /**
     * @param array $request Invalidation data.
     * @return array
     */
    public function invalidate( array $request ) {
        unset( $request );

        return $this->no_provider_result();
    }

    /**
     * @param array $urls Public URLs.
     * @return array
     */
    public function warm( array $urls ) {
        unset( $urls );

        return $this->no_provider_result();
    }

    /** @return array */
    public function get_status() {
        return [
            'id'           => $this->get_id(),
            'available'    => $this->is_available(),
            'capabilities' => $this->get_capabilities(),
        ];
    }

    /** @return array */
    private function no_provider_result() {
        return [
            'success' => false,
            'code'    => 'no_provider',
        ];
    }
}
