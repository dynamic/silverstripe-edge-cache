<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Page;
use SilverStripe\Dev\TestOnly;

/**
 * A page subclass that lists its own records (a blog post), so it is tagged even though Page is not.
 */
class ListedPage extends Page implements TestOnly
{
    private static $table_name = 'EdgeCacheListedPage';

    private static $db = ['Summary' => 'Varchar'];
}
