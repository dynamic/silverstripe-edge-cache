<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Task\EdgeCacheCloudflareRulesTask;
use SilverStripe\Dev\TestOnly;

/**
 * The rules task with its stderr and exit captured instead of ending the test run.
 */
class TestableRulesTask extends EdgeCacheCloudflareRulesTask implements TestOnly
{
    public string $stderr = '';

    public ?int $exitCode = null;

    protected function writeError(string $text): void
    {
        $this->stderr .= $text;
    }

    protected function terminate(int $code): void
    {
        $this->exitCode = $code;
    }
}
