<?php

namespace Dynamic\EdgeCache;

use SilverStripe\ORM\DataQuery;
use WeakMap;

/**
 * Whether the current request is recording queried classes as cache tags. A plain static so the
 * query hook, which runs for every query, costs one property read when nothing is recording.
 */
final class CollectionState
{
    private static bool $active = false;

    /**
     * How many edge-cache middleware calls are open. A request made while a page renders (a
     * module calling Director::test()) passes through the middleware again; only the outermost
     * request owns the page's tags.
     */
    private static int $depth = 0;

    /**
     * Queries Silverstripe built to lazy-load one record's subclass fields. The query hook skips
     * them: they are about a record the page already holds, not a list the page shows.
     *
     * @var WeakMap<DataQuery, true>|null
     */
    private static ?WeakMap $lazy = null;

    /**
     * @var WeakMap<DataQuery, string|null>|null
     */
    private static ?WeakMap $added = null;

    public static function isActive(): bool
    {
        return self::$active;
    }

    public static function set(bool $active): void
    {
        self::$active = $active;
    }

    public static function depth(): int
    {
        return self::$depth;
    }

    public static function enter(): void
    {
        self::$depth++;
    }

    public static function leave(): void
    {
        self::$depth = max(0, self::$depth - 1);
    }

    public static function markLazy(DataQuery $query): void
    {
        self::$lazy ??= new WeakMap();
        self::$lazy[$query] = true;
    }

    /**
     * Remember which tag the first query hook for this DataQuery added, if it added one.
     * Silverstripe builds a lazy-load query (DataObject::loadLazyFields) before it announces it is
     * one, so the hook has already recorded it by the time we learn what it was.
     */
    public static function rememberAdded(DataQuery $query, ?string $tag): void
    {
        self::$added ??= new WeakMap();
        self::$added[$query] = $tag;
    }

    public static function takeAdded(DataQuery $query): ?string
    {
        $tag = self::$added[$query] ?? null;
        if (self::$added !== null && isset(self::$added[$query])) {
            unset(self::$added[$query]);
        }

        return $tag;
    }

    public static function isLazy(DataQuery $query): bool
    {
        return self::$lazy !== null && isset(self::$lazy[$query]);
    }
}
