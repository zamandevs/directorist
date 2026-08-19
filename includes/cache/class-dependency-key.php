<?php

namespace Directorist\Cache;

/**
 * Builds stable dependency keys shared by render collection and invalidation.
 */
final class Dependency_Key {
    /**
     * @param int        $site_id WordPress blog ID.
     * @param string     $domain Dependency domain.
     * @param int|string $identifier Optional identifier.
     * @return string
     */
    public static function build( $site_id, $domain, $identifier = '' ) {
        $domain = sanitize_key( (string) $domain );

        if ( '' === $domain ) {
            return '';
        }

        $parts = [ 'directorist', (string) max( 1, absint( $site_id ) ), $domain ];

        if ( '' !== (string) $identifier ) {
            foreach ( explode( ':', (string) $identifier ) as $part ) {
                $part = sanitize_key( $part );

                if ( '' !== $part ) {
                    $parts[] = $part;
                }
            }
        }

        return implode( ':', $parts );
    }
}
