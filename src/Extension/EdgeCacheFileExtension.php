<?php

namespace Dynamic\EdgeCache\Extension;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use Psr\Log\LoggerInterface;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Versioned\Versioned;
use Throwable;

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
     * Replacing a file's content publishes over the same public path and deletes the old file's resized
     * variants, so the Live record's URLs are purged before that happens. `publishSingle()` hands over
     * the Live record, and `copyVersionToStage()` (the content API) is covered below.
     *
     * @param File|null $original the Live file before this publish
     */
    public function onBeforePublish(&$original): void
    {
        $this->queueUrl($original);
    }

    public function onBeforeVersionedPublish($fromStage, $toStage): void
    {
        if ($toStage === Versioned::LIVE) {
            $this->purgeLiveUrl();
        }
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
            PurgeQueue::singleton()->addUrls(array_merge([$url], $this->variantUrls($file)));
        }
    }

    /**
     * Public URLs of a file's resized variants (`name__FillWzEwMCwxMDBd.jpg`), which pages and
     * templates mostly render and which the edge caches under their own URLs. A lookup that fails is
     * logged, and the file's own URL is still purged.
     *
     * @return string[]
     */
    protected function variantUrls(File $file): array
    {
        try {
            $store = Injector::inst()->get(AssetStore::class);
            // A site may swap in another store; only the Flysystem one can list variants.
            if (!$store instanceof FlysystemAssetStore || !$file->getHash()) { // @phpstan-ignore instanceof.alwaysTrue
                return [];
            }

            $urls = [];
            $tuple = ['Filename' => $file->getFilename(), 'Hash' => $file->getHash(), 'Variant' => ''];
            $strategy = $store->getPublicResolutionStrategy();
            foreach ($strategy->findVariants($tuple, $store->getPublicFilesystem()) as $variant) {
                if ($variant->getVariant() !== '') {
                    $urls[] = Director::absoluteURL(
                        $store->getAsURL($variant->getFilename(), $variant->getHash(), $variant->getVariant(), false)
                    );
                }
            }

            return $urls;
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'Edge cache could not list the resized variants of a file, so only the file itself was purged: '
                . $e::class . ': ' . $e->getMessage()
            );

            return [];
        }
    }
}
