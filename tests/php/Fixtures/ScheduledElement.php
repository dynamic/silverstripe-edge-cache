<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Dev\TestOnly;

/**
 * An element that shows between two times, and one that lists scheduled records it does not query.
 */
class ScheduledElement extends BaseElement implements TestOnly
{
    private static $table_name = 'EdgeCacheScheduledElement';

    private static $singular_name = 'Scheduled test element';

    private static $db = ['ShowUntil' => 'Datetime'];

    private static $edge_cache_schedule_fields = ['ShowUntil'];

    private static $edge_cache_depends_on = [ScheduledThing::class];
}
