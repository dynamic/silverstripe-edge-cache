<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use SilverStripe\Core\Environment;
use SilverStripe\SiteConfig\SiteConfig;

class EdgeCacheSiteConfigExtensionTest extends EdgeCacheTestCase
{
    private function description(): string
    {
        $field = SiteConfig::current_site_config()->getCMSFields()->dataFieldByName('EdgeCacheEnabled');

        return (string) $field->getDescription();
    }

    public function testTheSwitchHasNoWarningWhenEdgeCachingCanRun(): void
    {
        $this->assertStringNotContainsString('NOT ACTIVE', $this->description());
    }

    public function testTickingTheSwitchInAnEnvironmentThatIsNotEnabledIsFlagged(): void
    {
        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'dev');

        $description = $this->description();

        $this->assertStringContainsString('NOT ACTIVE', $description);
        $this->assertStringContainsString('live', $description);
    }

    public function testMissingCredentialsAreFlagged(): void
    {
        $this->adapter->configured = false;

        $this->assertStringContainsString('credentials are not set', $this->description());
    }
}
