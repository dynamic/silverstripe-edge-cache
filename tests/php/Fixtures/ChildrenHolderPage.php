<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Page;
use SilverStripe\Dev\TestOnly;

/**
 * A page whose children are listed on other pages, so it opts in to children tags.
 */
class ChildrenHolderPage extends Page implements TestOnly
{
    private static $table_name = 'EdgeCacheChildrenHolderPage';

    private static $edge_cache_tag_children = true;
}
