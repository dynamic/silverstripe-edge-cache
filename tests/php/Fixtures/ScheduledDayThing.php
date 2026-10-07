<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A record that goes live on a date (no time).
 */
class ScheduledDayThing extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheScheduledDayThing';

    private static $db = ['Title' => 'Varchar', 'PublishOn' => 'Date'];

    private static $edge_cache_schedule_fields = ['PublishOn'];
}
