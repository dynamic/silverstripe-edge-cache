<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;

/**
 * A file's public URL is purged when the file changes or goes away, so an image served from the
 * edge does not outlive its replacement or its deletion.
 */
class EdgeCacheFileExtensionTest extends EdgeCacheTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('EdgeCacheFileExtensionTest');
        Versioned::set_stage(Versioned::DRAFT);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function publishedFile(): File
    {
        $file = File::create();
        $file->setFromString('first version', 'cache-test.txt');
        $file->write();
        $file->publishSingle();
        PurgeQueue::singleton()->reset();

        return $file;
    }

    public function testPublishingAChangedFilePurgesItsUrl(): void
    {
        $file = $this->publishedFile();
        $url = $file->getAbsoluteURL();

        $file->Title = 'Changed';
        $file->write();
        $file->publishSingle();

        $this->assertContains($url, PurgeQueue::singleton()->pending()['urls']);
    }

    public function testUnpublishingAFilePurgesItsUrl(): void
    {
        $file = $this->publishedFile();
        $url = $file->getAbsoluteURL();

        $file->doUnpublish();

        $this->assertContains($url, PurgeQueue::singleton()->pending()['urls']);
    }

    public function testArchivingAFilePurgesItsUrl(): void
    {
        $file = $this->publishedFile();
        $url = $file->getAbsoluteURL();
        $this->assertNotSame('', $url);

        $file->doArchive();

        $this->assertContains($url, PurgeQueue::singleton()->pending()['urls']);
    }

    public function testArchivingAFileWithUnpublishedChangesPurgesTheUrlTheEdgeHolds(): void
    {
        $file = $this->publishedFile();
        $publicUrl = $file->getAbsoluteURL();

        $file->setFromString('second version, never published', 'cache-test.txt');
        $file->write();
        $this->assertNotSame($publicUrl, $file->getAbsoluteURL(), 'the draft has its own URL');

        $file->doArchive();

        $this->assertContains($publicUrl, PurgeQueue::singleton()->pending()['urls']);
    }

    public function testArchivingAFolderPurgesTheUrlsOfTheFilesInIt(): void
    {
        $folder = Folder::create(['Name' => 'banners']);
        $folder->write();
        $folder->publishSingle();
        $file = File::create(['ParentID' => $folder->ID]);
        $file->setFromString('in a folder', 'banners/hero.txt');
        $file->write();
        $file->publishSingle();
        $url = $file->getAbsoluteURL();
        $this->assertStringContainsString('/banners/hero.txt', $url);
        PurgeQueue::singleton()->reset();

        $folder->doArchive();

        $this->assertContains($url, PurgeQueue::singleton()->pending()['urls']);
    }

    /**
     * Writes a resized variant next to a published file, as a template's `$Image.Fill(...)` does, and
     * returns its public URL.
     */
    private function variantOf(File $file, string $variant = 'FillWzEwMCwxMDBd'): string
    {
        $store = Injector::inst()->get(AssetStore::class);
        $store->setFromString('variant', $file->getFilename(), $file->getHash(), $variant);

        return Director::absoluteURL(
            $store->getAsURL($file->getFilename(), $file->getHash(), $variant, false)
        );
    }

    public function testArchivingAFilePurgesItsResizedVariantsToo(): void
    {
        $file = $this->publishedFile();
        $variantUrl = $this->variantOf($file);
        $original = $file->getAbsoluteURL();
        $this->assertNotSame($original, $variantUrl);

        $file->doArchive();

        $urls = PurgeQueue::singleton()->pending()['urls'];
        $this->assertContains($original, $urls);
        $this->assertContains($variantUrl, $urls);
    }

    public function testUnpublishingAFilePurgesItsResizedVariantsToo(): void
    {
        $file = $this->publishedFile();
        $variantUrl = $this->variantOf($file);

        $file->doUnpublish();

        $this->assertContains($variantUrl, PurgeQueue::singleton()->pending()['urls']);
    }

    public function testReplacingAFilesContentPurgesTheOldVariants(): void
    {
        $file = $this->publishedFile();
        $variantUrl = $this->variantOf($file);

        $file->setFromString('second version', 'cache-test.txt');
        $file->write();
        $file->publishSingle();

        $this->assertContains($variantUrl, PurgeQueue::singleton()->pending()['urls']);
    }

    public function testArchivingADraftOnlyFileQueuesNothing(): void
    {
        $file = File::create();
        $file->setFromString('draft', 'draft-test.txt');
        $file->write();
        PurgeQueue::singleton()->reset();

        $file->doArchive();

        $this->assertSame([], PurgeQueue::singleton()->pending()['urls']);
    }
}
