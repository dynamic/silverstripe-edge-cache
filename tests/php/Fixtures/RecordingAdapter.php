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

    public function isConfigured(): bool
    {
        return true;
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
        return null;
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

        return true;
    }
}
