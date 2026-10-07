# Extending

## Pages that list other records

A page is tagged with every class it queries while it renders. A home page that shows recent posts, a team page, a testimonials block: each query adds a class tag (`ec-class-BlogPost`), so publishing a post purges every page that listed posts. There is nothing to declare.

Some classes are never tagged because every page queries them (navigation and the page load): `Page`, `SiteTree`, elements and element areas, `SiteConfig`, files, members. Change the list with `EdgeCache.auto_tag_ignore` (exact class names, so a subclass such as `BlogPost` is still tagged). Publishing a page purges its own tag, its ancestors' tags and the class tags of its class chain, so plain pages never purge each other.

A page that depends on something it does not query declares it. A sitemap lists every page but queries `SiteTree`, which is ignored:

```php
private static $edge_cache_depends_on = [SiteTree::class];
```

An element can declare the same way. A page whose tags exceed `EdgeCache.max_tags` (150) is not edge-cached and a warning is logged, rather than being cached with a partial set.

A block that orders randomly (random testimonials) is rendered once and then served from the edge until the next purge, so every visitor sees the same pick.

## Other records

Records that pages list but that are not pages (testimonials, staff, sponsors) purge when you opt the class in:

```yaml
Vendor\Model\Testimonial:
  extensions:
    - Dynamic\EdgeCache\Extension\EdgeCachePurgeable
```

Saving or deleting one (publishing, if it is Versioned) purges the pages that listed its class. A record shown on every page, such as footer links, clears the whole site instead:

```yaml
Vendor\Model\FooterLink:
  extensions:
    - Dynamic\EdgeCache\Extension\EdgeCachePurgeable
  edge_cache_purge: everything
```

It is opt-in per class on purpose: a purge for every write to every record would send API calls for form submissions and sessions, and Cloudflare's Free plan allows five tag purges a minute.

## Another CDN

Implement `Dynamic\EdgeCache\Adapter\EdgeCacheAdapter` and register it through Injector:

| Method | Purpose |
|---|---|
| `isConfigured()` | Credentials present. False keeps the site on its normal headers |
| `edgeHeaders(EdgePolicy)` | The header(s) your edge reads for lifetime (Fastly: `Surrogate-Control`) |
| `tagHeaderName()`, `formatTags()` | Where tags go and how they are joined (Fastly: `Surrogate-Key`, space separated) |
| `allowedVary()` | `Vary` values the edge tolerates while still caching, or null. Declared for adapters whose edge refuses to cache a response with any other `Vary` (Imperva allows only `Accept-Encoding`); the module does not yet strip `Vary` itself, so such an adapter needs that handling added |
| `purgeTags()`, `purgeUrls()`, `purgeEverything()` | Purge calls. Return false on failure; never throw |
