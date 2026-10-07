<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
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
