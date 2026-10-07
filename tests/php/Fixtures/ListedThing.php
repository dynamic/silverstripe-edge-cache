<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * A versioned record a page might list.
 */
class ListedThing extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheListedThing';

    private static $db = ['Title' => 'Varchar'];

    private static $extensions = [Versioned::class];
}
