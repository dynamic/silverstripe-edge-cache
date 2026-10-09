<?php

namespace Dynamic\EdgeCache\Purge;

use Dynamic\EdgeCache\EdgeCache;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Throwable;

/**
 * What the CDN refused to purge, kept so a later request or `sake dev/tasks/edge-cache-purge retry=1`
 * can send it again. Without it a purge lost to a rate limit or an outage leaves the changed pages
 * at the edge until their lifetime ends, with only a log line to say so.
 *
 * Lives in the `EdgeCachePurgeBacklog` cache, a plain filesystem cache under the temp folder (not the
 * Versioned-aware one, whose keys differ by reading mode, so the CMS, the front end and `sake` would
 * each see a different backlog). A `?flush` does not clear it. The folder belongs to the operating-system
 * user, so run the retry as the user that serves the site, or point the cache at a shared `directory`
 * or backend. Each tag and URL is dropped once it has waited longer than the edge lifetime: the pages
 * it covered have expired anyway.
 *
 * @phpstan-type Stored array{
 *     everything: int|null, tags: array<string, int>, urls: array<string, int>,
 *     firstFailed: int, lastFailed: int, attempts: int, version: string
 * }
 * @phpstan-type Waiting array{
 *     everything: bool, tags: string[], urls: string[],
 *     firstFailed: int, lastFailed: int, attempts: int, version: string
 * }
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
     * What is waiting, or null when nothing is. `version` changes on every write, so a sender can tell
     * whether the backlog moved while it was sending.
     *
     * @return Waiting|null
     */
    public function peek(): ?array
    {
        $waiting = $this->read();
        if ($waiting === null) {
            return null;
        }

        return [
            'everything' => $waiting['everything'] !== null,
            'tags' => array_keys($waiting['tags']),
            'urls' => array_keys($waiting['urls']),
            'firstFailed' => $waiting['firstFailed'],
            'lastFailed' => $waiting['lastFailed'],
            'attempts' => $waiting['attempts'],
            'version' => $waiting['version'],
        ];
    }

    /**
     * Record the outcome of a send that started from `$seen` (what peek() returned, or null): `$lost`
     * is what the CDN refused. Empty means everything went through, so the entry is removed, unless
     * another request stored something in the meantime, which stays. Items that failed keep the time
     * they first failed.
     *
     * @param array{everything: bool, tags: string[], urls: string[], version?: string, attempts?: int}|null $seen
     * @param array{everything: bool, tags: string[], urls: string[]} $lost
     * @return bool false when the backlog could not be written, so a failed purge is only in the log
     */
    public function settle(?array $seen, array $lost): bool
    {
        try {
            return $this->locked(function () use ($seen, $lost): bool {
                $current = $this->read();
                $unchanged = ($seen === null && $current === null)
                    || ($seen !== null && $current !== null && ($seen['version'] ?? null) === $current['version']);

                if (!$lost['everything'] && !$lost['tags'] && !$lost['urls']) {
                    if ($unchanged && $current !== null) {
                        $this->cache()->delete(self::KEY);
                    }

                    return true;
                }

                // Items keep the time they first failed. A backlog that moved while sending keeps what the
                // other request stored; otherwise only what failed again stays.
                $now = time();
                $base = $unchanged || $current === null ? $this->blank() : $current;
                foreach ($lost['tags'] as $tag) {
                    $base['tags'][$tag] ??= $current['tags'][$tag] ?? $now;
                }
                foreach ($lost['urls'] as $url) {
                    $base['urls'][$url] ??= $current['urls'][$url] ?? $now;
                }
                if ($lost['everything']) {
                    $base['everything'] ??= $current['everything'] ?? $now;
                }
                if ($base['everything'] !== null || count($base['tags']) > (int) static::config()->get('max_tags')) {
                    // Purging everything covers every tag.
                    $base['everything'] ??= $now;
                    $base['tags'] = [];
                }

                $base['firstFailed'] = $current['firstFailed'] ?? $now;
                $base['lastFailed'] = $now;
                $base['attempts'] = max($base['attempts'], (int) ($seen['attempts'] ?? 0) + 1);
                $base['version'] = uniqid('', true);

                return $this->cache()->set(self::KEY, $base);
            });
        } catch (Throwable) {
            return false;
        }
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
     * @return Stored
     */
    private function blank(): array
    {
        $now = time();

        return [
            'everything' => null,
            'tags' => [],
            'urls' => [],
            'firstFailed' => $now,
            'lastFailed' => $now,
            'attempts' => 0,
            'version' => '',
        ];
    }

    /**
     * What is stored, without the tags and URLs that have waited longer than the edge lifetime.
     *
     * @return Stored|null
     */
    private function read(): ?array
    {
        try {
            $stored = $this->cache()->get(self::KEY);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($stored) || !isset($stored['firstFailed'])) {
            return null;
        }

        $oldest = time() - (int) EdgeCache::config()->get('edge_ttl');
        $waiting = $stored + $this->blank();
        $waiting['tags'] = array_filter((array) $waiting['tags'], fn ($since) => $since >= $oldest);
        $waiting['urls'] = array_filter((array) $waiting['urls'], fn ($since) => $since >= $oldest);
        if ($waiting['everything'] !== null && $waiting['everything'] < $oldest) {
            $waiting['everything'] = null;
        }

        return $waiting['everything'] === null && !$waiting['tags'] && !$waiting['urls'] ? null : $waiting;
    }

    /**
     * Runs the callback holding a lock on a file next to the cache, so two requests that fail at the
     * same moment do not overwrite each other's entry. Without a lock file it runs unlocked.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        $handle = defined('TEMP_PATH') ? @fopen(TEMP_PATH . '/edge-cache-purge-backlog.lock', 'c') : false;
        if ($handle && !flock($handle, LOCK_EX)) {
            fclose($handle);
            $handle = false;
        }

        try {
            return $callback();
        } finally {
            if ($handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function cache(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.EdgeCachePurgeBacklog');
    }
}
