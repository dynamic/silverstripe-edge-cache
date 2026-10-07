<?php

namespace Dynamic\EdgeCache\Tests\Purge;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use SilverStripe\Core\Environment;

class PurgeQueueTest extends EdgeCacheTestCase
{
    public function testManyAdditionsBecomeOnePurge(): void
    {
        $queue = PurgeQueue::singleton();
        foreach (range(1, 50) as $id) {
            $queue->addTags(['ec-page-' . $id, 'ec-class-Page']);
        }
        $queue->addTags('ec-page-1');
        $queue->flush();

        $this->assertCount(1, $this->adapter->calls);
        $this->assertSame('tags', $this->adapter->calls[0][0]);
        $this->assertCount(51, $this->adapter->calls[0][1]);
    }

    public function testEverythingWinsOverMoreSpecificPurges(): void
    {
        $queue = PurgeQueue::singleton();
        $queue->addTags('ec-page-1')->addUrls('https://example.com/a.pdf')->addEverything();
        $queue->flush();

        $this->assertSame([['everything', []]], $this->adapter->calls);
    }

    public function testTagsAndUrlsGoOutSeparately(): void
    {
        PurgeQueue::singleton()->addTags('ec-page-1')->addUrls('https://example.com/a.pdf');
        PurgeQueue::singleton()->flush();

        $this->assertSame([['tags', ['ec-page-1']], ['urls', ['https://example.com/a.pdf']]], $this->adapter->calls);
    }

    public function testFlushEmptiesTheQueue(): void
    {
        PurgeQueue::singleton()->addTags('ec-page-1');
        PurgeQueue::singleton()->flush();
        PurgeQueue::singleton()->flush();

        $this->assertCount(1, $this->adapter->calls);
        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testNothingIsSentOutsideAnEnabledEnvironment(): void
    {
        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'dev');
        PurgeQueue::singleton()->addTags('ec-page-1')->addEverything();
        PurgeQueue::singleton()->flush();

        $this->assertSame([], $this->adapter->calls);
        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testAFailingCdnDoesNotThrow(): void
    {
        $this->adapter->fail = true;
        PurgeQueue::singleton()->addTags('ec-page-1');

        PurgeQueue::singleton()->flush();

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testPurgingStillWorksWhenTheSettingsToggleIsOff(): void
    {
        $this->setToggle(false);
        PurgeQueue::singleton()->addEverything();
        PurgeQueue::singleton()->flush();

        $this->assertSame([['everything', []]], $this->adapter->calls);
    }
}
