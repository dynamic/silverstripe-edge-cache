<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

/**
 * Inherits its parent's scheduled fields.
 */
class ScheduledSubThing extends ScheduledThing
{
    private static $table_name = 'EdgeCacheScheduledSubThing';

    private static $db = ['Note' => 'Varchar'];
}
