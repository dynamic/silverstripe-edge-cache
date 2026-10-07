# Cloudflare

## How it works

Cloudflare does not cache HTML unless a Cache Rule makes it eligible. The module sends:

- `Cache-Control` for browsers (a short `max-age`), unchanged from Silverstripe.
- `Cloudflare-CDN-Cache-Control` for the edge. Cloudflare reads this instead of `Cache-Control` and does not forward it.
- `Cache-Tag`, which Cloudflare strips before the response reaches a visitor. Curl the origin directly to see it.

The edge lifetime goes in its own header because Silverstripe's public cache state always carries `must-revalidate`, which switches `stale-while-revalidate` off in Cloudflare.

## Cache Rules

```
ddev sake dev/tasks/edge-cache-cloudflare-rules            # preview
ddev sake dev/tasks/edge-cache-cloudflare-rules apply=1    # write
```

The task reads the zone's existing cache rules, keeps them, and adds five of its own after them (a rule that comes later wins when settings conflict):

1. Pages: eligible for cache, lifetime from the origin header, bypass when the origin sends none.
2. `/_resources/`: cached for `static_edge_ttl`.
3. Bypass for a `PHPSESSID` or `SECSESSID` cookie.
4. Bypass when the request's `Accept` header asks for `text/markdown` (the edge keys on the URL alone and ignores `Vary: Accept`).
5. Bypass for verified bots, so the origin sees them.

Running it again changes nothing.

## Checks

```
curl -sI https://www.example.com/ | grep -i -E 'cf-cache-status|cache-control|age'     # MISS, then HIT
curl -sI --resolve www.example.com:443:ORIGIN_IP https://www.example.com/ | grep -i -E 'cloudflare-cdn|cache-tag'
curl -sI -H 'Cookie: PHPSESSID=x' https://www.example.com/ | grep -i cf-cache-status   # BYPASS or DYNAMIC
curl -s  -H 'Accept: text/markdown' https://www.example.com/ | head -3                 # Markdown, even after a HIT
```

Pages with a form that carries a CSRF token are `no-store` and never cached. Check coverage by requesting each sitemap URL and counting `cf-cache-status: HIT`.

## Plan limits

Free plans allow 5 tag or purge-everything requests per minute (bucket of 25) and 100 items per request. The queue batches a request's purges into one call and the adapter retries on 429.
