<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\Assets\File;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Versioned\Versioned;

/**
 * Purges a file's public URL when the file is published, replaced, unpublished or archived, or deleted with its folder.
 *
 * Pages that show the file are not purged: a page references the file by URL, and the URL is what
 * a replacement changes. Cloudflare only caches static files its rules mark eligible, so this
 * matters for sites that cache `/assets/` at the edge.
 *
 * @property File|static $owner
 */
class EdgeCacheFileExtension extends DataExtension
{
    public function onAfterPublish(&$original): void
    {
        $this->purgeFile($original);
    }

    /**
     * Unpublishing and archiving purge before they run. Afterwards the file is protected or deleted,
     * and a protected file's URL is a different, session-granted one, so the public URL the edge holds
     * can only be read while the Live record still exists.
     */
    public function onBeforeUnpublish(): void
    {
        $this->purgeLiveUrl();
    }

    public function onBeforeArchive(): void
    {
        $this->purgeLiveUrl();
    }

    /**
     * Deleting a folder deletes its Live children with a plain delete, which fires neither of the
     * hooks above for them. A delete of the Live record is the one moment their public file is
     * still there. Deletes of the Draft record are not what the edge serves.
     */
    public function onBeforeDelete(): void
    {
        if (Versioned::get_stage() === Versioned::LIVE) {
            $this->queueUrl($this->owner);
        }
    }

    protected function purgeLiveUrl(): void
    {
        $owner = $this->owner;
        if ($owner->ID) {
            $this->queueUrl(Versioned::get_by_stage($owner->baseClass(), Versioned::LIVE)->byID($owner->ID));
        }
    }

    /**
     * @param File|null $original the Live file before this publish; its URL differs when the file moved
     */
    protected function purgeFile($original = null): void
    {
        foreach ([$this->owner, $original] as $file) {
            $this->queueUrl($file);
        }
    }

    protected function queueUrl(mixed $file): void
    {
        if ($file instanceof File && $file->exists() && ($url = $file->getAbsoluteURL())) {
            PurgeQueue::singleton()->addUrls($url);
        }
    }
}
