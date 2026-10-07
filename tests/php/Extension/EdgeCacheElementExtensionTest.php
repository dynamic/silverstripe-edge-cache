<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\ElementContent;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\ElementWithVirtuals;
use Page;
use SilverStripe\Versioned\Versioned;

class EdgeCacheElementExtensionTest extends EdgeCacheTestCase
{
    protected static $extra_dataobjects = [ElementWithVirtuals::class];

    protected static $required_extensions = [Page::class => [ElementalPageExtension::class]];

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        ElementWithVirtuals::$virtualPages = [];
        ElementWithVirtuals::$lookupFails = false;
    }

    protected function tearDown(): void
    {
        ElementWithVirtuals::$virtualPages = [];
        ElementWithVirtuals::$lookupFails = false;
        parent::tearDown();
    }

    private function pageWithElement(string $class = ElementContent::class): array
    {
        $page = Page::create(['Title' => 'Holder', 'ShowInMenus' => false]);
        $page->write();
        $page->publishSingle();
        $element = $class::create(['Title' => 'Block', 'ParentID' => $page->ElementalAreaID]);
        $element->write();
        PurgeQueue::singleton()->reset();

        return [$page, $element];
    }

    public function testPublishingAnElementPurgesThePageItSitsOn(): void
    {
        [$page, $element] = $this->pageWithElement();

        $element->publishSingle();

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertSame(['ec-page-' . $page->ID], $pending['tags']);
    }

    public function testPublishingThroughCopyVersionToStageDoesToo(): void
    {
        // The content API publishes this way.
        [$page, $element] = $this->pageWithElement();

        $element->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        $this->assertSame(['ec-page-' . $page->ID], PurgeQueue::singleton()->pending()['tags']);
    }

    public function testSavingADraftOfAnElementPurgesNothing(): void
    {
        [, $element] = $this->pageWithElement();

        $element->Title = 'Edited, not published';
        $element->write();

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testUnpublishingAnElementPurgesItsPage(): void
    {
        [$page, $element] = $this->pageWithElement();
        $element->publishSingle();
        PurgeQueue::singleton()->reset();

        $element->doUnpublish();

        $this->assertSame(['ec-page-' . $page->ID], PurgeQueue::singleton()->pending()['tags']);
    }

    public function testPagesShowingAVirtualCopyArePurgedToo(): void
    {
        [$page, $element] = $this->pageWithElement(ElementWithVirtuals::class);
        $mirror = Page::create(['Title' => 'Mirror', 'ShowInMenus' => false]);
        $mirror->write();
        $other = Page::create(['Title' => 'Other mirror', 'ShowInMenus' => false]);
        $other->write();
        // Two copies on the same page count once.
        ElementWithVirtuals::$virtualPages = [$mirror, $mirror, $other];
        PurgeQueue::singleton()->reset();

        $element->publishSingle();

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertEqualsCanonicalizing(
            ['ec-page-' . $page->ID, 'ec-page-' . $mirror->ID, 'ec-page-' . $other->ID],
            $pending['tags']
        );
    }

    public function testAVirtualCopyWhosePageCannotBeFoundClearsTheSite(): void
    {
        [, $element] = $this->pageWithElement(ElementWithVirtuals::class);
        ElementWithVirtuals::$virtualPages = [null];
        PurgeQueue::singleton()->reset();

        $element->publishSingle();

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testAVirtualCopyInsideAnotherElementClearsTheSite(): void
    {
        // getPage() can return the owning element (an element inside a group), not a page.
        [, $element] = $this->pageWithElement(ElementWithVirtuals::class);
        ElementWithVirtuals::$virtualPages = [ElementContent::create(['Title' => 'Group'])];
        PurgeQueue::singleton()->reset();

        $element->publishSingle();

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testAFailingVirtualLookupDoesNotBreakThePublishAndClearsTheSite(): void
    {
        [, $element] = $this->pageWithElement(ElementWithVirtuals::class);
        ElementWithVirtuals::$lookupFails = true;
        PurgeQueue::singleton()->reset();

        $published = $element->publishSingle();

        $this->assertTrue($published, 'the editor\'s publish went through');
        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testAnElementWithoutVirtualCopiesNeedsNoVirtualSupport(): void
    {
        [$page, $element] = $this->pageWithElement(ElementContent::class);
        $this->assertFalse($element->hasMethod('getPublishedVirtualElements'));

        $element->publishSingle();

        $this->assertSame(['ec-page-' . $page->ID], PurgeQueue::singleton()->pending()['tags']);
    }

    public function testAnElementWithNoPageClearsTheSite(): void
    {
        $orphan = ElementContent::create(['Title' => 'Orphan']);
        $orphan->write();
        PurgeQueue::singleton()->reset();

        $orphan->publishSingle();

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }
}
