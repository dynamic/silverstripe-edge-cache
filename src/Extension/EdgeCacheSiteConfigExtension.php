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
                    'Untick to stop edge caching and clear the cached pages. ' . $this->unavailableReason()
                )
        );
    }

    /**
     * Why ticking the box would not start edge caching on this site right now, or an empty string.
     */
    protected function unavailableReason(): string
    {
        $edge = EdgeCache::singleton();
        if (!$edge->isEnvironmentEnabled()) {
            return 'NOT ACTIVE: this environment is not one edge caching runs in ('
                . implode(', ', (array) EdgeCache::config()->get('enabled_environments')) . ').';
        }
        if (!$edge->adapter()->isConfigured()) {
            return 'NOT ACTIVE: the CDN credentials are not set, so pages are not cached and nothing is purged.';
        }

        return '';
    }

    public function onAfterWrite(): void
    {
        PurgeQueue::singleton()->addEverything();
    }
}
