# Configuration

All keys are Silverstripe config on `Dynamic\EdgeCache\EdgeCache` unless noted.

| Key | Default | Meaning |
|---|---|---|
| `enabled_environments` | `[live]` | `SS_ENVIRONMENT_TYPE` values where edge headers and purging run. Add `test` to try it on UAT |
| `edge_ttl` | `21600` | Seconds the edge keeps a page (6 hours). Publishing purges what the module can see; this is the backstop for the rest (see [Extending](extending.md#whats-not-tracked)). Raise it once a site's listings are covered |
| `stale_while_revalidate` | `60` | Seconds the edge may serve a stale copy while it refetches |
| `stale_if_error` | `86400` | Seconds the edge may serve a stale copy when the origin errors |
| `schedule_ttl_floor` | `60` | Shortest edge lifetime a scheduled start or end time may force (see [Scheduled records](#scheduled-records)) |
| `browser_max_age` | `60` | Seconds a browser keeps a page. Short, because browsers cannot be purged |
| `max_tags` | `150` | Most cache tags on one page. A page over the limit is served from the origin as `private` and a warning is logged once an hour per URL |
| `auto_tag_ignore` | `SiteTree`, `Page`, `DataObject` | Classes whose queries never become tags (exact match) |
| `auto_tag_ignore_descendants` | elements, element areas, `SiteConfig`, `File`, `Group` | Classes, and every subclass, whose queries never become tags |
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
8. The page carries no more than `max_tags` tags.

A page the controller made public that fails 7 or 8 is changed to `private, must-revalidate`, so a public header never leaves without edge handling behind it.

Unticking the Settings box clears the cached pages straight away.

## Scheduled records

A banner that shows between a start and an end time changes at a moment nothing publishes at, so no purge can catch it. A class lists its date fields and the edge lifetime of any page that listed it is cut to the time left until the next one:

```yaml
App\Model\Banner:
  edge_cache_schedule_fields:
    - StartTime
    - EndTime
```

Both ends count: one makes a record appear, the other makes it disappear. The earliest future value across every record of the class wins (the page may not show that record; a boundary coming is enough). A subclass inherits the fields, and fields only a subclass configures are found when a page lists the parent class. The fields must be Date or Datetime. A Date is a boundary at the start of that day and again at the start of the next, so a start date and an inclusive end date are both caught. The cap only ever shortens `edge_ttl`.

Which classes a page "listed" is what its queries touched, plus the classes it declares (`edge_cache_depends_on`, or `EdgeCache::singleton()->declareClass()` from an `updateEdgeCacheTags` hook). Classes the module never collects (pages, elements and their areas, files, members) are not seen from a query, so declare them:

- A page or element with its own start and end fields is declared automatically when it sets `edge_cache_schedule_fields`.
- A page that lists scheduled pages (news, events) declares them: `private static $edge_cache_depends_on = [NewsPage::class];`.

A boundary closer than `schedule_ttl_floor` uses the floor instead, so a page is not re-rendered on every request as a boundary nears. A banner can therefore show up to the floor late. `stale_while_revalidate` adds up to its own length on top, and `stale_if_error` lets the edge keep the old page for up to that long if the origin errors at that moment. A field the class does not have, or one that is not a date, is logged as a warning on each origin render and the page is cached for the floor only.

Not covered: a scheduled field on a `many_many` through join row or in `many_many_extraFields` (the join class is not a class a page queries).

One extra query per configured field runs when the origin renders a cacheable page; a cached page costs none.

## What gets purged

| Event | Purge |
|---|---|
| Page published | The page, its ancestors, pages that listed records of its class (class tags), and pages that listed its parent's children (`edge_cache_tag_children`) |
| Page published with a changed Title, MenuTitle, URLSegment, ShowInMenus, Sort or ParentID | The whole site (navigation shows on every page) |
| Page unpublished or archived | The whole site |
| Element published, unpublished or archived | The page it sits on |
| Settings saved | The whole site |
| File published, replaced or archived | The file's URL |
| Member's `FirstName` or `Surname` changed, member deleted | Pages that listed members (their authors) |
| Record using `EdgeCachePurgeable` | Pages that listed its class, or the whole site with `edge_cache_purge: everything` |

Purges only run in `enabled_environments`, whether or not the Settings box is ticked.

Publishing an element also purges every page that shows a virtual copy of it (`dnadesign/silverstripe-elemental-virtual`), when that module is installed.

## Checking it works, and what a failure looks like

```
sake dev/tasks/edge-cache-status             # environment, adapter, credentials, Settings switch
sake dev/tasks/edge-cache-status verify=1    # also proves the CDN credentials (purges a tag no page carries)
```

Run `verify=1` before ticking the Settings box and after changing credentials. The box itself says "NOT ACTIVE" in its description when the environment is not enabled or the credentials are missing.

A purge that the CDN refuses never breaks a publish, so an editor sees "Published" either way. The failure is written to the error log at `error` level with what was not purged, so the log must go somewhere (`SS_ERROR_LOG`, or a logger handler of your own). A purge run from the task (`edge-cache-purge`) exits 1 and prints the failure.

## After a deploy

Publishing purges what editors changed. A deploy that changes templates, the theme or anything else that alters the HTML of pages nobody edited does not, so cached pages keep the old markup until `edge_ttl` ends. Add this to the deploy steps:

```
sake dev/tasks/edge-cache-purge everything=1
```
