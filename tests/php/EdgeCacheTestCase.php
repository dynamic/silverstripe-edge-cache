<?php

namespace Dynamic\EdgeCache\Tests;

use Dynamic\EdgeCache\Adapter\EdgeCacheAdapter;
use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\Fixtures\RecordingAdapter;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Puts the module in the state a live site is in: live environment, a configured adapter, the
 * Settings switch on, reading the Live stage. Tests turn one gate off at a time.
 */
abstract class EdgeCacheTestCase extends SapphireTest
{
    protected $usesDatabase = true;

    protected RecordingAdapter $adapter;

    private ?string $originalEnvironment = null;

    private ?string $originalStage = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironment = Environment::getEnv('SS_ENVIRONMENT_TYPE') ?: null;
        $this->originalStage = Versioned::get_stage();

        $this->adapter = new RecordingAdapter();
        Injector::inst()->registerService($this->adapter, EdgeCacheAdapter::class);
        // Purges the CDN refused wait here; each test starts with none.
        Injector::inst()->registerService(
            new Psr16Cache(new ArrayAdapter()),
            CacheInterface::class . '.EdgeCachePurgeBacklog'
        );

        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'live');
        Versioned::set_stage(Versioned::LIVE);
        $this->setToggle(true);

        EdgeCache::singleton()->reset();
        PurgeQueue::singleton()->reset();
    }

    protected function tearDown(): void
    {
        PurgeQueue::singleton()->reset();
        EdgeCache::singleton()->reset();
        Environment::setEnv('SS_ENVIRONMENT_TYPE', $this->originalEnvironment ?? '');
        Versioned::set_stage($this->originalStage ?? Versioned::LIVE);

        parent::tearDown();
    }

    protected function setToggle(bool $on): void
    {
        $config = SiteConfig::current_site_config();
        $config->EdgeCacheEnabled = $on;
        $config->write();
        // Writing Settings queues a purge; the tests care about what they do next.
        PurgeQueue::singleton()->reset();
    }
}
