<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

class ScheduledChildThing extends ScheduledParentThing
{
    private static $table_name = 'EdgeCacheScheduledChildThing';

    private static $db = ['ExpiresAt' => 'Datetime'];

    private static $edge_cache_schedule_fields = ['ExpiresAt'];
}
