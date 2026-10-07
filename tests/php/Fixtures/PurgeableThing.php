<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Extension\EdgeCachePurgeable;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A plain (not versioned) record using the default purge: the pages that listed its class.
 */
class PurgeableThing extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCachePurgeableThing';

    private static $db = ['Title' => 'Varchar'];

    private static $extensions = [EdgeCachePurgeable::class];
}
