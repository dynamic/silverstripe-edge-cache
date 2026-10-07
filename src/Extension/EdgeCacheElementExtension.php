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
        $queue = PurgeQueue::singleton();

        // Pages that show this element through a virtual copy (dnadesign/silverstripe-elemental-virtual).
        // A virtual element renders the linked element's content but is not republished with it.
        foreach ($this->virtualPages() as $virtualPage) {
            $queue->addTags(EdgeCache::pageTag($virtualPage->ID));
        }

        $page = $owner->getPage();
        if ($page && $page->exists()) {
            $queue->addTags(EdgeCache::pageTag($page->ID));

            return;
        }

        // No page found (orphaned, or inside another element's area): clear the site rather than guess.
        $queue->addEverything();
    }

    /**
     * Pages holding a published virtual copy of this element.
     *
     * @return array<int, \SilverStripe\CMS\Model\SiteTree>
     */
    protected function virtualPages(): array
    {
        if (!$this->owner->hasMethod('getPublishedVirtualElements')) {
            return [];
        }

        $pages = [];
        // Provided by elemental-virtual's own extension, which this module does not depend on.
        /** @var iterable<object> $virtuals */
        $virtuals = $this->owner->getPublishedVirtualElements(); // @phpstan-ignore method.notFound
        foreach ($virtuals as $virtual) {
            $page = $virtual->getPage();
            if ($page && $page->exists()) {
                $pages[$page->ID] = $page;
            }
        }

        return $pages;
    }
}
