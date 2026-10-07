# Configuration

All keys are Silverstripe config on `Dynamic\EdgeCache\EdgeCache` unless noted.

| Key | Default | Meaning |
|---|---|---|
| `enabled_environments` | `[live]` | `SS_ENVIRONMENT_TYPE` values where edge headers and purging run. Add `test` to try it on UAT |
| `edge_ttl` | `86400` | Seconds the edge keeps a page. Safe to leave long because publishing purges |
| `stale_while_revalidate` | `60` | Seconds the edge may serve a stale copy while it refetches |
| `stale_if_error` | `86400` | Seconds the edge may serve a stale copy when the origin errors |
| `browser_max_age` | `60` | Seconds a browser keeps a page. Short, because browsers cannot be purged |
| `max_tags` | `150` | Most cache tags on one page. A page over the limit is not edge-cached |
| `auto_tag_ignore` | `SiteTree`, `Page`, elements, `SiteConfig`, files, members | Classes whose queries never become tags |
| `excluded_paths` | `admin`, `dev`, `Security` | URL prefixes that never get edge headers |

`Dynamic\EdgeCache\Adapter\CloudflareAdapter`: `max_items_per_request` (100; 500 on Enterprise), `max_attempts` (3), `max_retry_wait` (15 seconds), `max_tag_header_bytes` (16000).

`Dynamic\EdgeCache\Cloudflare\CacheRuleset`: `static_edge_ttl` (7 days), `static_browser_ttl` (1 day), `excluded_paths`.

## What decides whether a page is cached

All of these must hold:

1. The environment is in `enabled_environments`.
2. The adapter has credentials.
3. The **Serve pages from the CDN edge cache** box is ticked in Settings.
4. The request reads the Live stage (not `?stage=Stage` or CMS preview).
5. The page is a front-end page (`ContentController`) and not an Ajax request.
6. Silverstripe leaves the page `public`. A request with a session becomes `private`; a page with a CSRF form becomes `no-store`; errors and redirects become `no-store`.
7. The response is a 200 to a GET or HEAD request and sets no cookie.

Unticking the Settings box clears the cached pages straight away.

## What gets purged

| Event | Purge |
|---|---|
| Page published | The page, its ancestors, and pages that listed records of its class (class tags) |
| Page published with a changed Title, MenuTitle, URLSegment, ShowInMenus, Sort or ParentID | The whole site (navigation shows on every page) |
| Page unpublished or archived | The whole site |
| Element published, unpublished or archived | The page it sits on |
| Settings saved | The whole site |
| File published, replaced or archived | The file's URL |
| Record using `EdgeCachePurgeable` | Pages that listed its class, or the whole site with `edge_cache_purge: everything` |

Purges only run in `enabled_environments`, whether or not the Settings box is ticked.
