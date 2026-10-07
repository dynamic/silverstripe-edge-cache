<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Extension\EdgeCachePurgeable;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Like JoinOwner but on the default purge: the pages that listed its class.
 */
class ListedJoinOwner extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheListedJoinOwner';

    private static $db = ['Title' => 'Varchar'];

    private static $many_many = ['Targets' => JoinTarget::class];

    private static $extensions = [EdgeCachePurgeable::class];
}
