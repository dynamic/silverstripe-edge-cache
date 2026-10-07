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
     * Seconds the edge keeps a page. Purge on publish clears the changes the module can see; this is
     * the backstop for the ones it cannot (pages that list records through an ignored class, content
     * behind a partial or application cache).
     *
     * @config
     * @var int
     */
    private static $edge_ttl = 21600;

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
     * Shortest edge lifetime a scheduled start or end time may force (see `edge_cache_schedule_fields`).
     * A window about to open or close within this many seconds can show up to this late, which
     * avoids a page that is re-rendered on every request as a boundary approaches.
     *
     * @config
     * @var int
     */
    private static $schedule_ttl_floor = 60;

    /**
     * Most tags one page may carry. A page that would carry more is not edge-cached: a truncated
     * tag set would leave it out of purges it needs.
     *
     * @config
     * @var int
     */
    private static $max_tags = 150;

    /**
     * Classes whose queries never become tags, matched exactly. Navigation and the page load query
     * these on every page, so a tag for them would tie every page to every publish. A subclass that
     * lists its own records (BlogPost) is still tagged.
     *
     * @config
     * @var string[]
     */
    private static $auto_tag_ignore = [
        'SilverStripe\\CMS\\Model\\SiteTree',
        'Page',
        'SilverStripe\\ORM\\DataObject',
    ];

    /**
     * Classes whose queries never become tags, and every subclass of them. These are never what a
     * page lists: elements and their area (publishing one purges the owner page directly), Settings
     * (a save purges the site), files and security records.
     *
     * @config
     * @var string[]
     */
    private static $auto_tag_ignore_descendants = [
        'DNADesign\\Elemental\\Models\\BaseElement',
        'DNADesign\\Elemental\\Models\\ElementalArea',
        'SilverStripe\\SiteConfig\\SiteConfig',
        'SilverStripe\\Assets\\File',
        'SilverStripe\\Security\\Member',
        'SilverStripe\\Security\\Group',
    ];

    /**
     * `Vary` values that may be removed for an edge that refuses to cache a response varying on
     * anything but Accept-Encoding (see EdgeCacheAdapter::allowedVary()). Each one names a variant
     * that is never cached here: Markdown is `no-store`, Ajax responses are never public, and the
     * scheme is not served two ways. Any other value (Cookie, Accept-Language, `*`) keeps the page
     * out of the edge, since stripping it would let one variant be served as another.
     *
     * @config
     * @var string[]
     */
    private static $vary_ignorable = ['X-Forwarded-Protocol', 'X-Forwarded-Proto', 'Accept', 'X-Requested-With'];

    /**
     * URL path prefixes (no leading slash) that never get edge headers.
     *
     * @config
     * @var string[]
     */
    private static $excluded_paths = ['admin', 'dev', 'Security'];

    private bool $cacheable = false;

    private int $currentPageId = 0;

    /**
     * Classes whose subclass fields were lazy-loaded, with the ids of the records involved.
     *
     * @var array<string, array<int, true>>
     */
    private array $lazyClasses = [];

    /**
     * @var array<string, true>
     */
    private array $tags = [];

    /**
     * The class each automatic tag was collected for, so scheduled fields can be looked up on it.
     *
     * @var array<string, string>
     */
    private array $tagClasses = [];

    /**
     * Classes a page declared a dependency on (`edge_cache_depends_on`).
     *
     * @var array<string, true>
     */
    private array $declaredClasses = [];

    /**
     * Seconds until the next start or end time of a scheduled record the page lists, if any.
     */
    private ?int $ttlCap = null;

    public function adapter(): EdgeCacheAdapter
    {
        return Injector::inst()->get(EdgeCacheAdapter::class);
    }

    public function policy(): EdgePolicy
    {
        $ttl = (int) static::config()->get('edge_ttl');
        if ($this->ttlCap !== null) {
            $ttl = min($ttl, max((int) static::config()->get('schedule_ttl_floor'), $this->ttlCap));
        }

        return new EdgePolicy(
            $ttl,
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
     * The page being rendered, so a lazy load of its own subclass fields is not mistaken for a
     * list the page shows.
     */
    public function setCurrentPageId(int $id): void
    {
        $this->currentPageId = $id;
    }

    /**
     * @return string[] the site tag first, then the rest in the order they were added
     */
    public function getTags(): array
    {
        $tags = $this->tags;
        foreach ($this->lazyClasses as $class => $ids) {
            // Only the page's own record: it says nothing about what the page lists.
            if ($ids !== [$this->currentPageId => true]) {
                $tags[self::classTag($class)] = true;
            }
        }

        return array_merge([self::SITE_TAG], array_keys(array_diff_key($tags, [self::SITE_TAG => true])));
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
     * Record that the current page queried a class, unless the class is ignored.
     */
    /**
     * @return string|null the tag this call added, or null when the class is ignored or the page
     *                     already carried the tag
     */
    public function collectClass(string $class): ?string
    {
        if ($this->isIgnoredClass($class)) {
            return null;
        }

        $tag = self::classTag($class);
        if (isset($this->tags[$tag])) {
            return null;
        }
        $this->addTags($tag);
        $this->tagClasses[$tag] = ltrim($class, '\\');

        return $tag;
    }

    /**
     * Take back a tag that turned out not to describe something the page lists.
     */
    public function forgetTag(string $tag): void
    {
        unset($this->tags[$tag], $this->tagClasses[$tag]);
    }

    /**
     * Note a class the page depends on without querying it (`edge_cache_depends_on`), so its
     * scheduled records are taken into account. The tag itself is added separately.
     */
    public function declareClass(string $class): void
    {
        $this->declaredClasses[ltrim($class, '\\')] = true;
    }

    /**
     * Cap the edge lifetime at the time left until a scheduled record starts or ends. Several caps
     * keep the shortest.
     */
    public function capEdgeTtl(int $seconds): void
    {
        $this->ttlCap = $this->ttlCap === null ? $seconds : min($this->ttlCap, $seconds);
    }

    /**
     * The classes whose records this page shows: the ones its queries and declarations named, with
     * the same lazy-load exclusion as the tags.
     *
     * @return string[]
     */
    public function collectedClasses(): array
    {
        $classes = array_merge(array_values($this->tagClasses), array_keys($this->declaredClasses));
        foreach ($this->lazyClasses as $class => $ids) {
            if ($ids !== [$this->currentPageId => true]) {
                $classes[] = $class;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Record that a record's subclass fields were lazy-loaded while the page rendered.
     */
    public function collectLazyClass(string $class, int $recordId): void
    {
        if (!$this->isIgnoredClass($class)) {
            $this->lazyClasses[$class][$recordId] = true;
        }
    }

    public function isIgnoredClass(string $class): bool
    {
        $class = ltrim($class, '\\');
        $exact = array_map(
            fn ($name) => strtolower(ltrim($name, '\\')),
            (array) static::config()->get('auto_tag_ignore')
        );
        if (in_array(strtolower($class), $exact, true)) {
            return true;
        }

        foreach ((array) static::config()->get('auto_tag_ignore_descendants') as $ancestor) {
            if (is_a($class, ltrim($ancestor, '\\'), true)) {
                return true;
            }
        }

        return false;
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
        $this->tagClasses = [];
        $this->declaredClasses = [];
        $this->ttlCap = null;
        $this->lazyClasses = [];
        $this->currentPageId = 0;
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
