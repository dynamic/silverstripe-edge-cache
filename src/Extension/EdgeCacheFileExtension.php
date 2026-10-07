<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\Assets\File;
use SilverStripe\Core\Extension;
use SilverStripe\Versioned\Versioned;

/**
 * Purges a file's public URL when the file is published, replaced, unpublished or archived.
 *
 * Pages that show the file are not purged: a page references the file by URL, and the URL is what
 * a replacement changes. Cloudflare only caches static files its rules mark eligible, so this
 * matters for sites that cache `/assets/` at the edge.
 *
 * @property File|static $owner
 */
class EdgeCacheFileExtension extends Extension
{
    public function onAfterPublish(&$original): void
    {
        $this->purgeFile($original);
    }

    /**
     * Unpublishing and archiving purge before they run. Afterwards the file is protected and its URL
     * is a different, session-granted one, and an archived record has no ID left, so the public URL
     * the edge holds can only be read while the Live record still exists.
     */
    public function onBeforeUnpublish(): void
    {
        $this->purgeLiveUrl();
    }

    public function onBeforeArchive(): void
    {
        $this->purgeLiveUrl();
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
