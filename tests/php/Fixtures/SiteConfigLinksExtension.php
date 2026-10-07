<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataExtension;

/**
 * A list on Settings, the way a site adds utility or footer links.
 */
class SiteConfigLinksExtension extends DataExtension implements TestOnly
{
    private static $many_many = ['LinkTargets' => JoinTarget::class];

    private static $many_many_extraFields = ['LinkTargets' => ['Sort' => 'Int']];
}
