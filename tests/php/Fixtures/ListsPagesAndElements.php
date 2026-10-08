<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Page;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A plain record that lists pages and elements, so the page or element is on the far end of the
 * list (a sidebar that links to pages).
 */
class ListsPagesAndElements extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheListsPagesAndElements';

    private static $db = ['Title' => 'Varchar'];

    private static $many_many = [
        'Pages' => Page::class,
        'Elements' => OwnedRelationElement::class,
    ];
}
