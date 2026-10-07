# Extending

## Pages that list other records

A page is tagged with every class it queries while it renders. A home page that shows recent posts, a team page, a testimonials block: each query adds a class tag (`ec-class-BlogPost`), so publishing a post purges every page that listed posts. Nothing needs declaring for those.

Some classes are never tagged because every page queries them: `Page` and `SiteTree` (navigation and the page load), and elements, element areas, `SiteConfig`, files and members including all their subclasses. Change the lists with `EdgeCache.auto_tag_ignore` (exact class names, so a subclass such as `BlogPost` is still tagged) and `EdgeCache.auto_tag_ignore_descendants` (the class and every subclass).

When the page itself is the record Silverstripe lazy-loads subclass fields for (`$Summary` on a blog post), that is not a tag: it would tie every blog post to every other. The same read on some other record, such as a listed post, is.

Publishing a page purges its own tag, its ancestors' tags and the class tags of its class chain. Plain pages never purge each other.

### What is not tracked

The module sees database queries made while the page renders. These cases need a declaration, or accept up to `edge_ttl` (6 hours by default) of staleness:

- **Listings through an ignored class.** `$Children`, `SiteTree::get()->filter(...)`, `Page::get()`, `File::get()` and `Member::get()` query a class the module ignores, so a home page showing "latest news" through `$NewsHolder.Children` is not tagged for those pages. Publishing a child purges the holder's own tag, so depend on the holder:

  ```php
  // in the page's controller init(), or an extension
  EdgeCache::singleton()->addTags(EdgeCache::pageTag($newsHolder->ID));
  ```

  A page that lists every page (a sitemap) declares the class instead:

  ```php
  private static $edge_cache_depends_on = [SiteTree::class];
  ```

- **Content behind a partial or application cache.** A `<% cached %>` block or a PSR-16 cache that serves a listing runs no query while it is warm, so the page is stamped without that class tag. Declare the dependency with `edge_cache_depends_on` (or an `updateEdgeCacheTags` hook) for any such block.
- **`edge_cache_purge` lists.** Entries add class tags to the purge, but only pages that queried or declared that class carry them. A page class named in the list that never queried it is not purged.

A page whose tags exceed `EdgeCache.max_tags` (150) is not edge-cached. It is served from the origin with `Cache-Control: private, must-revalidate` (so nothing caches it untagged), and one warning an hour per URL names the first tags that put it over.

A block that orders randomly (random testimonials) is rendered once and then served from the edge until the next purge, so every visitor sees the same pick.

### Seeing a page's tags

Cloudflare strips `Cache-Tag` before the response reaches a visitor, so ask the origin directly:

```
curl -sI --resolve www.example.com:443:ORIGIN_IP https://www.example.com/page | grep -i cache-tag
```

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

It has no effect on a class in the ignore lists (files, members, elements): no page carries a tag for those, and files and elements purge through their own hooks. It is opt-in per class on purpose: a purge for every write to every record would send API calls for form submissions and sessions, and Cloudflare's Free plan allows five tag purges a minute.

## Another CDN

Implement `Dynamic\EdgeCache\Adapter\EdgeCacheAdapter` and register it through Injector:

| Method | Purpose |
|---|---|
| `isConfigured()` | Credentials present. False keeps the site on its normal headers |
| `edgeHeaders(EdgePolicy)` | The header(s) your edge reads for lifetime (Fastly: `Surrogate-Control`) |
| `tagHeaderName()`, `formatTags()` | Where tags go and how they are joined (Fastly: `Surrogate-Key`, space separated) |
| `allowedVary()` | `Vary` values the edge tolerates while still caching, or null for no restriction. When it returns a list (Imperva allows only `Accept-Encoding`), the middleware removes every other `Vary` value from responses it hands to the edge. That is safe only when no other variant of a URL is ever cached. Here Markdown is `no-store`, Ajax responses are never public, and the site serves one scheme, but check that holds for the site before relying on it |
| `purgeTags()`, `purgeUrls()`, `purgeEverything()` | Purge calls. Return false on failure; never throw |
