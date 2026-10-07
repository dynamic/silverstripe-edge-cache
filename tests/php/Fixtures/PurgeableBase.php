<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Extension\EdgeCachePurgeable;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class PurgeableBase extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCachePurgeableBase';

    private static $db = ['Title' => 'Varchar'];

    private static $extensions = [EdgeCachePurgeable::class];
}
