<?php

namespace Dynamic\EdgeCache\Tests\Task;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\TestableStatusTask;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;

class EdgeCacheStatusTaskTest extends EdgeCacheTestCase
{
    private TestableStatusTask $task;

    private function runTask(array $vars = []): string
    {
        $this->task = new TestableStatusTask();
        ob_start();
        $this->task->run(new HTTPRequest('GET', '', $vars));

        return (string) ob_get_clean();
    }

    public function testItReportsTheStateWithoutCallingTheCdn(): void
    {
        $out = $this->runTask();

        $this->assertStringContainsString('live (edge caching and purging run here)', $out);
        $this->assertStringContainsString('Credentials set:  yes', $out);
        $this->assertStringContainsString('Settings switch:  ticked', $out);
        $this->assertStringContainsString('Verify:           not run', $out);
        $this->assertStringContainsString('Failed purges:    none waiting', $out);
        $this->assertSame([], $this->adapter->calls);
        $this->assertNull($this->task->exitCode);
    }

    public function testAnEnvironmentThatIsNotEnabledSaysSo(): void
    {
        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'dev');

        $out = $this->runTask();

        $this->assertStringContainsString('not enabled; runs in: live', $out);
    }

    public function testVerifyReportsSuccess(): void
    {
        $out = $this->runTask(['verify' => '1']);

        $this->assertStringContainsString('Verify:           ok: Accepted.', $out);
        $this->assertNull($this->task->exitCode);
    }

    public function testAFailedVerifyExitsNonZeroWithTheCdnsReason(): void
    {
        $this->adapter->returnFalse = true;

        $out = $this->runTask(['verify' => '1']);

        $this->assertStringContainsString('FAILED: Cloudflare answered 403', $out);
        $this->assertStringContainsString('did not verify', $this->task->stderr);
        $this->assertSame(1, $this->task->exitCode);
    }

    public function testTheSettingsSwitchBeingOffIsShown(): void
    {
        $this->setToggle(false);

        $this->assertStringContainsString('not ticked', $this->runTask());
    }

    public function testItReportsPurgesTheCdnRefused(): void
    {
        $this->adapter->returnFalse = true;
        PurgeQueue::singleton()->addTags(['ec-page-1', 'ec-page-2'])->addUrls('https://example.com/a.pdf')->flush();

        $out = $this->runTask();

        $this->assertStringContainsString('WAITING: 2 tag(s), 1 URL(s)', $out);
        $this->assertStringContainsString('1 attempt(s)', $out);
        $this->assertStringContainsString('edge-cache-purge retry=1', $out);
        $this->assertNull($this->task->exitCode);
    }
}
