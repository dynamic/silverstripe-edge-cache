<?php

namespace Dynamic\EdgeCache\Purge;

use Dynamic\EdgeCache\EdgeCache;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Throwable;

/**
 * What the CDN refused to purge, kept so a later request or `sake tasks:edge-cache-purge --retry`
 * can send it again. Without it a purge lost to a rate limit or an outage leaves the changed pages
 * at the edge until their lifetime ends, with only a log line to say so.
 *
 * Lives in the `EdgeCachePurgeBacklog` cache (the filesystem under the temp folder by default),
 * which a `?flush` does not clear. That folder belongs to the operating-system user, so run the
 * retry as the user that serves the site, or point the cache at a shared `directory` or backend.
 * An entry older than the edge lifetime is dropped: the pages it covers have expired anyway.
 */
class PurgeBacklog
{
    use Configurable;
    use Injectable;

    private const KEY = 'backlog';

    /**
     * More waiting tags than this are replaced by one purge of everything, so the backlog stays small.
     *
     * @config
     * @var int
     */
    private static $max_tags = 200;

    /**
     * Add what failed to what is already waiting.
     *
     * @param array{everything: bool, tags: string[], urls: string[]} $lost
     * @param int|null $firstFailed when the oldest of these purges first failed (a retry passes it back)
     * @param int $attempts how many times these purges have been sent and failed
     * @return bool false when the backlog could not be written, so the purge is only in the log
     */
    public function store(array $lost, ?int $firstFailed = null, int $attempts = 1): bool
    {
        try {
            $now = time();
            $waiting = $this->read()
                ?? ['everything' => false, 'tags' => [], 'urls' => [], 'firstFailed' => $now, 'attempts' => 0];

            $everything = $waiting['everything'] || $lost['everything'];
            $tags = array_values(array_unique(array_merge($waiting['tags'], $lost['tags'])));
            if (count($tags) > (int) static::config()->get('max_tags')) {
                $everything = true;
            }

            return $this->cache()->set(self::KEY, [
                'everything' => $everything,
                // Purging everything covers every tag.
                'tags' => $everything ? [] : $tags,
                'urls' => array_values(array_unique(array_merge($waiting['urls'], $lost['urls']))),
                'firstFailed' => min($waiting['firstFailed'], $firstFailed ?? $now),
                'lastFailed' => $now,
                'attempts' => max($waiting['attempts'], $attempts),
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The waiting purges, emptying the backlog. Null when there are none or they are older than the
     * edge lifetime.
     *
     * @return array{everything: bool, tags: string[], urls: string[], firstFailed: int, lastFailed: int, attempts: int}|null
     */
    public function take(): ?array
    {
        $waiting = $this->read();
        if ($waiting !== null) {
            $this->clear();
        }

        return $waiting;
    }

    /**
     * The waiting purges without emptying the backlog, for the status task.
     *
     * @return array{everything: bool, tags: string[], urls: string[], firstFailed: int, lastFailed: int, attempts: int}|null
     */
    public function summary(): ?array
    {
        return $this->read();
    }

    public function clear(): void
    {
        try {
            $this->cache()->delete(self::KEY);
        } catch (Throwable) {
            // Nothing to clear if the cache cannot be reached.
        }
    }

    /**
     * @return array{everything: bool, tags: string[], urls: string[], firstFailed: int, lastFailed: int, attempts: int}|null
     */
    private function read(): ?array
    {
        try {
            $waiting = $this->cache()->get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($waiting) || !isset($waiting['firstFailed'])) {
            return null;
        }
        // The pages a purge this old covered have expired from the edge by now.
        if (time() - (int) $waiting['firstFailed'] > (int) EdgeCache::config()->get('edge_ttl')) {
            return null;
        }

        return $waiting + [
            'everything' => false,
            'tags' => [],
            'urls' => [],
            'lastFailed' => (int) $waiting['firstFailed'],
            'attempts' => 1,
        ];
    }

    private function cache(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.EdgeCachePurgeBacklog');
    }
}
