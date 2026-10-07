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
     * Send what is queued to the adapter and empty the queue. Safe to call more than once.
     * Does nothing outside the enabled environments.
     */
    public function flush(): void
    {
        if ($this->isEmpty()) {
            return;
        }

        $pending = $this->pending();
        $this->reset();

        $edge = EdgeCache::singleton();
        if (!$edge->isEnvironmentEnabled()) {
            return;
        }

        try {
            $adapter = $edge->adapter();
            if ($pending['everything']) {
                $adapter->purgeEverything();

                return;
            }
            if ($pending['tags']) {
                $adapter->purgeTags($pending['tags']);
            }
            if ($pending['urls']) {
                $adapter->purgeUrls($pending['urls']);
            }
        } catch (Throwable $e) {
            // A failed purge must never break a publish.
            Injector::inst()->get(LoggerInterface::class)->error('Edge cache purge failed: ' . $e->getMessage());
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
