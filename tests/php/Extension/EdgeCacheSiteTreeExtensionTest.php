<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Page;
use SilverStripe\Versioned\Versioned;

class EdgeCacheSiteTreeExtensionTest extends EdgeCacheTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Editors write and publish from the draft stage; the base class leaves the stage on Live.
        Versioned::set_stage(Versioned::DRAFT);
    }

    private function page(string $title, ?Page $parent = null): Page
    {
        $page = Page::create(['Title' => $title, 'ShowInMenus' => false]);
        if ($parent) {
            $page->ParentID = $parent->ID;
        }
        $page->write();
        $page->publishSingle();

        return $page;
    }

    public function testPublishingAPagePurgesItAndItsAncestors(): void
    {
        $holder = $this->page('Holder');
        $child = $this->page('Child', $holder);
        PurgeQueue::singleton()->reset();

        $child->Content = '<p>changed</p>';
        $child->write();
        $child->publishSingle();

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertContains('ec-page-' . $child->ID, $pending['tags']);
        $this->assertContains('ec-page-' . $holder->ID, $pending['tags']);
        $this->assertContains('ec-class-Page', $pending['tags']);
        $this->assertContains('ec-class-SiteTree', $pending['tags']);
    }

    public function testChangingTheTitleClearsTheWholeSite(): void
    {
        $page = $this->page('Before');
        PurgeQueue::singleton()->reset();

        $page->Title = 'After';
        $page->write();
        $page->publishSingle();

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testCopyVersionToStagePathDetectsStructuralChanges(): void
    {
        // The content API publishes through copyVersionToStage rather than publishSingle.
        $page = $this->page('Before');
        PurgeQueue::singleton()->reset();

        $page->MenuTitle = 'Nav label';
        $page->write();
        $page->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testCopyVersionToStageForAContentChangeIsTargeted(): void
    {
        $page = $this->page('Same title');
        PurgeQueue::singleton()->reset();

        $page->Content = '<p>new</p>';
        $page->write();
        $page->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertContains('ec-page-' . $page->ID, $pending['tags']);
    }

    public function testUnpublishingClearsTheWholeSite(): void
    {
        $page = $this->page('Going');
        PurgeQueue::singleton()->reset();

        $page->doUnpublish();

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testASavedDraftPurgesNothing(): void
    {
        $page = $this->page('Draft edits');
        PurgeQueue::singleton()->reset();

        $page->Content = '<p>not published</p>';
        $page->write();

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testSavingSettingsClearsTheWholeSite(): void
    {
        $this->setToggle(true);
        $config = \SilverStripe\SiteConfig\SiteConfig::current_site_config();
        $config->Tagline = 'New tagline';
        $config->write();

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }
}
