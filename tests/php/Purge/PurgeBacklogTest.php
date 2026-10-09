<?php

namespace Dynamic\EdgeCache\Tests\Purge;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeBacklog;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\UnavailableCache;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;

/**
 * A purge the CDN refuses is kept and sent again with the next one, or by `edge-cache-purge --retry`.
 */
class PurgeBacklogTest extends EdgeCacheTestCase
{
    private function cdnRefuses(): void
    {
        $this->adapter->returnFalse = true;
    }

    private function recover(): void
    {
        $this->adapter->returnFalse = false;
        $this->adapter->fail = false;
        $this->adapter->calls = [];
    }

    public function testARefusedPurgeIsKept(): void
    {
        $this->cdnRefuses();
        PurgeQueue::singleton()->addTags(['ec-page-1', 'ec-page-2'])->addUrls('https://example.com/a.pdf');

        $this->assertFalse(PurgeQueue::singleton()->flush());

        $waiting = PurgeBacklog::singleton()->summary();
        $this->assertSame(['ec-page-1', 'ec-page-2'], $waiting['tags']);
        $this->assertSame(['https://example.com/a.pdf'], $waiting['urls']);
        $this->assertFalse($waiting['everything']);
        $this->assertSame(1, $waiting['attempts']);
    }

    public function testAnExceptionKeepsEverythingThatWasQueued(): void
    {
        $this->adapter->fail = true;
        PurgeQueue::singleton()->addTags('ec-page-1')->addUrls('https://example.com/a.pdf');

        PurgeQueue::singleton()->flush();

        $waiting = PurgeBacklog::singleton()->summary();
        $this->assertSame(['ec-page-1'], $waiting['tags']);
        $this->assertSame(['https://example.com/a.pdf'], $waiting['urls']);
    }

    public function testAnAcceptedPurgeLeavesNothingWaiting(): void
    {
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();

        $this->assertNull(PurgeBacklog::singleton()->summary());
    }

    public function testTheNextFlushSendsWhatWasWaitingWithWhatIsNew(): void
    {
        $this->cdnRefuses();
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        $this->recover();

        PurgeQueue::singleton()->addTags('ec-page-2');
        $this->assertTrue(PurgeQueue::singleton()->flush());

        $this->assertSame([['tags', ['ec-page-2', 'ec-page-1']]], $this->adapter->calls, 'one request');
        $this->assertNull(PurgeBacklog::singleton()->summary());
    }

    public function testRetrySendsOnlyWhatWasWaiting(): void
    {
        $this->cdnRefuses();
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        $this->recover();

        $this->assertTrue(PurgeQueue::singleton()->retry());

        $this->assertSame([['tags', ['ec-page-1']]], $this->adapter->calls);
        $this->assertNull(PurgeBacklog::singleton()->summary());
    }

    public function testRetryWithNothingWaitingSendsNothing(): void
    {
        $this->assertTrue(PurgeQueue::singleton()->retry());

        $this->assertSame([], $this->adapter->calls);
    }

    public function testARetryThatFailsAgainKeepsTheFirstFailureAndCountsAttempts(): void
    {
        $this->cdnRefuses();
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        $first = PurgeBacklog::singleton()->summary()['firstFailed'];

        $this->assertFalse(PurgeQueue::singleton()->retry());
        $this->assertFalse(PurgeQueue::singleton()->retry());

        $waiting = PurgeBacklog::singleton()->summary();
        $this->assertSame($first, $waiting['firstFailed']);
        $this->assertSame(3, $waiting['attempts']);
        $this->assertSame(['ec-page-1'], $waiting['tags']);
    }

    public function testEverythingWaitingSupersedesTags(): void
    {
        $this->cdnRefuses();
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        PurgeQueue::singleton()->addEverything()->flush();

        $waiting = PurgeBacklog::singleton()->summary();
        $this->assertTrue($waiting['everything']);
        $this->assertSame([], $waiting['tags']);
    }

    public function testTooManyWaitingTagsBecomeOnePurgeOfEverything(): void
    {
        Config::modify()->set(PurgeBacklog::class, 'max_tags', 3);
        $this->cdnRefuses();

        PurgeQueue::singleton()->addTags(['a', 'b', 'c', 'd'])->flush();

        $waiting = PurgeBacklog::singleton()->summary();
        $this->assertTrue($waiting['everything']);
        $this->assertSame([], $waiting['tags']);
    }

    public function testAnEntryOlderThanTheEdgeLifetimeIsDropped(): void
    {
        $age = (int) EdgeCache::config()->get('edge_ttl') + 60;
        PurgeBacklog::singleton()->store(
            ['everything' => false, 'tags' => ['ec-page-1'], 'urls' => []],
            time() - $age
        );

        $this->assertNull(PurgeBacklog::singleton()->summary(), 'the pages it covers have expired');
        $this->assertTrue(PurgeQueue::singleton()->retry());
        $this->assertSame([], $this->adapter->calls);
    }

    public function testAnEntryWithinTheEdgeLifetimeIsKept(): void
    {
        $age = (int) EdgeCache::config()->get('edge_ttl') - 60;
        PurgeBacklog::singleton()->store(
            ['everything' => false, 'tags' => ['ec-page-1'], 'urls' => []],
            time() - $age
        );

        $this->assertSame(['ec-page-1'], PurgeBacklog::singleton()->summary()['tags']);
    }

    public function testNothingIsKeptOutsideAnEnabledEnvironment(): void
    {
        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'dev');
        $this->cdnRefuses();

        PurgeQueue::singleton()->addTags('ec-page-1')->flush();

        $this->assertNull(PurgeBacklog::singleton()->summary());
    }

    public function testTheFailureIsLoggedWithAStableMessageAndSaysItWasKept(): void
    {
        $handler = new TestHandler();
        Injector::inst()->registerService(new Logger('test', [$handler]), LoggerInterface::class);
        $this->cdnRefuses();

        PurgeQueue::singleton()->addTags('ec-page-1')->flush();

        $this->assertTrue($handler->hasErrorThatContains(PurgeQueue::FAILURE_MESSAGE));
        $this->assertSame('kept for a retry', $handler->getRecords()[0]['context']['retry']);
    }

    public function testAnUnavailableBacklogCacheDoesNotBreakThePublishAndIsSaid(): void
    {
        $broken = new UnavailableCache();
        Injector::inst()->registerService($broken, CacheInterface::class . '.EdgeCachePurgeBacklog');
        $handler = new TestHandler();
        Injector::inst()->registerService(new Logger('test', [$handler]), LoggerInterface::class);
        $this->cdnRefuses();

        $this->assertFalse(PurgeQueue::singleton()->addTags('ec-page-1')->flush());

        $this->assertStringContainsString('not kept', $handler->getRecords()[0]['context']['retry']);
    }
}
