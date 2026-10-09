# Silverstripe Edge Cache

Serve Silverstripe HTML from a CDN edge cache instead of rendering every request at the origin. The module sets the headers the edge needs, tags each page, and purges the edge when content is published. Cloudflare is supported now; the adapter interface covers other CDNs and WAFs.

| What it adds | |
|---|---|
| Edge headers | `Cloudflare-CDN-Cache-Control` (lifetime, `stale-while-revalidate`, `stale-if-error`) and `Cache-Tag` on pages the origin has marked public |
| Safe by default | Live only, Live stage only, off until ticked in Settings. Sessions, CSRF forms, errors, redirects, cookies and non-GET requests are never cached |
| Purge on publish | Per-page tag purge, ancestors included, one batched request per PHP request. Navigation-affecting changes and Settings saves clear the site. A write to the Live stage without a publish (the content API, a script) purges the same way; a Draft save purges nothing |
| Cache Rules task | `sake tasks:edge-cache-cloudflare-rules` previews and applies the Cloudflare rules the module needs |
| Manual purge | `sake tasks:edge-cache-purge` with `--everything`, `--tag=` or `--purge-url=`; `--retry` sends the purges the CDN refused earlier, which are kept and also sent with the next purge |

## Requirements

- PHP ^8.3, Silverstripe CMS ^6
- A Cloudflare zone and an API token

| Branch | Silverstripe | Status |
|---|---|---|
| `2` | CMS 6 | Active |
| `1` | CMS 5 (PHP ^8.1) | Maintenance: fixes only |

## Install

```
composer require dynamic/silverstripe-edge-cache
```

Choose the adapter in project config:

```yaml
SilverStripe\Core\Injector\Injector:
  Dynamic\EdgeCache\Adapter\EdgeCacheAdapter:
    class: Dynamic\EdgeCache\Adapter\CloudflareAdapter
```

Set in `.env`:

```
EDGECACHE_CLOUDFLARE_API_TOKEN="..."
EDGECACHE_CLOUDFLARE_ZONE_ID="..."
```

The token needs Zone > Cache Purge > Purge. To let the module write the Cache Rules, also give it Zone > Cache Rules > Edit.

Run `dev/build`, then tick **Serve pages from the CDN edge cache** in Settings > Caching.

## Documentation

- [Configuration](docs/en/configuration.md)
- [Cloudflare setup and verification](docs/en/cloudflare.md)
- [Extending: other CDNs, listing pages, other records](docs/en/extending.md)

## License

BSD-3-Clause.
