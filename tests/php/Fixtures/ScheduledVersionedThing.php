<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

class ScheduledVersionedThing extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheScheduledVersionedThing';

    private static $db = ['Title' => 'Varchar', 'StartTime' => 'Datetime'];

    private static $extensions = [Versioned::class];

    private static $edge_cache_schedule_fields = ['StartTime'];
}
