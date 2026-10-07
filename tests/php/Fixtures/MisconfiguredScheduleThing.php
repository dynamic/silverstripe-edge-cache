<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Names a field it does not have.
 */
class MisconfiguredScheduleThing extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheMisconfiguredScheduleThing';

    private static $db = ['Title' => 'Varchar'];

    private static $edge_cache_schedule_fields = ['NoSuchField'];
}
