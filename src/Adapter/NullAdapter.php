<?php

namespace Dynamic\EdgeCache\Adapter;

use Dynamic\EdgeCache\Policy\EdgePolicy;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * Does nothing but log. The default, so a site with no CDN configured keeps its normal headers.
 */
class NullAdapter implements EdgeCacheAdapter
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function edgeHeaders(EdgePolicy $policy): array
    {
        return [];
    }

    public function tagHeaderName(): ?string
    {
        return null;
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
        return $this->log('tags', $tags);
    }

    public function purgeUrls(array $urls): bool
    {
        return $this->log('urls', $urls);
    }

    public function purgeEverything(): bool
    {
        return $this->log('everything', []);
    }

    private function log(string $what, array $items): bool
    {
        Injector::inst()->get(LoggerInterface::class)->debug(
            sprintf('Edge cache purge skipped (no adapter configured): %s %s', $what, implode(' ', $items))
        );

        return true;
    }
}
