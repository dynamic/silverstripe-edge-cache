<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\ORM\DataExtension;
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
 * @property \SilverStripe\ORM\DataObject|static $owner
 */
class EdgeCachePurgeable extends DataExtension
{
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
        $setting = $this->owner->config()->get('edge_cache_purge');
        $queue = PurgeQueue::singleton();

        if ($setting === 'everything') {
            $queue->addEverything();

            return;
        }

        // Pages that listed records of this class carry its class tag, so purge that.
        $tags = EdgeCache::classChainTags($this->owner->ClassName);
        foreach ((array) $setting as $class) {
            $tags[] = EdgeCache::classTag($class);
        }
        $queue->addTags($tags);
    }
}
