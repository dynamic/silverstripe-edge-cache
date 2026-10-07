<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Versioned\Versioned;
use Throwable;

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
        $edge = EdgeCache::singleton();
        foreach ((array) $this->owner->config()->get('edge_cache_depends_on') as $class) {
            $tags[] = EdgeCache::classTag($class);
            $edge->declareClass($class);
        }

        // An element that shows itself between a start and an end time (`edge_cache_schedule_fields`)
        // is a class the module never collects, so say so here.
        if ($this->owner->config()->get('edge_cache_schedule_fields')) {
            $edge->declareClass(get_class($this->owner));
        }

        return $tags;
    }

    protected function purgeOwnerPage(): void
    {
        $queue = PurgeQueue::singleton();

        try {
            $pages = $this->owner->hasMethod('getPage') ? [$this->owner->getPage()] : [null];
            $virtual = $this->virtualPages();
        } catch (Throwable $e) {
            // Looking up where an element is shown must never break the editor's publish. If it
            // cannot be answered, clear the site rather than leave a page showing the old element.
            $queue->addEverything();
            $this->logLookupFailure($e);

            return;
        }

        foreach (array_merge($pages, $virtual) as $page) {
            // No page (an orphaned area), or an owner that is not a page (an element inside another
            // element's area): which pages show this element is unknown, so clear the site.
            if (!$page instanceof SiteTree || !$page->exists()) {
                $queue->addEverything();

                return;
            }
            $queue->addTags(EdgeCache::pageTag($page->ID));
        }
    }

    /**
     * Pages holding a published virtual copy of this element (dnadesign/silverstripe-elemental-virtual,
     * which renders the linked element's content but is not republished with it). An entry that is
     * not a page means a copy whose page could not be found.
     *
     * @return array<int, mixed>
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
            $pages[] = $virtual->getPage();
        }

        return $pages;
    }

    protected function logLookupFailure(Throwable $e): void
    {
        try {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'Edge cache could not find the pages showing an element, so the whole site was purged: '
                . $e::class . ': ' . $e->getMessage()
            );
        } catch (Throwable) {
            // Nowhere left to report to.
        }
    }
}
