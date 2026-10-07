<?php

namespace Dynamic\EdgeCache\Tests\Task;

use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Task\EdgeCachePurgeTask;
use Dynamic\EdgeCache\Tests\Fixtures\RunsTasks;
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
}
