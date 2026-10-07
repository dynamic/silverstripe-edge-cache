<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Extension\EdgeCachePurgeable;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A record shown on every page, so any change clears the site.
 */
class SitewideThing extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheSitewideThing';

    private static $db = ['Title' => 'Varchar'];

    private static $extensions = [EdgeCachePurgeable::class];

    private static $edge_cache_purge = 'everything';
}
