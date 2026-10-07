<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A banner that shows between a start and an end time.
 */
class ScheduledThing extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheScheduledThing';

    private static $db = [
        'Title' => 'Varchar',
        'StartTime' => 'Datetime',
        'EndTime' => 'Datetime',
    ];

    private static $edge_cache_schedule_fields = ['StartTime', 'EndTime'];
}
