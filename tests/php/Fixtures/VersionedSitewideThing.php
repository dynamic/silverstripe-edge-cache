<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Extension\EdgeCachePurgeable;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

class VersionedSitewideThing extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheVersionedSitewideThing';

    private static $db = ['Title' => 'Varchar'];

    private static $extensions = [Versioned::class, EdgeCachePurgeable::class];

    private static $edge_cache_purge = 'everything';
}
