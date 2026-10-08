<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use Dynamic\EdgeCache\Extension\EdgeCacheOrderableRowsExtension;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\JoinTarget;
use Dynamic\EdgeCache\Tests\Fixtures\ListsPagesAndElements;
use Dynamic\EdgeCache\Tests\Fixtures\OwnedElementJoin;
use Dynamic\EdgeCache\Tests\Fixtures\OwnedRelationElement;
use Dynamic\EdgeCache\Tests\Fixtures\OwnedRelationPage;
use Dynamic\EdgeCache\Tests\Fixtures\PlainJoinOwner;
use Page;
use ReflectionMethod;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Versioned\Versioned;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

/**
 * A page or an element that holds a many_many list purges the pages that show it when the list
 * changes. The join table is not versioned, so the change is live without a publish, and neither
 * class has to opt in.
 */
class EdgeCacheOwnedRelationTest extends EdgeCacheTestCase
{
    protected static $extra_dataobjects = [
        JoinTarget::class,
        PlainJoinOwner::class,
        OwnedRelationPage::class,
        OwnedRelationElement::class,
        OwnedElementJoin::class,
        ListsPagesAndElements::class,
    ];

    protected static $required_extensions = [Page::class => [ElementalPageExtension::class]];

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    private function page(string $class = OwnedRelationPage::class): Page
    {
        $page = $class::create(['Title' => 'Holder', 'ShowInMenus' => false]);
        $page->write();
        $page->publishSingle();
        PurgeQueue::singleton()->reset();

        return $page;
    }

    private function elementOn(Page $page): OwnedRelationElement
    {
        $element = OwnedRelationElement::create(['Title' => 'Block', 'ParentID' => $page->ElementalAreaID]);
        $element->write();
        $element->publishSingle();
        PurgeQueue::singleton()->reset();

        return $element;
    }

    private function target(string $title = 'Target'): JoinTarget
    {
        $target = JoinTarget::create(['Title' => $title]);
        $target->write();
        PurgeQueue::singleton()->reset();

        return $target;
    }

    private function assertPurgedPages(array $pages): void
    {
        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertEqualsCanonicalizing(
            array_map(fn (Page $page) => 'ec-page-' . $page->ID, $pages),
            $pending['tags']
        );
    }

    public function testAddingToAPagesListPurgesThatPage(): void
    {
        $page = $this->page();

        $page->Targets()->add($this->target());

        $this->assertPurgedPages([$page]);
    }

    public function testRemovingFromAPagesListPurgesThatPage(): void
    {
        $page = $this->page();
        $target = $this->target();
        $page->Targets()->add($target);

        PurgeQueue::singleton()->reset();
        $page->Targets()->remove($target);
        $this->assertPurgedPages([$page]);

        $page->Targets()->add($target);
        PurgeQueue::singleton()->reset();
        $page->Targets()->removeByID($target->ID);
        $this->assertPurgedPages([$page]);
    }

    public function testClearingAndSettingAPagesListPurgesThatPage(): void
    {
        $page = $this->page();
        $first = $this->target('One');
        $second = $this->target('Two');

        $page->Targets()->setByIDList([$first->ID, $second->ID]);
        $this->assertPurgedPages([$page]);

        PurgeQueue::singleton()->reset();
        $page->Targets()->removeAll();
        $this->assertPurgedPages([$page]);
    }

