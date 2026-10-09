<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

/**
 * An owned-relation element that reports whatever owner a test sets, standing in for an element
 * inside a group or one that sits under a template.
 */
class OwnedRelationElementUnderOwner extends OwnedRelationElement
{
    public static mixed $owner = null;

    private static $table_name = 'EdgeCacheOwnedRelationElementUnderOwner';

    public function getPage()
    {
        return static::$owner;
    }
}
