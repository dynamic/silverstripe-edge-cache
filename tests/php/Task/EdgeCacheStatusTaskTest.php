<?php

namespace Dynamic\EdgeCache\Tests\Task;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Task\EdgeCacheStatusTask;
use Dynamic\EdgeCache\Tests\Fixtures\RunsTasks;
use SilverStripe\Core\Environment;

class EdgeCacheStatusTaskTest extends EdgeCacheTestCase
{
    use RunsTasks;

    private function runTask(array $options = []): string
    {
        return $this->runBuildTask(new EdgeCacheStatusTask(), $options);
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
        $this->assertSame(0, $this->exitCode);
    }

    public function testAnEnvironmentThatIsNotEnabledSaysSo(): void
    {
        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'dev');

        $out = $this->runTask();

        $this->assertStringContainsString('not enabled; runs in: live', $out);
    }

    public function testVerifyReportsSuccess(): void
    {
        $out = $this->runTask(['verify' => true]);

        $this->assertStringContainsString('Verify:           ok: Accepted.', $out);
        $this->assertSame(0, $this->exitCode);
    }

    public function testAFailedVerifyExitsNonZeroWithTheCdnsReason(): void
    {
        $this->adapter->returnFalse = true;

        $out = $this->runTask(['verify' => true]);

        $this->assertStringContainsString('FAILED: Cloudflare answered 403', $out);
        $this->assertStringContainsString('did not verify', $out);
        $this->assertSame(1, $this->exitCode);
    }

    public function testTheSettingsSwitchBeingOffIsShown(): void
    {
        $this->setToggle(false);

        $this->assertStringContainsString('not ticked', $this->runTask());
    }

    public function testItIsRegisteredUnderTheNameTheDocsUse(): void
    {
        $this->assertSame('tasks:edge-cache-status', EdgeCacheStatusTask::getName());
    }

    public function testItReportsPurgesTheCdnRefused(): void
    {
        $this->adapter->returnFalse = true;
        PurgeQueue::singleton()->addTags(['ec-page-1', 'ec-page-2'])->addUrls('https://example.com/a.pdf')->flush();

        $out = $this->runTask();

        $this->assertStringContainsString('WAITING: 2 tag(s), 1 URL(s)', $out);
        $this->assertStringContainsString('1 attempt(s)', $out);
        $this->assertStringContainsString('sake tasks:edge-cache-purge --retry', $out);
        $this->assertSame(0, $this->exitCode);
    }
}
