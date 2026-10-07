<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A many_many owner that did not opt in to purging.
 */
class PlainJoinOwner extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCachePlainJoinOwner';

    private static $db = ['Title' => 'Varchar'];

    private static $many_many = ['Targets' => JoinTarget::class];
}
