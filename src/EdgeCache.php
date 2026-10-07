<?php

namespace Dynamic\EdgeCache;

use Dynamic\EdgeCache\Adapter\EdgeCacheAdapter;
use Dynamic\EdgeCache\Policy\EdgePolicy;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
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
     * Most tags one page may carry. A page that would carry more is not edge-cached: a truncated
     * tag set would leave it out of purges it needs.
     *
     * @config
     * @var int
     */
    private static $max_tags = 150;

    /**
     * Classes whose queries never become tags. Navigation and the page load query these on every
     * page, so a tag for them would tie every page to every publish. Matched exactly, so a
     * subclass that lists its own records (BlogPost) is still tagged.
     *
     * @config
     * @var string[]
     */
    private static $auto_tag_ignore = [
        'SilverStripe\\CMS\\Model\\SiteTree',
        'Page',
        'DNADesign\\Elemental\\Models\\BaseElement',
        'DNADesign\\Elemental\\Models\\ElementalArea',
        'SilverStripe\\SiteConfig\\SiteConfig',
        'SilverStripe\\Assets\\File',
        'SilverStripe\\Assets\\Image',
        'SilverStripe\\Assets\\Folder',
        'SilverStripe\\Security\\Member',
        'SilverStripe\\Security\\Group',
        'SilverStripe\\ORM\\DataObject',
    ];

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
     * @return string[] the site tag first, then the rest in the order they were added
     */
    public function getTags(): array
    {
        return array_merge([self::SITE_TAG], array_keys(array_diff_key($this->tags, [self::SITE_TAG => true])));
    }

    /**
     * True when the page carries more tags than `max_tags` allows.
     */
    public function isTagOverflow(): bool
    {
        return count($this->getTags()) > (int) static::config()->get('max_tags');
    }

    /**
     * Whether queries are being recorded as tags. A static flag, so the query hook costs nothing
     * on requests that are not edge-cached.
     */
    public static function isCollecting(): bool
    {
        return CollectionState::isActive();
    }

    public function startCollecting(): void
    {
        CollectionState::set(true);
    }

    public function stopCollecting(): void
    {
        CollectionState::set(false);
    }

    /**
     * Record that the current page queried a class, unless the class is in `auto_tag_ignore`.
     */
    public function collectClass(string $class): void
    {
        $ignored = array_map(
            fn ($name) => strtolower(ltrim($name, '\\')),
            (array) static::config()->get('auto_tag_ignore')
        );
        if (!in_array(strtolower(ltrim($class, '\\')), $ignored, true)) {
            $this->addTags(self::classTag($class));
        }
    }

    /**
     * Class tags for a record being purged: its class and every parent class below DataObject.
     * Parents the site ignores when tagging are included, because a page can declare one
     * (a sitemap listing every page declares SiteTree).
     *
     * @return string[]
     */
    public static function classChainTags(string $class): array
    {
        $tags = [];
        foreach (ClassInfo::ancestry($class) as $ancestor) {
            if ($ancestor !== DataObject::class && is_subclass_of($ancestor, DataObject::class)) {
                $tags[] = self::classTag($ancestor);
            }
        }

        return $tags;
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
        CollectionState::set(false);
    }

    /**
     * Absolute URL for a site-relative path, for URL purges.
     */
    public static function absoluteUrl(string $path): string
    {
        return Director::absoluteURL($path);
    }
}
