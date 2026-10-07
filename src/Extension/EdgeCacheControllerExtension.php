<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\EdgeCache;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Core\Extension;

/**
 * Makes a front-end page eligible for the edge and tags it.
 *
 * Calls `publicCache()` without forcing it, so Silverstripe still downgrades a request with a
 * session to `private` and a page with a CSRF form to `no-store`. The browser lifetime is set on
 * the public state only: `setMaxAge()` would also write it into the private and disabled states.
 *
 * Pages that show content from other records (a home page listing recent posts) declare the
 * classes they depend on, so publishing one of those purges the page:
 *
 *     private static $edge_cache_depends_on = [BlogPost::class];
 *
 * @property ContentController|static $owner
 */
class EdgeCacheControllerExtension extends Extension
{
    public function onAfterInit(): void
    {
        $edge = EdgeCache::singleton();
        $request = $this->owner->getRequest();

        if (!in_array($request->httpMethod(), ['GET', 'HEAD'], true)) {
            return;
        }
        if (!$edge->isEnabled() || $edge->isExcludedPath($request->getURL())) {
            return;
        }
        if ($request->isAjax()) {
            HTTPCacheControlMiddleware::singleton()->disableCache();

            return;
        }

        $control = HTTPCacheControlMiddleware::singleton();
        $control->publicCache();
        $control->setStateDirective(
            HTTPCacheControlMiddleware::STATE_PUBLIC,
            'max-age',
            (int) EdgeCache::config()->get('browser_max_age')
        );

        $edge->markCacheable();
        $edge->addTags($this->tags());
    }

    /**
     * @return string[]
     */
    protected function tags(): array
    {
        $tags = [EdgeCache::SITE_TAG];

        $record = $this->owner->data();
        if ($record && $record->exists()) {
            $tags[] = EdgeCache::pageTag($record->ID);
            $tags[] = EdgeCache::classTag($record->ClassName);

            foreach ((array) $record->config()->get('edge_cache_depends_on') as $class) {
                $tags[] = EdgeCache::classTag($class);
            }

            $tags = array_merge($tags, $this->elementTags($record));
        }

        $this->owner->extend('updateEdgeCacheTags', $tags);

        return $tags;
    }

    /**
     * Tags contributed by the elements on a page (top-level elements only; elements nested inside
     * a group tag themselves through `updateEdgeCacheTags` if they need to).
     *
     * @return string[]
     */
    protected function elementTags($record): array
    {
        if (!$record->hasMethod('ElementalArea')) {
            return [];
        }

        $tags = [];
        $area = $record->ElementalArea();
        if (!$area || !$area->exists()) {
            return [];
        }
        foreach ($area->Elements() as $element) {
            if ($element->hasMethod('edgeCacheTags')) {
                $tags = array_merge($tags, $element->edgeCacheTags());
            }
        }

        return $tags;
    }
}
