# Extending

## Pages that list other records

A page is tagged with every class it queries while it renders. A home page that shows recent posts, a team page, a testimonials block: each query adds a class tag (`ec-class-BlogPost`), so publishing a post purges every page that listed posts. Nothing needs declaring for those.

Some classes are never tagged because every page queries them: `Page` and `SiteTree` (navigation and the page load), and elements, element areas, `SiteConfig`, files and groups including all their subclasses. Members are tagged: a page that lists authors carries `ec-class-Member`, and a change to a member's `FirstName` or `Surname` (`Member.edge_cache_purge_fields`) or deleting one purges those pages. A login's writes (last visited, password hash) never purge. Only the listed fields count: add any other field a page shows to `Member.edge_cache_purge_fields`, naming a has_one by its column (`ImageID`, not `Image`). Adding or removing a member from a group, or a group from a member, purges them too. Change the lists with `EdgeCache.auto_tag_ignore` (exact class names, so a subclass such as `BlogPost` is still tagged) and `EdgeCache.auto_tag_ignore_descendants` (the class and every subclass).

When the page itself is the record Silverstripe lazy-loads subclass fields for (`$Summary` on a blog post), that is not a tag: it would tie every blog post to every other. The same read on some other record, such as a listed post, is.

Publishing a page purges its own tag, its ancestors' tags and the class tags of its class chain. Plain pages never purge each other.

#### A page base class that navigation reads

Silverstripe loads the fields a subclass adds (a menu icon, a summary) the first time a template reads one from a record that was hydrated through `SiteTree`. When every page of a site extends one base class (`App\Page\BasePage`) and the navigation reads one of its fields for each item, every page carries `ec-class-BasePage`, and publishing any page purges that class tag: the whole site, every time. The module's own `Page` and `SiteTree` are ignored for this reason; a site's own base class is not known to it. Add it:

```yaml
Dynamic\EdgeCache\EdgeCache:
  auto_tag_ignore_descendants:
    - App\Page\BasePage
```

The base class and every page class below it stop producing class tags from queries and lazy loads. Publishing a page then purges that page, its ancestors and the pages that list its children, and a change that moves it in the navigation still clears the site (see `structural_fields`). A page that lists records of a page subclass (an index of posts) can still declare the dependency with `private static $edge_cache_depends_on = [PostPage::class];`, which is not affected by the ignore list. Check a publish after adding it: the page and its parent should go `MISS`, and a page unrelated to it should stay `HIT`.

### Pages that list a page's children

`$Children` and `$AllChildren` query `SiteTree`, which is ignored, so a page that shows "latest news" from a news holder is not tagged for those pages. Opt the holder's class in and every page that reads its children carries `ec-children-<holder id>`; publishing any child purges that tag:

```yaml
App\Pages\NewsHolder:
  edge_cache_tag_children: true
```

It is off by default on purpose. A menu reads the children of every top-level page, even `<% if $Children %>` to decide whether to draw a dropdown, so tagging all of them would make an edit to any child purge every page that shows the menu. Turn it on only for a class whose children no menu reads and that is listed outside its own section; a news holder that sits in the main navigation should not opt in.

Only `$Children` and `$AllChildren` are seen. `liveChildren()` and a hand-written `SiteTree::get()->filter('ParentID', ...)` are not: depend on the holder's page tag (above) or declare the class. A child class with its own start and end times is not capped by `edge_cache_schedule_fields` through this tag; declare that class with `edge_cache_depends_on` as well. The holder's own page is purged by its page tag regardless, and a change to a child's title, URL or menu position clears the whole site.

### What is not tracked

The module sees database queries made while the page renders. These cases need a declaration, or accept up to `edge_ttl` (6 hours by default) of staleness:

