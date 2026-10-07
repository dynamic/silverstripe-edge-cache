<?php

namespace Dynamic\EdgeCache\Adapter;

use Dynamic\EdgeCache\Policy\EdgePolicy;

/**
 * One CDN or WAF. An adapter says which response headers its edge reads and how to purge it;
 * the module decides when.
 */
interface EdgeCacheAdapter
{
    /**
     * True when the adapter has what it needs to purge (credentials, zone). An unconfigured
     * adapter leaves a site on its normal cache headers and purges nothing.
     */
    public function isConfigured(): bool;

    /**
     * Headers that tell this edge how long to keep a page, as name => value. Sent in addition to
     * the browser-facing `Cache-Control`.
     *
     * @return array<string, string>
     */
    public function edgeHeaders(EdgePolicy $policy): array;

    /**
     * Name of the response header this edge reads cache tags from, or null if it has none.
     */
    public function tagHeaderName(): ?string;

    /**
     * Joins tags into one header value.
     *
     * @param string[] $tags
     */
    public function formatTags(array $tags): string;

    /**
     * Values this edge allows in `Vary` while still caching, or null for no restriction.
     *
     * @return string[]|null
     */
    public function allowedVary(): ?array;

    /**
     * Purge every page carrying one of these tags.
     *
     * @param string[] $tags
     */
    public function purgeTags(array $tags): bool;

    /**
     * Purge these absolute URLs.
     *
     * @param string[] $urls
     */
    public function purgeUrls(array $urls): bool;

    /**
     * Purge every cached page of the site (not its static assets).
     */
    public function purgeEverything(): bool;
}
