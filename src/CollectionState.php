<?php

namespace Dynamic\EdgeCache;

/**
 * Whether the current request is recording queried classes as cache tags. A plain static so the
 * query hook, which runs for every query, costs one property read when nothing is recording.
 */
final class CollectionState
{
    private static bool $active = false;

    public static function isActive(): bool
    {
        return self::$active;
    }

    public static function set(bool $active): void
    {
        self::$active = $active;
    }
}
