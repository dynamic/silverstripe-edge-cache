<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Page;
use SilverStripe\Dev\TestOnly;

/**
 * A page holding a many_many to a class that did not opt in to purging (a slide list).
 */
class OwnedRelationPage extends Page implements TestOnly
{
    private static $table_name = 'EdgeCacheOwnedRelationPage';

    private static $many_many = ['Targets' => JoinTarget::class];
}
