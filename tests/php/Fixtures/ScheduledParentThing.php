<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A base class a page can list. Only its subclass has scheduled fields.
 */
class ScheduledParentThing extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheScheduledParentThing';

    private static $db = ['Title' => 'Varchar'];
}
