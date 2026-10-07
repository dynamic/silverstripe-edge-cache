<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\CollectionState;
use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\RelationList;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Tags a page with every class it queries while rendering, so a page that lists blog posts, staff
 * or testimonials is purged when one of them is published, with no declaration on the page.
 *
 * Applied to DataObject. It records only while a front-end GET is being rendered in an enabled
 * environment (EdgeCache::isCollecting()), and never queries the database itself.
 *
 * Silverstripe lazy-loads a record's subclass fields with a query of its own class. When that
 * record is the page being rendered it says nothing about what the page lists, so it is not a tag
 * (every blog post would otherwise be tied to every other blog post). When it is some other record,
 * such as a listed post whose summary the template reads, it is.
 *
 * It also hooks many_many lists for EdgeCachePurgeable: a join write fires no record event, so the
 * list's add/remove callbacks purge instead. Applied here because the list can be edited from either
 * side of the relation, and the class that opted in may be the other one.
 */
class EdgeCacheQueryExtension extends DataExtension
{
    public function augmentLoadLazyFields(SQLSelect $query, DataQuery $dataQuery, DataObject $dataObject): void
    {
        if (!EdgeCache::isCollecting()) {
            return;
        }

        // Silverstripe has already run the query hook once for this query; take that tag back.
        $edge = EdgeCache::singleton();
        if ($added = CollectionState::takeAdded($dataQuery)) {
            $edge->forgetTag($added);
        }

        CollectionState::markLazy($dataQuery);
        $edge->collectLazyClass($dataQuery->dataClass(), (int) $dataObject->ID);
    }

    /**
     * Purge when the members of a many_many list (plain or through) change, for whichever of the
     * record handing out the list and the class it lists uses EdgeCachePurgeable. The other side is
     * the class as declared on the relation, so a subclass tag is not purged from there. A list of
     * Settings records edited from the other side clears the site, as editing it from Settings does
     * (EdgeCacheSiteConfigExtension).
     */
    public function updateManyManyComponents(RelationList $list): void
    {
        $classes = [];
        foreach ([get_class($this->owner), $list->dataClass()] as $class) {
            if (singleton($class)->hasExtension(EdgeCachePurgeable::class)) {
                $classes[$class] = $class;
            }
        }
        $settings = is_a($list->dataClass(), SiteConfig::class, true);
        if (!$classes && !$settings) {
            return;
        }

        $callback = function () use ($classes, $settings): void {
            if ($settings) {
                PurgeQueue::singleton()->addEverything();
            }
            foreach ($classes as $class) {
                EdgeCachePurgeable::purgeClass($class);
            }
        };
        $list->addCallbacks()->add($callback, EdgeCachePurgeable::RELATION_CALLBACK);
        $list->removeCallbacks()->add($callback, EdgeCachePurgeable::RELATION_CALLBACK);
    }

    public function augmentSQL(SQLSelect $query, ?DataQuery $dataQuery = null): void
    {
        if (!EdgeCache::isCollecting() || !$dataQuery || CollectionState::isLazy($dataQuery)) {
            return;
        }

        $added = EdgeCache::singleton()->collectClass($dataQuery->dataClass());
        CollectionState::rememberAdded($dataQuery, $added);
    }
}