    public function testAPageWithoutAChangedListPurgesNothing(): void
    {
        $page = $this->page();
        $page->Title = 'Edited, not published';
        $page->write();

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testSavingAPageWithLinksAndImagesInItsContentPurgesNothing(): void
    {
        // Silverstripe rewrites the page's link and file tracking lists on every save.
        $linked = $this->page();
        $page = $this->page();
        $page->Content = '<p><a href="[sitetree_link,id=' . $linked->ID . ']">Linked</a></p>';
        $page->write();

        $this->assertSame([$linked->ID], $page->LinkTracking()->column('ID'), 'the tracking list was written');
        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testAddingToAnElementsListPurgesThePageItSitsOn(): void
    {
        $page = $this->page();
        $element = $this->elementOn($page);

        $element->Targets()->add($this->target());

        $this->assertPurgedPages([$page]);
    }

    public function testRemovingFromAnElementsListPurgesItsPage(): void
    {
        $page = $this->page();
        $element = $this->elementOn($page);
        $target = $this->target();
        $element->Targets()->add($target);

        PurgeQueue::singleton()->reset();
        $element->Targets()->remove($target);
        $this->assertPurgedPages([$page]);

        $element->Targets()->add($target);
        PurgeQueue::singleton()->reset();
        $element->Targets()->removeAll();
        $this->assertPurgedPages([$page]);
    }

    public function testAnElementsThroughListPurgesItsPage(): void
    {
        $page = $this->page();
        $element = $this->elementOn($page);
        $target = $this->target();

        $element->ThroughTargets()->add($target);
        $this->assertPurgedPages([$page]);

        PurgeQueue::singleton()->reset();
        $element->ThroughTargets()->remove($target);
        $this->assertPurgedPages([$page]);
    }

    public function testAnElementWithNoPageClearsTheSiteWhenItsListChanges(): void
    {
        $orphan = OwnedRelationElement::create(['Title' => 'Orphan']);
        $orphan->write();
        PurgeQueue::singleton()->reset();

        $orphan->Targets()->add($this->target());

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testReorderingAnElementsListPurgesItsPage(): void
    {
        $page = $this->page();
        $element = $this->elementOn($page);
        $first = $this->target('One');
        $second = $this->target('Two');
        $element->ThroughTargets()->add($first, ['Sort' => 1]);
        $element->ThroughTargets()->add($second, ['Sort' => 2]);
        PurgeQueue::singleton()->reset();

        $list = $element->ThroughTargets()->sort('Sort');
        $component = new GridFieldOrderableRows('Sort');
        $reorder = new ReflectionMethod($component, 'reorderItems');
        $reorder->setAccessible(true);
        $reorder->invoke($component, $list, [], [1 => $second->ID, 2 => $first->ID]);

        $this->assertPurgedPages([$page]);
    }

    public function testEditingFromThePageSideOfAListPurgesThosePages(): void
    {
        $first = $this->page();
        $second = $this->page();
        $lister = ListsPagesAndElements::create(['Title' => 'Sidebar']);
        $lister->write();
        PurgeQueue::singleton()->reset();

        $lister->Pages()->add($first);
        $this->assertPurgedPages([$first]);

        PurgeQueue::singleton()->reset();
        $lister->Pages()->add($second);
        $lister->Pages()->remove($first);
        $this->assertPurgedPages([$first, $second]);

        PurgeQueue::singleton()->reset();
        $lister->Pages()->removeAll();
        $this->assertPurgedPages([$second]);
    }

    public function testEditingFromTheElementSideOfAListPurgesThoseElementsPages(): void
    {
        $page = $this->page();
        $element = $this->elementOn($page);
        $lister = ListsPagesAndElements::create(['Title' => 'Sidebar']);
        $lister->write();
        PurgeQueue::singleton()->reset();

        $lister->Elements()->add($element);
        $this->assertPurgedPages([$page]);

        PurgeQueue::singleton()->reset();
        $lister->Elements()->remove($element);
        $this->assertPurgedPages([$page]);
    }

    public function testReorderingAListOfPagesPurgesThosePages(): void
    {
        $first = $this->page();
        $second = $this->page();
        $lister = ListsPagesAndElements::create(['Title' => 'Sidebar']);
        $lister->write();
        $lister->Pages()->add($first);
        $lister->Pages()->add($second);
        PurgeQueue::singleton()->reset();

        // The reorder names no record, so every member's page is purged.
        $callback = $lister->Pages()->addCallbacks()->get('edge-cache-purge');
        (new EdgeCacheOrderableRowsExtension())->onAfterReorderItems($lister->Pages(), [], []);

        $this->assertNotNull($callback);
        $this->assertPurgedPages([$first, $second]);
    }

    public function testPagesCopyingThePageArePurgedToo(): void
    {
        $page = $this->page();
        $virtual = VirtualPage::create(['Title' => 'Copy', 'CopyContentFromID' => $page->ID, 'ShowInMenus' => false]);
        $virtual->write();
        PurgeQueue::singleton()->reset();

        $page->Targets()->add($this->target());

        $this->assertPurgedPages([$page, $virtual]);
    }

    public function testAListHandedOutBySingletonPurgesTheOwnersItNames(): void
    {
        $first = $this->page();
        $second = $this->page();
        $target = $this->target();

        // DataList::relation() builds the list on a record with no ID, for the IDs in the list.
        OwnedRelationPage::get()->filter('ID', [$first->ID, $second->ID])->relation('Targets')->add($target);

        $this->assertPurgedPages([$first, $second]);
    }

    public function testAPlainRecordWithAPlainListStillPurgesNothing(): void
    {
        $owner = PlainJoinOwner::create(['Title' => 'Plain']);
        $owner->write();
        PurgeQueue::singleton()->reset();

        $owner->Targets()->add($this->target());

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testTheListStillWorksAfterThePurgeHook(): void
    {
        $page = $this->page();
        $page->Targets()->add($this->target('One'));
        $page->Targets()->add($this->target('Two'));

        $this->assertSame(['One', 'Two'], $page->Targets()->sort('Title')->column('Title'));
    }
}
