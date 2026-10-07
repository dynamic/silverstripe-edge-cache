<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Task\EdgeCacheStatusTask;
use SilverStripe\Dev\TestOnly;

class TestableStatusTask extends EdgeCacheStatusTask implements TestOnly
{
    use CapturesTaskFailures;
}
