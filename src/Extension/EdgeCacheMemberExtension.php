<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\Member;

/**
 * Purges the pages that list members (the author of a blog post) when a member's name changes or a
 * member is deleted.
 *
 * Only the fields in `edge_cache_purge_fields` count, so the writes a login makes (last visited,
 * failed logins, password hash) never purge. A page is tagged `ec-class-Member` when it queries
 * members while it renders.
 *
 * @property Member|static $owner
 */
class EdgeCacheMemberExtension extends DataExtension
{
    /**
     * Fields whose change alters how a member is shown on a page.
     *
     * @config
     * @var string[]
     */
    private static $edge_cache_purge_fields = ['FirstName', 'Surname'];

    private bool $shownFieldChanged = false;

    public function onBeforeWrite(): void
    {
        $this->shownFieldChanged = false;
        foreach ((array) $this->owner->config()->get('edge_cache_purge_fields') as $field) {
            if ($this->owner->isChanged($field)) {
                $this->shownFieldChanged = true;
                break;
            }
        }
    }

    public function onAfterWrite(): void
    {
        if ($this->shownFieldChanged) {
            $this->shownFieldChanged = false;
            $this->purge();
        }
    }

    public function onAfterDelete(): void
    {
        $this->purge();
    }

    protected function purge(): void
    {
        PurgeQueue::singleton()->addTags(EdgeCache::classChainTags(get_class($this->owner)));
    }
}
