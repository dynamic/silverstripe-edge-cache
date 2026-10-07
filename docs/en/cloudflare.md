# Cloudflare

## How it works

Cloudflare does not cache HTML unless a Cache Rule makes it eligible. The module sends:

- `Cache-Control` for browsers (a short `max-age`), unchanged from Silverstripe.
- `Cloudflare-CDN-Cache-Control` for the edge. Cloudflare reads this instead of `Cache-Control` and does not forward it.
- `Cache-Tag`, which Cloudflare strips before the response reaches a visitor. Curl the origin directly to see it.

The edge lifetime goes in its own header because Silverstripe's public cache state always carries `must-revalidate`, which switches `stale-while-revalidate` off in Cloudflare.

## Tokens

Use two, so the token on the server can do nothing but purge:

| Token | Lives | Permissions (this zone only) |
|---|---|---|
| Runtime | the server's `.env` (`EDGECACHE_CLOUDFLARE_API_TOKEN`) | Zone > Cache Purge > Purge |
| Provisioning | your shell, for one run of the rules task | Zone > Cache Rules > Edit (API permission group "Cache Settings"), plus Zone Settings > Edit if you also change Tiered Cache. Delete it afterwards |

Also set `EDGECACHE_CLOUDFLARE_ZONE_ID`. The task reads the same variable name for both tokens, so for the provisioning run export the provisioning token as `EDGECACHE_CLOUDFLARE_API_TOKEN` in that shell only (for DDEV, `ddev exec` with the variable set), never in the server's `.env`. The Global API Key should not be used by the application.

## Cache Rules

```
sake dev/tasks/edge-cache-cloudflare-rules                                    # print the ruleset, write nothing
sake dev/tasks/edge-cache-cloudflare-rules validate=1 host=www.example.com   # Cloudflare checks it (dry run), writes nothing
sake dev/tasks/edge-cache-cloudflare-rules apply=1 host=www.example.com      # dry run, then write
sake dev/tasks/edge-cache-cloudflare-rules remove=1                          # rollback: remove this module's rules only
```

`validate=1` and `apply=1` refuse to run without `host=`. A local or staging site's base URL is not the host the zone serves, and rules written for it would match no real traffic. Each host keeps its own rules (the host is part of each rule's ref), so apex and www can both be provisioned in one zone; `remove=1` takes back every host's rules, or only one host's with `host=`.

A failure prints to stderr and exits 1, so a script running the task can tell. The task reads the zone's rules and then replaces the whole ruleset in one write, so run it from one place at a time: a rule saved in the dashboard between the read and the write would be overwritten. If a write ends in a timeout the task says so, because it may have been applied; run it with no arguments to see the rules the zone holds.

The task reads the zone's existing cache rules, keeps them, and adds up to five of its own after them (a rule that comes later wins when settings conflict). Rules 1, 3, 4 and 5 apply to page paths only: not `/admin`, `/Security`, `/dev`, `/_resources` or `/assets`.

1. Pages: eligible for cache, lifetime from the origin header, bypass when the origin sends none. The browser lifetime is also left to the origin (`browser_ttl: respect_origin`); without it the zone's Browser Cache TTL (4 hours by default) replaces the origin's short `max-age` and browsers keep a page long after it is purged.
2. `/_resources/`: cached for `static_edge_ttl` (1 day). Purge the prefix after a deploy that changes theme images, which carry no `?m=` cache-buster.
3. Bypass for a `PHPSESSID` or `SECSESSID` cookie.
4. Bypass when the request's `Accept` header asks for `text/markdown` (the edge keys on the URL alone and ignores `Vary: Accept`).
5. Bypass for AI crawlers, matched case-insensitively by user agent (`CacheRuleset.bot_user_agents`), so the origin sees them and the aeo crawler log stays complete. Cache Rules reject `cf.client.bot` on the Free plan, so verified-bot matching is not available there; a spoofed user agent only gets an uncached page. YAML adds to the default list (an empty list changes nothing); set `bot_user_agents: null` to leave the rule out, or replace the list from PHP with `Config::modify()->set(CacheRuleset::class, 'bot_user_agents', [...])`. Googlebot and Bingbot are not in the list, so they are served from the cache and do not reach the aeo crawler log.

Running it again for the same host changes nothing, and `remove=1` takes back only rules whose ref starts with `dynamic-edge-cache-`.

`validate=1` and `apply=1` use Cloudflare's rulesets dry run (`?dry_run=true`), which runs the same syntax, field, phase and plan checks as a real write. Pass `novalidate=1` with `apply=1` only if the dry run is refused for your zone.

## Page Rules already on the zone

A zone often already has a Page Rule such as `*example.com/*` with Cache Everything and Origin Cache Control. Cache Rules take precedence over Page Rules where both match, so the two can coexist. For HTML, applying the rules changes nothing until the origin sends public headers, because Origin Cache Control respects the `private` the site sends today. The one rule that changes behaviour straight away is rule 2: files under `/_resources/` are cached for the configured lifetime, overriding what the origin or the Page Rule would do. Leave the Page Rules in place through the rollout and remove them as a separate step once the Cache Rules are proven. Cache Everything also caches `/assets/` for the zone's default edge lifetime, which the module's rules do not touch.

## Rolling out

1. Deploy the module with the Settings box unticked. Page headers are unchanged.
2. `validate=1`, review the printed rules, then `apply=1`.
3. Optionally enable Smart Tiered Cache (it needs Zone Settings > Edit): `PATCH /zones/{zone}/cache/tiered_cache_smart_topology_enable` with `{"value":"on"}`. On a low-traffic site it collapses per-location misses into one origin fetch.
4. Tick **Serve pages from the CDN edge cache** in Settings > Caching (the save clears the cached pages). Run the checks below.
5. To roll back, untick the box (clears the cache and restores the old headers) or run `remove=1`.

## Checks

```
curl -sI https://www.example.com/ | grep -i -E 'cf-cache-status|cache-control|age'     # MISS, then HIT
curl -sI --resolve www.example.com:443:ORIGIN_IP https://www.example.com/ | grep -i -E 'cloudflare-cdn|cache-tag'
curl -sI -H 'Cookie: PHPSESSID=x' https://www.example.com/ | grep -i cf-cache-status   # BYPASS or DYNAMIC
curl -s  -H 'Accept: text/markdown' https://www.example.com/ | head -3                 # Markdown, even after a HIT
```

The first MISS then HIT also confirms Cloudflare honours `Cloudflare-CDN-Cache-Control` under the rule's `bypass_by_default` lifetime. If it stays `BYPASS` or `DYNAMIC`, check that the Cache Rules were applied and that the page returned `Cache-Tag` from the origin.

Pages with a form that carries a CSRF token are `no-store` and never cached. Check coverage by requesting each sitemap URL and counting `cf-cache-status: HIT`.

## Plan limits

Free plans allow 5 tag or purge-everything requests per minute (bucket of 25) and 100 items per request. The queue batches a request's purges into one call and the adapter retries on 429.
