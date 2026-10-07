<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\ListedSubThing;
use Dynamic\EdgeCache\Tests\Fixtures\ListedThing;
use Dynamic\EdgeCache\Tests\Fixtures\PurgeableThing;
use Dynamic\EdgeCache\Tests\Fixtures\SitewideThing;
use SilverStripe\Versioned\Versioned;

class EdgeCachePurgeableTest extends EdgeCacheTestCase
{
    protected static $extra_dataobjects = [
        ListedThing::class,
        ListedSubThing::class,
        PurgeableThing::class,
        SitewideThing::class,
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
}