- **Listings through an ignored class.** `SiteTree::get()->filter(...)`, `Page::get()`, `File::get()` query a class the module ignores, so a page showing a listing made that way is not tagged for those pages. A page listing a holder's children with `$Children` or `$AllChildren` can be tagged for them, see below. For anything else, depend on the holder (publishing a child purges the holder's own tag):

  ```php
  // in the page's controller init(), or an extension
  EdgeCache::singleton()->addTags(EdgeCache::pageTag($newsHolder->ID));
  ```

  A page that lists every page (a sitemap) declares the class instead:

  ```php
  private static $edge_cache_depends_on = [SiteTree::class];
  ```

- **Scheduled start and end times.** A banner or post that appears or disappears at a set time is handled by listing its date fields in `edge_cache_schedule_fields` (see [Configuration](configuration.md#scheduled-records)). Without that, it can be up to `edge_ttl` late.
- **Content behind a partial or application cache.** A `<% cached %>` block or a PSR-16 cache that serves a listing runs no query while it is warm, so the page is stamped without that class tag. Declare the dependency with `edge_cache_depends_on` (or an `updateEdgeCacheTags` hook that adds the tag and calls `EdgeCache::singleton()->declareClass($class)`, which is what lets scheduled fields on that class count) for any such block.
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

### Relations and reordering

Adding, removing or clearing the records of a `many_many` relation writes only a join table, so no record event fires. A class that uses `EdgeCachePurgeable` also purges when a `many_many` list it belongs to changes (including `many_many` through), with the same setting as above: the class tag chain by default, the whole site for `edge_cache_purge: everything`.

```yaml
App\Model\FooterLinkGroup:
  extensions:
    - Dynamic\EdgeCache\Extension\EdgeCachePurgeable
  edge_cache_purge: everything
```

Lists held by Settings (`SiteConfig`), such as utility or footer links, need no declaration: adding, removing or reordering their members clears the whole site, as saving Settings does, whichever side of the relation is edited.

It works from either side of the relation: ticking groups on a link purges for the group class when the group class opted in, whether or not the link class did. From the side that did not declare the relation, only the class as declared on the relation is purged, so a subclass tag of it is not.

Dragging rows into a new order in a `GridFieldOrderableRows` purges too, for a plain `many_many` with an extra sort field and for `many_many` through.

Not seen: changes made with raw SQL, other sortable GridField modules, `ManyManyList::setExtraData()`, and adding an already-linked record to a `many_many` through list just to change its extra fields. After one of those, save the owner record or run `sake tasks:edge-cache-purge --everything`.

It has no effect on a class in the ignore lists (files, elements): no page carries a tag for those, and files and elements purge through their own hooks. It is opt-in per class on purpose: a purge for every write to every record would send API calls for form submissions and sessions, and Cloudflare's Free plan allows five tag purges a minute.

## Another CDN

Implement `Dynamic\EdgeCache\Adapter\EdgeCacheAdapter` and register it through Injector:

| Method | Purpose |
|---|---|
| `isConfigured()` | Credentials present. False keeps the site on its normal headers |
| `edgeHeaders(EdgePolicy)` | The header(s) your edge reads for lifetime (Fastly: `Surrogate-Control`) |
| `tagHeaderName()`, `formatTags()` | Where tags go and how they are joined (Fastly: `Surrogate-Key`, space separated) |
| `allowedVary()` | `Vary` values the edge tolerates while still caching, or null for no restriction. When it returns a list (Imperva allows only `Accept-Encoding`), the middleware removes the `Vary` values in `EdgeCache.vary_ignorable` (`X-Forwarded-Protocol`, `Accept`, `X-Requested-With`) from responses it hands to the edge. Those name variants that are never cached here: Markdown is `no-store`, Ajax responses are never public, the scheme is not served two ways. A page whose `Vary` has any other value (`Cookie`, `Accept-Language`, `*`) is not edge-cached and is made `private`, since dropping it would let one variant be served as another |
| `purgeTags()`, `purgeUrls()`, `purgeEverything()` | Purge calls. Return false on failure; never throw |
