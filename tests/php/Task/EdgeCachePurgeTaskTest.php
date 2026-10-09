<?php

namespace Dynamic\EdgeCache\Tests\Task;

use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Purge\PurgeBacklog;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Task\EdgeCachePurgeTask;
use Dynamic\EdgeCache\Tests\Fixtures\RunsTasks;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\PolyExecution\HttpRequestInput;
use SilverStripe\Core\Environment;

class EdgeCachePurgeTaskTest extends EdgeCacheTestCase
{
    use RunsTasks;

    private function runTask(array $options): string
    {
        return $this->runBuildTask(new EdgeCachePurgeTask(), $options);
    }

    public function testAnAcceptedPurgeIsReported(): void
    {
        $out = $this->runTask(['tag' => 'ec-page-1, ec-class-BlogPost']);

        $this->assertStringContainsString('Purge accepted: tags ec-page-1, ec-class-BlogPost', $out);
        $this->assertSame(0, $this->exitCode);
        $this->assertSame([['tags', ['ec-page-1', 'ec-class-BlogPost']]], $this->adapter->calls);
    }

    public function testPurgeUrlTakesAbsoluteUrls(): void
    {
        $this->runTask(['purge-url' => 'https://example.com/a.pdf, https://example.com/b.pdf']);

        $this->assertSame(
            [['urls', ['https://example.com/a.pdf', 'https://example.com/b.pdf']]],
            $this->adapter->calls
        );
    }

    public function testEverythingAndUrlsAreBothSent(): void
    {
        $this->runTask(['everything' => true, 'purge-url' => 'https://example.com/a.pdf']);

        $this->assertSame([['everything', []], ['urls', ['https://example.com/a.pdf']]], $this->adapter->calls);
    }

    public function testARefusedPurgeIsNotReportedAsSent(): void
    {
        $this->adapter->returnFalse = true;

        $out = $this->runTask(['everything' => true]);

        $this->assertStringNotContainsString('Purge accepted', $out);
        $this->assertStringContainsString('did not accept the purge', $out);
        $this->assertStringContainsString('edge-cache-status --verify', $out);
        $this->assertSame(1, $this->exitCode);
    }

    public function testAThrowingAdapterIsAFailureToo(): void
    {
        $this->adapter->fail = true;

        $this->runTask(['purge-url' => 'https://example.com/a.pdf']);

        $this->assertSame(1, $this->exitCode);
    }

    public function testOutsideAnEnabledEnvironmentNothingIsPurged(): void
    {
        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'dev');

        $out = $this->runTask(['everything' => true]);

        $this->assertStringContainsString('not enabled for this environment', $out);
        $this->assertSame([], $this->adapter->calls);
    }

    public function testWithNothingToPurgeItFailsSoAScriptCannotReadItAsSuccess(): void
    {
        $out = $this->runTask([]);

        $this->assertStringContainsString('Nothing to purge', $out);
        $this->assertStringContainsString('--purge-url=', $out);
        $this->assertSame(1, $this->exitCode);
        $this->assertSame([], $this->adapter->calls);
    }

    public function testTagsAndUrlsAreTrimmedAndDeduplicated(): void
    {
        $this->runTask(['tag' => 'a, b,,a,', 'purge-url' => 'https://example.com/a.pdf,https://example.com/a.pdf']);

        $this->assertSame(
            [['tags', ['a', 'b']], ['urls', ['https://example.com/a.pdf']]],
            $this->adapter->calls
        );
    }

    public function testItIsRegisteredUnderTheNameTheDocsUse(): void
    {
        $this->assertSame('tasks:edge-cache-purge', EdgeCachePurgeTask::getName());
    }

    public function testOverHttpTheQueryVariablesMapToTheOptions(): void
    {
        $task = new EdgeCachePurgeTask();
        $request = new HTTPRequest('GET', '', ['tag' => 'a,b', 'purge-url' => 'https://example.com/a.pdf']);

        $this->executeTask($task, HttpRequestInput::create($request, $task->getOptions()));

        $this->assertSame(0, $this->exitCode);
        $this->assertSame([['tags', ['a', 'b']], ['urls', ['https://example.com/a.pdf']]], $this->adapter->calls);
    }

    public function testOverHttpAFlagIsReadFromTheQueryString(): void
    {
        $task = new EdgeCachePurgeTask();
        $options = $task->getOptions();

        $this->executeTask($task, HttpRequestInput::create(new HTTPRequest('GET', '', ['everything' => '1']), $options));
        $this->assertSame([['everything', []]], $this->adapter->calls);

        $this->adapter->calls = [];
        $this->executeTask($task, HttpRequestInput::create(new HTTPRequest('GET', '', ['everything' => '0']), $options));
        $this->assertSame(1, $this->exitCode, 'everything=0 is off, so there is nothing to purge');
        $this->assertSame([], $this->adapter->calls);
    }

    public function testRetryWithNothingWaitingSaysSoAndSucceeds(): void
    {
        $out = $this->runTask(['retry' => true]);

        $this->assertStringContainsString('No failed purges are waiting', $out);
        $this->assertSame(0, $this->exitCode);
        $this->assertSame([], $this->adapter->calls);
    }

    public function testRetrySendsTheFailedPurgesAndEmptiesTheBacklog(): void
    {
        $this->adapter->returnFalse = true;
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        $this->adapter->returnFalse = false;
        $this->adapter->calls = [];

        $out = $this->runTask(['retry' => true]);

        $this->assertStringContainsString('Purge accepted: waiting from an earlier failure: 1 tag(s), 0 URL(s)', $out);
        $this->assertSame(0, $this->exitCode);
        $this->assertSame([['tags', ['ec-page-1']]], $this->adapter->calls);
        $this->assertNull(PurgeBacklog::singleton()->summary());
    }

    public function testRetryThatFailsAgainExitsNonZeroAndKeepsTheBacklog(): void
    {
        $this->adapter->returnFalse = true;
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();

        $out = $this->runTask(['retry' => true]);

        $this->assertStringContainsString('did not accept the purge', $out);
        $this->assertSame(1, $this->exitCode);
        $this->assertSame(['ec-page-1'], PurgeBacklog::singleton()->summary()['tags']);
    }

    public function testAnotherPurgeCarriesTheWaitingOnesToo(): void
    {
        $this->adapter->returnFalse = true;
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        $this->adapter->returnFalse = false;
        $this->adapter->calls = [];

        $this->runTask(['tag' => 'ec-page-2']);

        $this->assertSame([['tags', ['ec-page-2', 'ec-page-1']]], $this->adapter->calls);
    }
}
