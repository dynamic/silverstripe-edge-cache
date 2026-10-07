<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use Dynamic\EdgeCache\Task\EdgeCachePurgeTask;
use SilverStripe\Dev\TestOnly;

class TestablePurgeTask extends EdgeCachePurgeTask implements TestOnly
{
    use CapturesTaskFailures;
}
