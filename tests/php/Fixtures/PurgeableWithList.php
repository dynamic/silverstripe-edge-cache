<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Extension\EdgeCachePurgeable;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class PurgeableWithList extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCachePurgeableWithList';

    private static $db = ['Title' => 'Varchar'];

    private static $extensions = [EdgeCachePurgeable::class];

    private static $edge_cache_purge = ['App\\Pages\\TeamPage', 'Elsewhere'];
}
