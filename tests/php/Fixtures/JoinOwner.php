<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Extension\EdgeCachePurgeable;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A sitewide record (a navigation group) whose members live in a many_many join table.
 */
class JoinOwner extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheJoinOwner';

    private static $db = ['Title' => 'Varchar'];

    private static $many_many = ['Targets' => JoinTarget::class];

    private static $many_many_extraFields = ['Targets' => ['Sort' => 'Int']];

    private static $extensions = [EdgeCachePurgeable::class];

    private static $edge_cache_purge = 'everything';
}
