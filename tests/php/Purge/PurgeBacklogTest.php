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
use SilverStripe\Versioned\Versioned;

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

        $waiting = PurgeBacklog::singleton()->peek();
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

        $waiting = PurgeBacklog::singleton()->peek();
        $this->assertSame(['ec-page-1'], $waiting['tags']);
        $this->assertSame(['https://example.com/a.pdf'], $waiting['urls']);
    }

    public function testAnAcceptedPurgeLeavesNothingWaiting(): void
    {
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();

        $this->assertNull(PurgeBacklog::singleton()->peek());
    }

    public function testTheNextFlushSendsWhatWasWaitingWithWhatIsNew(): void
    {
        $this->cdnRefuses();
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        $this->recover();

        PurgeQueue::singleton()->addTags('ec-page-2');
        $this->assertTrue(PurgeQueue::singleton()->flush());

        $this->assertSame([['tags', ['ec-page-2', 'ec-page-1']]], $this->adapter->calls, 'one request');
        $this->assertNull(PurgeBacklog::singleton()->peek());
    }

    public function testRetrySendsOnlyWhatWasWaiting(): void
    {
        $this->cdnRefuses();
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        $this->recover();

        $this->assertTrue(PurgeQueue::singleton()->retry());

        $this->assertSame([['tags', ['ec-page-1']]], $this->adapter->calls);
        $this->assertNull(PurgeBacklog::singleton()->peek());
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
        $first = PurgeBacklog::singleton()->peek()['firstFailed'];

        $this->assertFalse(PurgeQueue::singleton()->retry());
        $this->assertFalse(PurgeQueue::singleton()->retry());

        $waiting = PurgeBacklog::singleton()->peek();
        $this->assertSame($first, $waiting['firstFailed']);
        $this->assertSame(3, $waiting['attempts']);
        $this->assertSame(['ec-page-1'], $waiting['tags']);
    }

    public function testEverythingWaitingSupersedesTags(): void
    {
        $this->cdnRefuses();
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        PurgeQueue::singleton()->addEverything()->flush();

        $waiting = PurgeBacklog::singleton()->peek();
        $this->assertTrue($waiting['everything']);
        $this->assertSame([], $waiting['tags']);
    }

    public function testTooManyWaitingTagsBecomeOnePurgeOfEverything(): void
    {
        Config::modify()->set(PurgeBacklog::class, 'max_tags', 3);
        $this->cdnRefuses();

        PurgeQueue::singleton()->addTags(['a', 'b', 'c', 'd'])->flush();

        $waiting = PurgeBacklog::singleton()->peek();
        $this->assertTrue($waiting['everything']);
        $this->assertSame([], $waiting['tags']);
    }

    /**
     * Put tags in the backlog as if each had first failed at the given time.
     *
     * @param array<string, int> $tags tag => seconds ago
     */
    private function seed(array $tags, int $attempts = 1): void
    {
        $now = time();
        Injector::inst()->get(CacheInterface::class . '.EdgeCachePurgeBacklog')->set('backlog', [
            'everything' => null,
            'tags' => array_map(fn ($ago) => $now - $ago, $tags),
            'urls' => [],
            'firstFailed' => $now - max($tags),
            'lastFailed' => $now,
            'attempts' => $attempts,
            'version' => 'seed',
        ]);
    }

    private function ttl(): int
    {
        return (int) EdgeCache::config()->get('edge_ttl');
    }

    public function testATagThatHasWaitedLongerThanTheEdgeLifetimeIsDropped(): void
    {
        $this->seed(['old' => $this->ttl() + 60, 'recent' => 30]);

        $this->assertSame(['recent'], PurgeBacklog::singleton()->peek()['tags'], 'the pages it covered have expired');
    }

    public function testABacklogOfOnlyExpiredItemsIsEmpty(): void
    {
        $this->seed(['old' => $this->ttl() + 60]);

        $this->assertNull(PurgeBacklog::singleton()->peek());
        $this->assertTrue(PurgeQueue::singleton()->retry());
        $this->assertSame([], $this->adapter->calls);
    }

    public function testAFreshFailureIsNotDroppedBecauseAnOlderOneIsAboutToExpire(): void
    {
        // The first purge failed just under the edge lifetime ago; the outage is still on and a new
        // purge fails now. The new one must wait a full lifetime of its own.
        $this->seed(['old' => $this->ttl() - 30]);
        $this->cdnRefuses();

        PurgeQueue::singleton()->addTags('new')->flush();

        $stored = Injector::inst()->get(CacheInterface::class . '.EdgeCachePurgeBacklog')->get('backlog');
        $this->assertEqualsWithDelta(time() - $this->ttl() + 30, $stored['tags']['old'], 3, 'keeps its own time');
        $this->assertEqualsWithDelta(time(), $stored['tags']['new'], 3);
    }

    public function testNothingIsKeptOutsideAnEnabledEnvironment(): void
    {
        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'dev');
        $this->cdnRefuses();

        PurgeQueue::singleton()->addTags('ec-page-1')->flush();

        $this->assertNull(PurgeBacklog::singleton()->peek());
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

    public function testOnlyThePurgeKindTheCdnRefusedIsKept(): void
    {
        $this->adapter->refuses = ['urls'];
        PurgeQueue::singleton()->addTags('ec-page-1')->addUrls('https://example.com/a.pdf')->flush();

        $waiting = PurgeBacklog::singleton()->peek();
        $this->assertSame([], $waiting['tags'], 'the tags went through');
        $this->assertSame(['https://example.com/a.pdf'], $waiting['urls']);

        $this->adapter->refuses = ['tags'];
        PurgeBacklog::singleton()->clear();
        PurgeQueue::singleton()->addTags('ec-page-1')->addUrls('https://example.com/a.pdf')->flush();

        $waiting = PurgeBacklog::singleton()->peek();
        $this->assertSame(['ec-page-1'], $waiting['tags']);
        $this->assertSame([], $waiting['urls'], 'the URLs went through');
    }

    public function testTheBacklogIsStillThereWhileItIsBeingSent(): void
    {
        // A request killed mid-send (a timeout during a slow CDN call) must not lose what was waiting.
        $this->cdnRefuses();
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        $this->recover();
        $seen = null;
        $this->adapter->whileSending = function () use (&$seen) {
            $seen = PurgeBacklog::singleton()->peek();
        };

        PurgeQueue::singleton()->retry();

        $this->assertSame(['ec-page-1'], $seen['tags']);
        $this->assertNull(PurgeBacklog::singleton()->peek(), 'removed once the CDN accepted it');
    }

    public function testAPurgeThatFailedWhileAnotherWasBeingSentIsNotRemovedBySuccess(): void
    {
        $this->cdnRefuses();
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        $this->recover();
        $this->adapter->whileSending = function () {
            // Another request fails and stores its own purge while this one is on the wire.
            PurgeBacklog::singleton()->settle(null, ['everything' => false, 'tags' => ['ec-page-9'], 'urls' => []]);
        };

        $this->assertTrue(PurgeQueue::singleton()->retry());

        // The other request's purge stays (the older tag may too, and is sent again; that is harmless).
        $this->assertContains('ec-page-9', PurgeBacklog::singleton()->peek()['tags']);
    }

    public function testAFailureStoredWhileSendingIsKeptAlongsideWhatFailedAgain(): void
    {
        $this->cdnRefuses();
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        $this->adapter->whileSending = function () {
            $this->adapter->whileSending = null;
            PurgeBacklog::singleton()->settle(null, ['everything' => false, 'tags' => ['ec-page-9'], 'urls' => []]);
        };

        $this->assertFalse(PurgeQueue::singleton()->retry());

        $tags = PurgeBacklog::singleton()->peek()['tags'];
        sort($tags);
        $this->assertSame(['ec-page-1', 'ec-page-9'], $tags);
    }

    public function testTheBacklogIsTheSameInEveryReadingMode(): void
    {
        // Versioned wraps the default cache factory and keys every entry by reading mode, so the CMS
        // (Draft), the front end (Live) and sake (none) would each see a different backlog. Use the
        // real service, not the in-memory one the other tests register.
        $name = CacheInterface::class . '.EdgeCachePurgeBacklog';
        Injector::inst()->unregisterNamedObject($name);
        PurgeBacklog::singleton()->clear();
        $original = Versioned::get_reading_mode();
        try {
            Versioned::set_stage(Versioned::DRAFT);
            PurgeBacklog::singleton()->settle(null, ['everything' => false, 'tags' => ['ec-page-1'], 'urls' => []]);

            Versioned::set_stage(Versioned::LIVE);
            $this->assertSame(['ec-page-1'], PurgeBacklog::singleton()->peek()['tags']);

            Versioned::set_reading_mode('');
            $this->assertSame(['ec-page-1'], PurgeBacklog::singleton()->peek()['tags']);
        } finally {
            PurgeBacklog::singleton()->clear();
            Versioned::set_reading_mode($original);
        }
    }
}
