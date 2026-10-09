<?php

namespace Dynamic\EdgeCache\Tests\Task;

use Dynamic\EdgeCache\Purge\PurgeBacklog;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\TestablePurgeTask;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;

class EdgeCachePurgeTaskTest extends EdgeCacheTestCase
{
    private TestablePurgeTask $task;

    private function runTask(array $vars): string
    {
        $this->task = new TestablePurgeTask();
        ob_start();
        $this->task->run(new HTTPRequest('GET', '', $vars));

        return (string) ob_get_clean();
    }

    public function testAnAcceptedPurgeIsReported(): void
    {
        $out = $this->runTask(['tag' => 'ec-page-1, ec-class-BlogPost']);

        $this->assertStringContainsString('Purge accepted: tags ec-page-1, ec-class-BlogPost', $out);
        $this->assertNull($this->task->exitCode);
        $this->assertSame([['tags', ['ec-page-1', 'ec-class-BlogPost']]], $this->adapter->calls);
    }

    public function testPurgeUrlTakesAbsoluteUrls(): void
    {
        $this->runTask(['purge_url' => 'https://example.com/a.pdf, https://example.com/b.pdf']);

        $this->assertSame(
            [['urls', ['https://example.com/a.pdf', 'https://example.com/b.pdf']]],
            $this->adapter->calls
        );
    }

    public function testSakesOwnUrlVariableIsNotMistakenForAUrlToPurge(): void
    {
        // sake sets `url` to the task's path; it once went to the CDN as a URL to purge.
        $out = $this->runTask(['everything' => '1', 'url' => 'dev/tasks/edge-cache-purge']);

        $this->assertSame([['everything', []]], $this->adapter->calls);
        $this->assertStringNotContainsString('urls', $out);
    }

    public function testAnAbsoluteUrlGivenAsUrlStillWorksOverHttp(): void
    {
        $this->runTask(['url' => 'https://example.com/a.pdf']);

        $this->assertSame([['urls', ['https://example.com/a.pdf']]], $this->adapter->calls);
    }

    public function testEverythingAndUrlsAreBothSent(): void
    {
        $this->runTask(['everything' => '1', 'purge_url' => 'https://example.com/a.pdf']);

        $this->assertSame([['everything', []], ['urls', ['https://example.com/a.pdf']]], $this->adapter->calls);
    }

    public function testARefusedPurgeIsNotReportedAsSent(): void
    {
        $this->adapter->returnFalse = true;

        $out = $this->runTask(['everything' => '1']);

        $this->assertSame('', $out, 'no success line');
        $this->assertStringContainsString('did not accept the purge', $this->task->stderr);
        $this->assertStringContainsString('edge-cache-status verify=1', $this->task->stderr);
        $this->assertSame(1, $this->task->exitCode);
    }

    public function testAThrowingAdapterIsAFailureToo(): void
    {
        $this->adapter->fail = true;

        $this->runTask(['url' => 'https://example.com/a.pdf']);

        $this->assertSame(1, $this->task->exitCode);
    }

    public function testOutsideAnEnabledEnvironmentNothingIsPurged(): void
    {
        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'dev');

        $out = $this->runTask(['everything' => '1']);

        $this->assertStringContainsString('not enabled for this environment', $out);
        $this->assertSame([], $this->adapter->calls);
    }

    public function testWithNothingToPurgeItFailsSoAScriptCannotReadItAsSuccess(): void
    {
        $out = $this->runTask([]);

        $this->assertSame('', $out);
        $this->assertStringContainsString('Nothing to purge', $this->task->stderr);
        $this->assertStringContainsString('purge_url=', $this->task->stderr);
        $this->assertSame(1, $this->task->exitCode);
        $this->assertSame([], $this->adapter->calls);
    }

    public function testTheOldUrlFormUnderSakeFailsInsteadOfDoingNothing(): void
    {
        // sake replaces `url` with the task path, so a script still passing url=https://... sends no URL.
        $this->runTask(['url' => 'dev/tasks/edge-cache-purge']);

        $this->assertSame(1, $this->task->exitCode);
        $this->assertSame([], $this->adapter->calls);
    }

    public function testRetryWithNothingWaitingSaysSoAndSucceeds(): void
    {
        $out = $this->runTask(['retry' => '1']);

        $this->assertStringContainsString('No failed purges are waiting', $out);
        $this->assertNull($this->task->exitCode);
        $this->assertSame([], $this->adapter->calls);
    }

    public function testRetrySendsTheFailedPurgesAndEmptiesTheBacklog(): void
    {
        $this->adapter->returnFalse = true;
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();
        $this->adapter->returnFalse = false;
        $this->adapter->calls = [];

        $out = $this->runTask(['retry' => '1']);

        $this->assertStringContainsString('Purge accepted: waiting from an earlier failure: 1 tag(s), 0 URL(s)', $out);
        $this->assertNull($this->task->exitCode);
        $this->assertSame([['tags', ['ec-page-1']]], $this->adapter->calls);
        $this->assertNull(PurgeBacklog::singleton()->peek());
    }

    public function testRetryThatFailsAgainExitsNonZeroAndKeepsTheBacklog(): void
    {
        $this->adapter->returnFalse = true;
        PurgeQueue::singleton()->addTags('ec-page-1')->flush();

        $this->runTask(['retry' => '1']);

        $this->assertStringContainsString('did not accept the purge', $this->task->stderr);
        $this->assertSame(1, $this->task->exitCode);
        $this->assertSame(['ec-page-1'], PurgeBacklog::singleton()->peek()['tags']);
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
