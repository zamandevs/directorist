<?php

namespace Directorist\Cache;

/**
 * Stable semantic mutation types used by invalidation planning.
 */
final class Change_Type {
    const LISTING   = 'listing';
    const TERM      = 'term';
    const DIRECTORY = 'directory';
    const AUTHOR    = 'author';
    const PAGE      = 'page';
    const SETTINGS  = 'settings';
    const TEMPLATE  = 'template';
    const REVIEW    = 'review';
    const EXTENSION = 'extension';

    /** @return string[] */
    public static function all() {
        return [
            self::LISTING,
            self::TERM,
            self::DIRECTORY,
            self::AUTHOR,
            self::PAGE,
            self::SETTINGS,
            self::TEMPLATE,
            self::REVIEW,
            self::EXTENSION,
        ];
    }

    /**
     * @param string $type Change type.
     * @return bool
     */
    public static function is_valid( $type ) {
        return in_array( sanitize_key( (string) $type ), self::all(), true );
    }

    /**
     * @param string     $type Change type.
     * @param int|string $identifier Entity identifier.
     * @return int|string
     */
    public static function normalize_identifier( $type, $identifier ) {
        if ( in_array( $type, [ self::LISTING, self::TERM, self::DIRECTORY, self::AUTHOR, self::PAGE, self::REVIEW ], true ) ) {
            return absint( $identifier );
        }

        $parts = [];

        foreach ( explode( ':', (string) $identifier ) as $part ) {
            $part = sanitize_key( $part );

            if ( '' !== $part ) {
                $parts[] = $part;
            }
        }

        return implode( ':', $parts );
    }
}
