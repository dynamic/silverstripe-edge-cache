<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\ChildrenHolderPage;
use Page;
use SilverStripe\Versioned\Versioned;

/**
 * `$Children` queries SiteTree, a class the module ignores, so a page that lists a holder's
 * children is tagged `ec-children-<holder id>` for classes that opt in.
 */
class EdgeCacheChildrenTagTest extends EdgeCacheTestCase
{
    protected static $extra_dataobjects = [ChildrenHolderPage::class];

    private function published(string $class, string $title, int $parentId = 0): Page
    {
        Versioned::set_stage(Versioned::DRAFT);
        $page = $class::create(['Title' => $title, 'ParentID' => $parentId, 'ShowInMenus' => false]);
        $page->write();
        $page->publishSingle();
        Versioned::set_stage(Versioned::LIVE);
        PurgeQueue::singleton()->reset();

        return $page;
    }

    private function tags(callable $render): array
    {
        $edge = EdgeCache::singleton();
        $edge->reset();
        $edge->startCollecting();
        $render();
        $edge->stopCollecting();

        return $edge->getTags();
    }

    public function testListingAnOptedInHoldersChildrenTagsThePage(): void
    {
        $holder = $this->published(ChildrenHolderPage::class, 'News');
        $this->published(Page::class, 'Story', $holder->ID);

        $tags = $this->tags(fn () => ChildrenHolderPage::get()->byID($holder->ID)->Children()->toArray());

        $this->assertContains('ec-children-' . $holder->ID, $tags);
    }

    public function testAllChildrenCountsToo(): void
    {
        $holder = $this->published(ChildrenHolderPage::class, 'News');

        $tags = $this->tags(fn () => ChildrenHolderPage::get()->byID($holder->ID)->AllChildren()->toArray());

        $this->assertContains('ec-children-' . $holder->ID, $tags);
    }

    public function testAPageThatDidNotOptInIsNotTagged(): void
    {
        $plain = $this->published(Page::class, 'About');
        $this->published(Page::class, 'Team', $plain->ID);

        $tags = $this->tags(fn () => Page::get()->byID($plain->ID)->Children()->toArray());

        $this->assertSame(['ec-site'], $tags, 'a menu reads children of every page; those must not be tags');
    }

    public function testNothingIsRecordedWhenNotCollecting(): void
    {
        $holder = $this->published(ChildrenHolderPage::class, 'News');
        EdgeCache::singleton()->reset();

        ChildrenHolderPage::get()->byID($holder->ID)->Children()->toArray();

        $this->assertSame(['ec-site'], EdgeCache::singleton()->getTags());
    }

    public function testPublishingAChildPurgesPagesThatListTheHoldersChildren(): void
    {
        $holder = $this->published(ChildrenHolderPage::class, 'News');
        $story = $this->published(Page::class, 'Story', $holder->ID);

        Versioned::set_stage(Versioned::DRAFT);
        $story = Page::get()->byID($story->ID);
        $story->Content = 'Edited';
        $story->write();
        $story->publishSingle();

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertContains('ec-children-' . $holder->ID, $pending['tags']);
        $this->assertContains('ec-page-' . $story->ID, $pending['tags']);
    }

    public function testATopLevelPageHasNoChildrenTagToPurge(): void
    {
        $top = $this->published(Page::class, 'Top');

        Versioned::set_stage(Versioned::DRAFT);
        $top = Page::get()->byID($top->ID);
        $top->Content = 'Edited';
        $top->write();
        $top->publishSingle();

        $tags = PurgeQueue::singleton()->pending()['tags'];
        $this->assertSame([], array_filter($tags, fn ($t) => str_starts_with($t, 'ec-children-')));
    }

    public function testPublishingAChildThroughCopyVersionToStagePurgesTheChildrenTag(): void
    {
        // The content API publishes this way.
        $holder = $this->published(ChildrenHolderPage::class, 'News');
        $story = $this->published(Page::class, 'Story', $holder->ID);

        Versioned::set_stage(Versioned::DRAFT);
        $story = Page::get()->byID($story->ID);
        $story->Content = 'Via API';
        $story->write();
        PurgeQueue::singleton()->reset();

        $story->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertContains('ec-children-' . $holder->ID, $pending['tags']);
    }

    public function testAnUnsavedHolderIsNotTagged(): void
    {
        $tags = $this->tags(fn () => ChildrenHolderPage::create()->Children()->toArray());

        $this->assertSame(['ec-site'], $tags);
    }
}
