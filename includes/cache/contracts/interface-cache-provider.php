<?php

namespace Directorist\Cache;

/**
 * Common boundary between Directorist and an active page-cache provider.
 */
interface Cache_Provider {
    /**
     * Return the stable provider identifier.
     *
     * @return string
     */
    public function get_id();

    /**
     * Return whether this provider can operate in the current installation.
     *
     * @return bool
     */
    public function is_available();

    /**
     * Return supported operation identifiers.
     *
     * @return string[]
     */
    public function get_capabilities();

    /**
     * Check one operation identifier.
     *
     * @param string $capability Operation identifier.
     * @return bool
     */
    public function supports( $capability );

    /**
     * Apply a normalized Directorist invalidation request.
     *
     * @param array $request Invalidation data.
     * @return array Operation result.
     */
    public function invalidate( array $request );

    /**
     * Warm normalized public URLs.
     *
     * @param string[] $urls Public URLs.
     * @return array Operation result.
     */
    public function warm( array $urls );

    /**
     * Return provider health and capability data.
     *
     * @return array
     */
    public function get_status();
}
