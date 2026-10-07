<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Names a field that is not a date.
 */
class TextScheduleThing extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheTextScheduleThing';

    private static $db = ['Title' => 'Varchar'];

    private static $edge_cache_schedule_fields = ['Title'];
}
