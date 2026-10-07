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
 * Apply it to the class and say what to purge:
 *
 *     Vendor\\Model\\Testimonial:
 *       extensions:
 *         - Dynamic\\EdgeCache\\Extension\\EdgeCachePurgeable
 *       edge_cache_purge: everything     # or a list of classes whose pages show it
 *       edge_cache_purge: ['Page', 'Vendor\\Model\\TestimonialsPage']
 *
 * `everything` clears the whole site. A list of class names clears pages of those classes through
 * their class tag. A record that is Versioned purges on publish; one that is not purges on write.
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

        if (!$setting || $setting === 'everything') {
            $queue->addEverything();

            return;
        }

        $queue->addTags(array_map(fn ($class) => EdgeCache::classTag($class), (array) $setting));
    }
}
