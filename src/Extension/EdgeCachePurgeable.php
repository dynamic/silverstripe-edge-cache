<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\RelationList;
use SilverStripe\Versioned\Versioned;

/**
 * Purges the edge when a record that pages display (a testimonial, a team member, a logo) is
 * written, published or deleted.
 *
 * Apply it to the class:
 *
 *     Vendor\\Model\\Testimonial:
 *       extensions:
 *         - Dynamic\\EdgeCache\\Extension\\EdgeCachePurgeable
 *
 * By default it clears the pages that listed records of that class (they carry its class tag).
 * `edge_cache_purge: everything` clears the whole site, for a record shown on every page (footer
 * links). A list of class names adds those class tags too. A record that is Versioned purges on
 * publish; one that is not purges on write.
 *
 * Adding, removing or clearing the records of one of its many_many relations purges too, since those
 * write only a join table and fire no record event (the links in a navigation group). The join
 * table is not versioned, so the purge is immediate whatever stage the owner is on.
 *
 * @property DataObject|static $owner
 */
class EdgeCachePurgeable extends DataExtension
{
    /**
     * Name the purge callback is registered under on a relation list. The reorder extension looks
     * it up by this name.
     */
    public const RELATION_CALLBACK = 'edge-cache-purge';

    /**
     * Hooks every many_many list this record hands out (both sides of the relation, and
     * many_many through) so a change to its members purges.
     */
    public function updateManyManyComponents(RelationList $list): void
    {
        // The extension's owner is only set while a hook runs, so keep it for the later call.
        $owner = $this->owner;
        $callback = function () use ($owner): void {
            $this->purgeFor($owner);
        };
        $list->addCallbacks()->add($callback, self::RELATION_CALLBACK);
        $list->removeCallbacks()->add($callback, self::RELATION_CALLBACK);
    }

    public function onAfterWrite(): void
    {
        if (!$this->owner->hasExtension(Versioned::class)) {
            $this->purge();
        }
    }

    public function onAfterDelete(): void
    {
        if (!$this->owner->hasExtension(Versioned::class)) {
            $this->purge();
        }
    }

    public function onAfterPublish(&$original): void
    {
        $this->purge();
    }

    public function onBeforeVersionedPublish($fromStage, $toStage): void
    {
        if ($toStage === Versioned::LIVE) {
            $this->purge();
        }
    }

    public function onAfterUnpublish(): void
    {
        $this->purge();
    }

    public function onAfterArchive(): void
    {
        $this->purge();
    }

    protected function purge(): void
    {
        $this->purgeFor($this->owner);
    }

    protected function purgeFor(DataObject $owner): void
    {
        $setting = $owner->config()->get('edge_cache_purge');
        $queue = PurgeQueue::singleton();

        if ($setting === 'everything') {
            $queue->addEverything();

            return;
        }

        // Pages that listed records of this class carry its class tag, so purge that.
        $tags = EdgeCache::classChainTags(get_class($owner));
        foreach ((array) $setting as $class) {
            $tags[] = EdgeCache::classTag($class);
        }
        $queue->addTags($tags);
    }
}
