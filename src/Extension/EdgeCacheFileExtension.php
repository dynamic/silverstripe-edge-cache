<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\Assets\File;
use SilverStripe\Core\Extension;

/**
 * Purges a file's public URL when the file is published, replaced, unpublished or deleted.
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

    public function onAfterUnpublish(): void
    {
        $this->purgeFile();
    }

    public function onAfterArchive(): void
    {
        $this->purgeFile();
    }

    /**
     * @param File|null $original the Live file before this publish; its URL differs when the file moved
     */
    protected function purgeFile($original = null): void
    {
        $queue = PurgeQueue::singleton();
        foreach ([$this->owner, $original] as $file) {
            if ($file instanceof File && $file->exists() && ($url = $file->getAbsoluteURL())) {
                $queue->addUrls($url);
            }
        }
    }
}
