<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\ListedSubThing;
use Dynamic\EdgeCache\Tests\Fixtures\ListedThing;
use Dynamic\EdgeCache\Tests\Fixtures\PurgeableBase;
use Dynamic\EdgeCache\Tests\Fixtures\PurgeableSub;
use Dynamic\EdgeCache\Tests\Fixtures\PurgeableThing;
use Dynamic\EdgeCache\Tests\Fixtures\PurgeableWithList;
use Dynamic\EdgeCache\Tests\Fixtures\SitewideThing;
use Dynamic\EdgeCache\Tests\Fixtures\VersionedPurgeableThing;
use Dynamic\EdgeCache\Tests\Fixtures\VersionedSitewideThing;
use SilverStripe\Versioned\Versioned;

class EdgeCachePurgeableTest extends EdgeCacheTestCase
{
    protected static $extra_dataobjects = [
        ListedThing::class,
        ListedSubThing::class,
        PurgeableThing::class,
        SitewideThing::class,
        PurgeableBase::class,
        PurgeableSub::class,
        PurgeableWithList::class,
        VersionedPurgeableThing::class,
        VersionedSitewideThing::class,
    ];

    public function testSavingAPlainRecordPurgesPagesThatListItsClass(): void
    {
        PurgeableThing::create(['Title' => 'One'])->write();

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertSame(['ec-class-PurgeableThing'], $pending['tags']);
    }

    public function testDeletingAPlainRecordPurgesToo(): void
    {
        $thing = PurgeableThing::create(['Title' => 'One']);
        $thing->write();
        PurgeQueue::singleton()->reset();

        $thing->delete();

        $this->assertSame(['ec-class-PurgeableThing'], PurgeQueue::singleton()->pending()['tags']);
    }

    public function testASitewideRecordClearsTheSite(): void
    {
        SitewideThing::create(['Title' => 'Footer link'])->write();

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testAnUnrelatedRecordPurgesNothing(): void
    {
        ListedThing::create(['Title' => 'Draft only'])->write();

        $this->assertTrue(PurgeQueue::singleton()->isEmpty(), 'no purge extension on this class');
    }

    public function testAVersionedRecordPurgesOnPublishNotOnDraftSave(): void
    {
        Versioned::set_stage(Versioned::DRAFT);
        $thing = VersionedPurgeableThing::create(['Title' => 'Draft']);
        $thing->write();

        $this->assertTrue(PurgeQueue::singleton()->isEmpty(), 'a draft save is not visible to visitors');

        $thing->publishSingle();

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertSame(['ec-class-VersionedPurgeableThing'], $pending['tags']);
    }

    public function testAVersionedRecordPublishedThroughCopyVersionToStage(): void
    {
        // The content API publishes this way.
        Versioned::set_stage(Versioned::DRAFT);
        $thing = VersionedPurgeableThing::create(['Title' => 'Via API']);
        $thing->write();
        PurgeQueue::singleton()->reset();

        $thing->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertSame(['ec-class-VersionedPurgeableThing'], $pending['tags']);
    }

    public function testAVersionedRecordWrittenToTheLiveStageWithoutAPublishPurges(): void
    {
        Versioned::set_stage(Versioned::DRAFT);
        $thing = VersionedPurgeableThing::create(['Title' => 'Via API']);
        $thing->writeToStage(Versioned::DRAFT);
        $this->assertTrue(PurgeQueue::singleton()->isEmpty(), 'the Draft half purges nothing');

        $thing->writeToStage(Versioned::LIVE);

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertSame(['ec-class-VersionedPurgeableThing'], $pending['tags']);
    }

    public function testCopyingToDraftPurgesNothing(): void
    {
        Versioned::set_stage(Versioned::DRAFT);
        $thing = VersionedPurgeableThing::create(['Title' => 'Reverting']);
        $thing->write();
        $thing->publishSingle();
        PurgeQueue::singleton()->reset();

        $thing->copyVersionToStage(Versioned::LIVE, Versioned::DRAFT);

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testUnpublishingAVersionedRecordPurges(): void
    {
        Versioned::set_stage(Versioned::DRAFT);
        $thing = VersionedPurgeableThing::create(['Title' => 'Going']);
        $thing->write();
        $thing->publishSingle();
        PurgeQueue::singleton()->reset();

        $thing->doUnpublish();

        $this->assertSame(['ec-class-VersionedPurgeableThing'], PurgeQueue::singleton()->pending()['tags']);
    }

    public function testASitewideVersionedRecordClearsTheSiteOnPublish(): void
    {
        Versioned::set_stage(Versioned::DRAFT);
        $thing = VersionedSitewideThing::create(['Title' => 'Footer']);
        $thing->write();
        $this->assertTrue(PurgeQueue::singleton()->isEmpty());

        $thing->publishSingle();

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testSavingASubclassPurgesTheBaseClassTagToo(): void
    {
        // A page that queried PurgeableBase::get() carries only the base tag.
        PurgeableSub::create(['Title' => 'Sub'])->write();

        $this->assertSame(
            ['ec-class-PurgeableBase', 'ec-class-PurgeableSub'],
            PurgeQueue::singleton()->pending()['tags']
        );
    }

    public function testAListSettingAddsToTheClassChain(): void
    {
        PurgeableWithList::create(['Title' => 'Team member'])->write();

        $this->assertSame(
            ['ec-class-PurgeableWithList', 'ec-class-TeamPage', 'ec-class-Elsewhere'],
            PurgeQueue::singleton()->pending()['tags']
        );
    }
}
