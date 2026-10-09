<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
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
 * Adding, removing, clearing or reordering the records of a many_many relation it belongs to purges
 * too, from either side of the relation, since those write only a join table and fire no record
 * event (the links in a navigation group). The join table is not versioned, so the purge is
 * immediate whatever stage the owner is on.
 *
 * @property DataObject|static $owner
 */
class EdgeCachePurgeable extends Extension
{
    /**
     * Name the purge callback is registered under on a relation list (by EdgeCacheQueryExtension).
     * The reorder extension looks it up by this name.
     */
    public const RELATION_CALLBACK = 'edge-cache-purge';

    public function onAfterWrite(): void
    {
        // A versioned record purges when it is published, or when it is written to the Live stage
        // without a publish (the content API); a Draft write changes nothing the edge serves.
        if (!$this->owner->hasExtension(Versioned::class) || Versioned::get_stage() === Versioned::LIVE) {
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
        static::purgeClass(get_class($this->owner));
    }

    /**
     * Queue the purge a record of this class needs: the whole site, or the class tag chain plus any
     * listed classes. Also used for a relation change, which has the class but no record event.
     */
    public static function purgeClass(string $class): void
    {
        $setting = Config::inst()->get($class, 'edge_cache_purge');
        $queue = PurgeQueue::singleton();

        if ($setting === 'everything') {
            $queue->addEverything();

            return;
        }

        // Pages that listed records of this class carry its class tag, so purge that.
        $tags = EdgeCache::classChainTags($class);
        foreach ((array) $setting as $class) {
            $tags[] = EdgeCache::classTag($class);
        }
        $queue->addTags($tags);
    }
}
