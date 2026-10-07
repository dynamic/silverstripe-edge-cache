# Extending

## A page that lists other records

A home page showing recent posts is cached with the page's own tag, so publishing a post does not reach it. Declare the dependency on the page or element class:

```php
private static $edge_cache_depends_on = [BlogPost::class];
```

Publishing a `BlogPost` purges every page whose tags include `ec-class-BlogPost`. Add other tags from a controller with `updateEdgeCacheTags`.

## Other records

```yaml
Vendor\Model\Testimonial:
  extensions:
    - Dynamic\EdgeCache\Extension\EdgeCachePurgeable
  edge_cache_purge: everything          # or a list of page classes
```

## Another CDN

Implement `Dynamic\EdgeCache\Adapter\EdgeCacheAdapter` and register it through Injector:

| Method | Purpose |
|---|---|
| `isConfigured()` | Credentials present. False keeps the site on its normal headers |
| `edgeHeaders(EdgePolicy)` | The header(s) your edge reads for lifetime (Fastly: `Surrogate-Control`) |
| `tagHeaderName()`, `formatTags()` | Where tags go and how they are joined (Fastly: `Surrogate-Key`, space separated) |
| `allowedVary()` | `Vary` values the edge tolerates while still caching, or null. Declared for adapters whose edge refuses to cache a response with any other `Vary` (Imperva allows only `Accept-Encoding`); the module does not yet strip `Vary` itself, so such an adapter needs that handling added |
| `purgeTags()`, `purgeUrls()`, `purgeEverything()` | Purge calls. Return false on failure; never throw |
