<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\CollectionState;
use Dynamic\EdgeCache\EdgeCache;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;

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

    public function augmentSQL(SQLSelect $query, ?DataQuery $dataQuery = null): void
    {
        if (!EdgeCache::isCollecting() || !$dataQuery || CollectionState::isLazy($dataQuery)) {
            return;
        }

        $added = EdgeCache::singleton()->collectClass($dataQuery->dataClass());
        CollectionState::rememberAdded($dataQuery, $added);
    }
}
