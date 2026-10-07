<?php

namespace Dynamic\EdgeCache\Tests\Purge;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;

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

    public function testFlushSaysWhetherTheCdnAcceptedThePurge(): void
    {
        $queue = PurgeQueue::singleton();
        $this->assertTrue($queue->flush(), 'nothing to send is not a failure');

        $queue->addTags('ec-page-1');
        $this->assertTrue($queue->flush());

        $this->adapter->returnFalse = true;
        $queue->addTags('ec-page-2');
        $this->assertFalse($queue->flush());

        $this->adapter->returnFalse = false;
        $this->adapter->fail = true;
        $queue->addEverything();
        $this->assertFalse($queue->flush(), 'an exception is a failure too');
    }

    public function testFlushOutsideAnEnabledEnvironmentIsNotAFailure(): void
    {
        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'dev');
        $this->adapter->returnFalse = true;
        PurgeQueue::singleton()->addTags('ec-page-1');

        $this->assertTrue(PurgeQueue::singleton()->flush());
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

    public function testAFailingCdnDoesNotThrowAndTheLossIsLoggedWithWhatWasLost(): void
    {
        $handler = new TestHandler();
        Injector::inst()->registerService(new Logger('test', [$handler]), LoggerInterface::class);
        $this->adapter->fail = true;
        PurgeQueue::singleton()->addTags('ec-page-1');

        PurgeQueue::singleton()->flush();

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
        $this->assertTrue($handler->hasErrorThatContains('Edge cache purge did not complete'));
        $record = $handler->getRecords()[0];
        $this->assertSame(['ec-page-1'], $record['context']['tags']);
        $this->assertStringContainsString('CDN down', $record['context']['exception']);
    }

    public function testAnAdapterThatReportsFailureIsLoggedToo(): void
    {
        $handler = new TestHandler();
        Injector::inst()->registerService(new Logger('test', [$handler]), LoggerInterface::class);
        $this->adapter->returnFalse = true;
        PurgeQueue::singleton()->addEverything();

        PurgeQueue::singleton()->flush();

        $this->assertTrue($handler->hasErrorThatContains('Edge cache purge did not complete'));
        $this->assertSame(['everything'], $handler->getRecords()[0]['context']['failed']);
    }

    public function testAThrowingLoggerDoesNotBreakAPublish(): void
    {
        Injector::inst()->registerService(
            new class extends \Psr\Log\AbstractLogger {
                public function log($level, $message, array $context = []): void
                {
                    throw new \RuntimeException('log stream unwritable');
                }
            },
            LoggerInterface::class
        );
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
