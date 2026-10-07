<?php

namespace Dynamic\EdgeCache\Tests\Task;

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

    public function testWithNothingToPurgeItSaysSo(): void
    {
        $out = $this->runTask([]);

        $this->assertStringContainsString('Nothing to purge', $out);
        $this->assertNull($this->task->exitCode);
    }
}
