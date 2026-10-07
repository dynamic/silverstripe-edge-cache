<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Versioned\Versioned;

/**
 * Purges the page an element sits on when the element is published or unpublished.
 *
 * Elements only exist when dnadesign/silverstripe-elemental is installed; the extension is
 * applied to `BaseElement` from config that is skipped without it.
 *
 * An element that shows content from other records can add `edge_cache_depends_on` classes, the
 * same way a page does:
 *
 *     private static $edge_cache_depends_on = [BlogPost::class];
 *
 * Pages carrying such an element get that class tag.
 *
 * @property \DNADesign\Elemental\Models\BaseElement|static $owner
 */
class EdgeCacheElementExtension extends DataExtension
{
    public function onAfterPublish(&$original): void
    {
        $this->purgeOwnerPage();
    }

    public function onBeforeVersionedPublish($fromStage, $toStage): void
    {
        if ($toStage === Versioned::LIVE) {
            $this->purgeOwnerPage();
        }
    }

    public function onAfterUnpublish(): void
    {
        $this->purgeOwnerPage();
    }

    public function onAfterArchive(): void
    {
        $this->purgeOwnerPage();
    }

    /**
     * Tags a page carries because of this element. Called by the controller extension through
     * `updateEdgeCacheTags` for every element in the page's areas.
     *
     * @return string[]
     */
    public function edgeCacheTags(): array
    {
        $tags = [];
        foreach ((array) $this->owner->config()->get('edge_cache_depends_on') as $class) {
            $tags[] = EdgeCache::classTag($class);
        }

        return $tags;
    }

    protected function purgeOwnerPage(): void
    {
        $owner = $this->owner;
        $page = $owner->getPage();
        if ($page && $page->exists()) {
            PurgeQueue::singleton()->addTags(EdgeCache::pageTag($page->ID));

            return;
        }

        // No page found (orphaned, or inside another element's area): clear the site rather than guess.
        PurgeQueue::singleton()->addEverything();
    }
}
