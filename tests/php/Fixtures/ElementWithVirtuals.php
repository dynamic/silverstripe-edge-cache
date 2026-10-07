<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\ArrayList;

/**
 * An element that answers the way an element with dnadesign/silverstripe-elemental-virtual does:
 * getPublishedVirtualElements() returns the virtual copies, each with the page it sits on.
 */
class ElementWithVirtuals extends BaseElement implements TestOnly
{
    private static $table_name = 'EdgeCacheElementWithVirtuals';

    private static $singular_name = 'Test element';

    /**
     * @var array<int, object>
     */
    public static array $virtualPages = [];

    public function getPublishedVirtualElements()
    {
        return ArrayList::create(array_map(
            fn ($page) => new class ($page) {
                public function __construct(private $page)
                {
                }

                public function getPage()
                {
                    return $this->page;
                }
            },
            self::$virtualPages
        ));
    }
}
