<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataExtension;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The Settings switch for edge caching, and a site-wide purge whenever Settings change (the
 * navigation, footer and scripts on every page can come from Settings).
 *
 * Turning the switch off purges the site, so the edge drains at once instead of serving the last
 * cached copies until they expire.
 *
 * @property SiteConfig|static $owner
 * @property bool $EdgeCacheEnabled
 */
class EdgeCacheSiteConfigExtension extends DataExtension
{
    private static $db = [
        'EdgeCacheEnabled' => 'Boolean',
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->addFieldToTab(
            'Root.Caching',
            CheckboxField::create('EdgeCacheEnabled', 'Serve pages from the CDN edge cache')
                ->setDescription(
                    'Only takes effect in the environments the module is enabled for (live by default). '
                    . 'Untick to stop edge caching and clear the cached pages.'
                )
        );
    }

    public function onAfterWrite(): void
    {
        PurgeQueue::singleton()->addEverything();
    }
}
