<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Adapter\EdgeCacheAdapter;
use Dynamic\EdgeCache\Policy\EdgePolicy;
use SilverStripe\Dev\TestOnly;

/**
 * A configured adapter that records purges instead of sending them.
 */
class RecordingAdapter implements EdgeCacheAdapter, TestOnly
{
    /**
     * @var array<int, array{string, array}>
     */
    public array $calls = [];

    public bool $fail = false;

    public bool $returnFalse = false;

    /**
     * Purge kinds ('tags', 'urls', 'everything') the CDN refuses while accepting the rest.
     *
     * @var string[]
     */
    public array $refuses = [];

    /**
     * Called as each purge is sent, to see what the module has stored at that moment.
     *
     * @var callable|null
     */
    public $whileSending = null;

    /**
     * @var string[]|null
     */
    public ?array $allowedVary = null;

    public bool $configured = true;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function edgeHeaders(EdgePolicy $policy): array
    {
        return ['Edge-Cache-Control' => 'max-age=' . $policy->getEdgeTtl()];
    }

    public function tagHeaderName(): ?string
    {
        return 'Cache-Tag';
    }

    public function formatTags(array $tags): string
    {
        return implode(',', $tags);
    }

    public function allowedVary(): ?array
    {
        return $this->allowedVary;
    }

    public function verify(): array
    {
        return $this->returnFalse
            ? ['ok' => false, 'message' => 'Cloudflare answered 403: not allowed']
            : ['ok' => true, 'message' => 'Accepted.'];
    }

    public function purgeTags(array $tags): bool
    {
        return $this->record('tags', $tags);
    }

    public function purgeUrls(array $urls): bool
    {
        return $this->record('urls', $urls);
    }

    public function purgeEverything(): bool
    {
        return $this->record('everything', []);
    }

    private function record(string $what, array $items): bool
    {
        if ($this->fail) {
            throw new \RuntimeException('CDN down');
        }
        $this->calls[] = [$what, $items];
        if ($this->whileSending) {
            ($this->whileSending)($what, $items);
        }

        return !$this->returnFalse && !in_array($what, $this->refuses, true);
    }
}
