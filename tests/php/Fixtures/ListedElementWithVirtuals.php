<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

/**
 * An element with virtual copies that also holds a many_many list.
 */
class ListedElementWithVirtuals extends ElementWithVirtuals
{
    private static $table_name = 'EdgeCacheListedElementWithVirtuals';

    private static $many_many = ['Targets' => JoinTarget::class];
}
