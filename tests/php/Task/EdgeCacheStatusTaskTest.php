<?php

namespace Dynamic\EdgeCache\Tests\Task;

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
}
