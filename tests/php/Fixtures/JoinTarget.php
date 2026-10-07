<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * The record on the far side of the owners' many_many relations.
 */
class JoinTarget extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheJoinTarget';

    private static $db = ['Title' => 'Varchar'];

    private static $belongs_many_many = [
        'Owners' => JoinOwner::class . '.Targets',
        'ListedOwners' => ListedJoinOwner::class . '.Targets',
        'PlainOwners' => PlainJoinOwner::class . '.Targets',
    ];
}
