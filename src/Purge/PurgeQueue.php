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
     * Purge everything wins over tags; URLs are always sent. Does nothing outside the enabled
     * environments.
     *
     * @return bool false when the CDN did not accept the purge (logged with what was lost); true when
     *              it did, or when there was nothing to send
     */
    public function flush(): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        $pending = $this->pending();
        $this->reset();

        $edge = EdgeCache::singleton();
        if (!$edge->isEnvironmentEnabled()) {
            return true;
        }

        $failed = [];
        try {
            $adapter = $edge->adapter();
            // Purging everything covers every tag; URLs still go out, since a file or a response this
            // module never tagged does not carry the site tag.
            if ($pending['everything']) {
                if (!$adapter->purgeEverything()) {
                    $failed[] = 'everything';
                }
            } elseif ($pending['tags'] && !$adapter->purgeTags($pending['tags'])) {
                $failed[] = 'tags';
            }
            if ($pending['urls'] && !$adapter->purgeUrls($pending['urls'])) {
                $failed[] = 'urls';
            }
        } catch (Throwable $e) {
            // A failed purge must never break a publish.
            $this->logFailure($pending, ['exception' => $e::class . ': ' . $e->getMessage()]);

            return false;
        }

        if ($failed) {
            $this->logFailure($pending, ['failed' => $failed]);
        }

        return !$failed;
    }

    /**
     * The purge did not reach the CDN, so pages that changed stay cached until their edge lifetime
     * ends. Record what was lost so it can be purged by hand (edge-cache-purge).
     *
     * @param array{everything: bool, tags: string[], urls: string[]} $pending
     * @param array<string, mixed> $context
     */
    protected function logFailure(array $pending, array $context): void
    {
        try {
            Injector::inst()->get(LoggerInterface::class)->error(
                'Edge cache purge did not complete; changed pages stay cached until the edge lifetime ends. '
                . 'Purge by hand with: sake dev/tasks/edge-cache-purge',
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
