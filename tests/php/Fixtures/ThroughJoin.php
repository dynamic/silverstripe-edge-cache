<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class ThroughJoin extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheThroughJoin';

    private static $db = ['Sort' => 'Int'];

    private static $has_one = [
        'Owner' => ThroughOwner::class,
        'Target' => JoinTarget::class,
    ];
}
