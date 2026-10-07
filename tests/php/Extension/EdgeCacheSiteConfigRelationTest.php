<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\JoinOwner;
use Dynamic\EdgeCache\Tests\Fixtures\JoinTarget;
use Dynamic\EdgeCache\Tests\Fixtures\ListedJoinOwner;
use Dynamic\EdgeCache\Tests\Fixtures\PlainJoinOwner;
use Dynamic\EdgeCache\Tests\Fixtures\SiteConfigLinksExtension;
use ReflectionMethod;
use SilverStripe\SiteConfig\SiteConfig;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

/**
 * A list on Settings (utility links) writes only a join table; changing it must clear the site.
 */
class EdgeCacheSiteConfigRelationTest extends EdgeCacheTestCase
{
    protected static $extra_dataobjects = [
        JoinTarget::class,
        JoinOwner::class,
        ListedJoinOwner::class,
        PlainJoinOwner::class,
    ];

    protected static $required_extensions = [
        SiteConfig::class => [SiteConfigLinksExtension::class],
    ];

    private function target(string $title): JoinTarget
    {
        $target = JoinTarget::create(['Title' => $title]);
        $target->write();
        PurgeQueue::singleton()->reset();

        return $target;
    }

    public function testAddingAndRemovingASettingsLinkClearsTheSite(): void
    {
        $config = SiteConfig::current_site_config();
        $target = $this->target('Contact');

        $config->LinkTargets()->add($target);
        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);

        PurgeQueue::singleton()->reset();
        $config->LinkTargets()->remove($target);
        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testClearingTheListClearsTheSite(): void
    {
        $config = SiteConfig::current_site_config();
        $config->LinkTargets()->add($this->target('Contact'));
        PurgeQueue::singleton()->reset();

        $config->LinkTargets()->removeAll();

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testReorderingASettingsListClearsTheSite(): void
    {
        $config = SiteConfig::current_site_config();
        $first = $this->target('One');
        $second = $this->target('Two');
        $config->LinkTargets()->add($first, ['Sort' => 1]);
        $config->LinkTargets()->add($second, ['Sort' => 2]);
        PurgeQueue::singleton()->reset();

        $component = new GridFieldOrderableRows('Sort');
        $reorder = new ReflectionMethod($component, 'reorderItems');
        $reorder->setAccessible(true);
        $reorder->invoke($component, $config->LinkTargets()->sort('Sort'), [], [1 => $second->ID, 2 => $first->ID]);

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }
}
