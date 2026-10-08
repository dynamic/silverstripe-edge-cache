<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Dev\TestOnly;

/**
 * An element holding many_many relations to a class that did not opt in to purging (a list of FAQs,
 * a list of categories), one plain and one through.
 */
class OwnedRelationElement extends BaseElement implements TestOnly
{
    private static $table_name = 'EdgeCacheOwnedRelationElement';

    private static $singular_name = 'Owned relation element';

    private static $has_many = ['Joins' => OwnedElementJoin::class . '.Owner'];

    private static $many_many = [
        'Targets' => JoinTarget::class,
        'ThroughTargets' => [
            'through' => OwnedElementJoin::class,
            'from' => 'Owner',
            'to' => 'Target',
        ],
    ];
}
