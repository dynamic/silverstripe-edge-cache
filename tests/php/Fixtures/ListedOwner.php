<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Owns ListedThing records through a has_many, the way a page lists a relation.
 */
class ListedOwner extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheListedOwner';

    private static $db = ['Title' => 'Varchar'];

    private static $has_many = ['Things' => ListedThing::class];
}
