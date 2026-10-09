<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class OwnedElementJoin extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheOwnedElementJoin';

    private static $db = ['Sort' => 'Int'];

    private static $has_one = [
        'Owner' => OwnedRelationElement::class,
        'Target' => JoinTarget::class,
    ];
}
