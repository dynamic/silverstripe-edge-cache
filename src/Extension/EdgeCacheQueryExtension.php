<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\EdgeCache;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;

/**
 * Tags a page with every class it queries while rendering, so a page that lists blog posts, staff
 * or testimonials is purged when one of them is published, with no declaration on the page.
 *
 * Applied to DataObject. It records only while a front-end GET is being rendered in an enabled
 * environment (EdgeCache::isCollecting()), and never queries the database itself.
 */
class EdgeCacheQueryExtension extends DataExtension
{
    public function augmentSQL(SQLSelect $query, ?DataQuery $dataQuery = null): void
    {
        if (!EdgeCache::isCollecting() || !$dataQuery) {
            return;
        }

        EdgeCache::singleton()->collectClass($dataQuery->dataClass());
    }
}
