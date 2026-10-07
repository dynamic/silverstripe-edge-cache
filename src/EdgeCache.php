<?php

namespace Dynamic\EdgeCache;

use Dynamic\EdgeCache\Adapter\EdgeCacheAdapter;
use Dynamic\EdgeCache\Policy\EdgePolicy;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * The module's gatekeeper and request-scoped state.
 *
 * Edge caching runs only when every gate passes: the environment is listed in
 * `enabled_environments` (live by default), the adapter has credentials, the Settings toggle is
 * on, and the request reads the Live stage. Anything else leaves headers alone.
 *
 * A controller marks its page cacheable and adds tags during the request; the middleware turns
 * that into response headers once Silverstripe has settled the final `Cache-Control`.
 */
class EdgeCache
{
    use Configurable;
    use Injectable;

    public const SITE_TAG = 'ec-site';

    public const PAGE_TAG_PREFIX = 'ec-page-';

    public const CLASS_TAG_PREFIX = 'ec-class-';

    /**
     * `SS_ENVIRONMENT_TYPE` values in which edge caching and purging run.
     *
     * @config
     * @var string[]
     */
    private static $enabled_environments = ['live'];

    /**
     * Seconds the edge keeps a page. Purge on publish keeps this long lifetime safe.
     *
     * @config
     * @var int
     */
    private static $edge_ttl = 86400;

    /**
     * @config
     * @var int
     */
    private static $stale_while_revalidate = 60;

    /**
     * @config
     * @var int
     */
    private static $stale_if_error = 86400;

    /**
     * Seconds a browser keeps a page. Short, because browsers cannot be purged.
     *
     * @config
     * @var int
     */
    private static $browser_max_age = 60;

    /**
     * Most tags one page carries; the rest are dropped.
     *
     * @config
     * @var int
     */
    private static $max_tags = 100;

    /**
     * URL path prefixes (no leading slash) that never get edge headers.
     *
     * @config
     * @var string[]
     */
    private static $excluded_paths = ['admin', 'dev', 'Security'];

    private bool $cacheable = false;

    /**
     * @var array<string, true>
     */
    private array $tags = [];

    public function adapter(): EdgeCacheAdapter
    {
        return Injector::inst()->get(EdgeCacheAdapter::class);
    }

    public function policy(): EdgePolicy
    {
        return new EdgePolicy(
            (int) static::config()->get('edge_ttl'),
            (int) static::config()->get('stale_while_revalidate'),
            (int) static::config()->get('stale_if_error')
        );
    }

    /**
     * True when the environment is one this module runs in. Purging uses this gate alone, so
     * turning the Settings toggle off can still clear the edge.
     */
    public function isEnvironmentEnabled(): bool
    {
        $environment = strtolower((string) Environment::getEnv('SS_ENVIRONMENT_TYPE'));
        $allowed = array_map('strtolower', (array) static::config()->get('enabled_environments'));

        return $environment !== '' && in_array($environment, $allowed, true);
    }

    /**
     * True when this request may be served from the edge.
     */
    public function isEnabled(): bool
    {
        if (!$this->isEnvironmentEnabled() || !$this->adapter()->isConfigured()) {
            return false;
        }
        if (Versioned::get_stage() !== Versioned::LIVE) {
            return false;
        }

        try {
            return (bool) SiteConfig::current_site_config()->EdgeCacheEnabled;
        } catch (Throwable) {
            // No database or no SiteConfig yet (dev/build, fresh install): stay off.
            return false;
        }
    }

    public function isExcludedPath(string $url): bool
    {
        $path = trim($url, '/');
        foreach ((array) static::config()->get('excluded_paths') as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    public function markCacheable(): void
    {
        $this->cacheable = true;
    }

    public function isMarkedCacheable(): bool
    {
        return $this->cacheable;
    }

    /**
     * Tag the current response. Tags must be printable ASCII with no spaces or commas.
     *
     * @param string|string[] $tags
     */
    public function addTags(string|array $tags): void
    {
        foreach ((array) $tags as $tag) {
            $tag = preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $tag);
            if ($tag !== '') {
                $this->tags[$tag] = true;
            }
        }
    }

    /**
     * @return string[] the site tag first, then the rest, capped at `max_tags`
     */
    public function getTags(): array
    {
        $tags = array_keys($this->tags);
        $tags = array_slice(array_diff($tags, [self::SITE_TAG]), 0, max(0, (int) static::config()->get('max_tags') - 1));

        return array_merge([self::SITE_TAG], $tags);
    }

    public static function pageTag(int|string $id): string
    {
        return self::PAGE_TAG_PREFIX . $id;
    }

    public static function classTag(string $class): string
    {
        $short = substr(strrchr('\\' . $class, '\\'), 1);

        return self::CLASS_TAG_PREFIX . $short;
    }

    /**
     * Forget the request-scoped state.
     */
    public function reset(): void
    {
        $this->cacheable = false;
        $this->tags = [];
    }

    /**
     * Absolute URL for a site-relative path, for URL purges.
     */
    public static function absoluteUrl(string $path): string
    {
        return Director::absoluteURL($path);
    }
}
