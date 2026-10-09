<?php

namespace Dynamic\EdgeCache\Purge;

use Dynamic\EdgeCache\EdgeCache;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Injector\Injectable;
use Throwable;

/**
 * Collects what to purge while a request runs and sends it to the CDN once, at the end.
 *
 * A content-api batch that publishes fifty pages becomes one tag purge. Purge everything wins
 * over anything more specific. The queue flushes after the response has been sent where PHP-FPM
 * allows it, and on shutdown otherwise, so a slow CDN API never holds up an editor.
 */
class PurgeQueue
{
    use Injectable;

    /**
     * Logged at error level when the CDN does not accept a purge. Match on this to alert.
     */
    public const FAILURE_MESSAGE = 'Edge cache purge did not complete; changed pages stay cached until the retry succeeds '
        . 'or the edge lifetime ends. Retry with: sake tasks:edge-cache-purge --retry';

    /**
     * @var array<string, true>
     */
    private array $tags = [];

    /**
     * @var array<string, true>
     */
    private array $urls = [];

    private bool $everything = false;

    private bool $shutdownRegistered = false;

    /**
     * @param string|string[] $tags
     */
    public function addTags(string|array $tags): static
    {
        foreach ((array) $tags as $tag) {
            $this->tags[(string) $tag] = true;
        }

        return $this->queued();
    }

    /**
     * @param string|string[] $urls absolute URLs
     */
    public function addUrls(string|array $urls): static
    {
        foreach ((array) $urls as $url) {
            $this->urls[(string) $url] = true;
        }

        return $this->queued();
    }

    public function addEverything(): static
    {
        $this->everything = true;

        return $this->queued();
    }

    public function isEmpty(): bool
    {
        return !$this->everything && !$this->tags && !$this->urls;
    }

    /**
     * @return array{everything: bool, tags: string[], urls: string[]}
     */
    public function pending(): array
    {
        return [
            'everything' => $this->everything,
            'tags' => array_keys($this->tags),
            'urls' => array_keys($this->urls),
        ];
    }

    /**
     * Send what is queued, and anything an earlier purge left waiting in the backlog, to the adapter
     * and empty the queue. Safe to call more than once. Purge everything wins over tags; URLs are
     * always sent. Does nothing outside the enabled environments. What the CDN refuses goes back
     * into the backlog for the next flush or `edge-cache-purge --retry`.
     *
     * @return bool false when the CDN did not accept the purge (logged with what was lost); true when
     *              it did, or when there was nothing to send
     */
    public function flush(): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        return $this->send();
    }

    /**
     * Send only what an earlier purge left waiting in the backlog (and anything queued now).
     *
     * @return bool false when the CDN did not accept it, true when it did or nothing was waiting
     */
    public function retry(): bool
    {
        return $this->send();
    }

    private function send(): bool
    {
        $pending = $this->pending();
        $this->reset();

        $edge = EdgeCache::singleton();
        if (!$edge->isEnvironmentEnabled()) {
            return true;
        }

        $backlog = PurgeBacklog::singleton()->take();
        $firstFailed = null;
        $attempts = 0;
        if ($backlog !== null) {
            $firstFailed = $backlog['firstFailed'];
            $attempts = $backlog['attempts'];
            $pending = [
                'everything' => $pending['everything'] || $backlog['everything'],
                'tags' => array_values(array_unique(array_merge($pending['tags'], $backlog['tags']))),
                'urls' => array_values(array_unique(array_merge($pending['urls'], $backlog['urls']))),
            ];
        }
        if (!$pending['everything'] && !$pending['tags'] && !$pending['urls']) {
            return true;
        }

        $lost = ['everything' => false, 'tags' => [], 'urls' => []];
        $context = [];
        try {
            $adapter = $edge->adapter();
            // Purging everything covers every tag; URLs still go out, since a file or a response this
            // module never tagged does not carry the site tag.
            if ($pending['everything']) {
                if (!$adapter->purgeEverything()) {
                    $lost['everything'] = true;
                    $context['failed'][] = 'everything';
                }
            } elseif ($pending['tags'] && !$adapter->purgeTags($pending['tags'])) {
                $lost['tags'] = $pending['tags'];
                $context['failed'][] = 'tags';
            }
            if ($pending['urls'] && !$adapter->purgeUrls($pending['urls'])) {
                $lost['urls'] = $pending['urls'];
                $context['failed'][] = 'urls';
            }
        } catch (Throwable $e) {
            $lost = $pending;
            $context = ['exception' => $e::class . ': ' . $e->getMessage()];
        }

        if (!$lost['everything'] && !$lost['tags'] && !$lost['urls']) {
            return true;
        }

        $kept = PurgeBacklog::singleton()->store($lost, $firstFailed, $attempts + 1);
        $this->logFailure($pending, $context + [
            'retry' => $kept ? 'kept for a retry' : 'not kept: the backlog cache is unavailable',
        ]);

        return false;
    }

    /**
     * The purge did not reach the CDN, so pages that changed stay cached until a retry succeeds or
     * their edge lifetime ends. Record what was lost.
     *
     * The message is stable, so a log alert can match it.
     *
     * @param array{everything: bool, tags: string[], urls: string[]} $pending
     * @param array<string, mixed> $context
     */
    protected function logFailure(array $pending, array $context): void
    {
        try {
            Injector::inst()->get(LoggerInterface::class)->error(
                self::FAILURE_MESSAGE,
                $context + [
                    'everything' => $pending['everything'],
                    'tags' => array_slice($pending['tags'], 0, 30),
                    'urls' => array_slice($pending['urls'], 0, 30),
                ]
            );
        } catch (Throwable) {
            // The logger itself failed; there is nowhere left to report to.
        }
    }

    public function reset(): void
    {
        $this->tags = [];
        $this->urls = [];
        $this->everything = false;
    }

    protected function queued(): static
    {
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function () {
                if (!Director::is_cli() && function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $this->flush();
            });
        }

        return $this;
    }
}
