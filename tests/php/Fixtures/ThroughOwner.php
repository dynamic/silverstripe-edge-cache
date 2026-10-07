<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Extension\EdgeCachePurgeable;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A sitewide record whose members are a many_many through relation.
 */
class ThroughOwner extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheThroughOwner';

    private static $db = ['Title' => 'Varchar'];

    private static $has_many = ['Joins' => ThroughJoin::class . '.Owner'];

    private static $many_many = [
        'Targets' => [
            'through' => ThroughJoin::class,
            'from' => 'Owner',
            'to' => 'Target',
        ],
    ];

    private static $extensions = [EdgeCachePurgeable::class];

    private static $edge_cache_purge = 'everything';
}
