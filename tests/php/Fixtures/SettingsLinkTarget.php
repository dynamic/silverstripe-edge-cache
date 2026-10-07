<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * A record that can be linked to Settings from its own edit form.
 */
class SettingsLinkTarget extends DataObject implements TestOnly
{
    private static $table_name = 'EdgeCacheSettingsLinkTarget';

    private static $db = ['Title' => 'Varchar'];

    private static $belongs_many_many = ['SettingsOwners' => SiteConfig::class . '.SettingsLinkTargets'];
}
