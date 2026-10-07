<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Task\EdgeCacheCloudflareRulesTask;
use SilverStripe\Dev\TestOnly;

class TestableRulesTask extends EdgeCacheCloudflareRulesTask implements TestOnly
{
    use CapturesTaskFailures;
}
