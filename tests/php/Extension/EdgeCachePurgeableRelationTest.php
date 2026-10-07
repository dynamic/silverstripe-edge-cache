<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\Extension\EdgeCacheOrderableRowsExtension;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\JoinOwner;
use Dynamic\EdgeCache\Tests\Fixtures\JoinTarget;
use Dynamic\EdgeCache\Tests\Fixtures\ListedJoinOwner;
use Dynamic\EdgeCache\Tests\Fixtures\PlainJoinOwner;
use Dynamic\EdgeCache\Tests\Fixtures\ThroughJoin;
use Dynamic\EdgeCache\Tests\Fixtures\ThroughOwner;
use ReflectionMethod;
use SilverStripe\ORM\ArrayList;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

/**
 * A many_many join write fires no record event, so EdgeCachePurgeable hooks the relation list.
 */
class EdgeCachePurgeableRelationTest extends EdgeCacheTestCase
{
    protected static $extra_dataobjects = [
        JoinTarget::class,
        JoinOwner::class,
        ListedJoinOwner::class,
        PlainJoinOwner::class,
        ThroughOwner::class,
        ThroughJoin::class,
    ];

    private function owner(): JoinOwner
    {
        $owner = JoinOwner::create(['Title' => 'Footer links']);
        $owner->write();
        PurgeQueue::singleton()->reset();

        return $owner;
    }

    private function target(string $title = 'Target'): JoinTarget
    {
        $target = JoinTarget::create(['Title' => $title]);
        $target->write();
        PurgeQueue::singleton()->reset();

        return $target;
    }

    public function testAddingAMemberPurges(): void
    {
        $owner = $this->owner();

        $owner->Targets()->add($this->target());

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testRemovingAMemberPurges(): void
    {
        $owner = $this->owner();
        $target = $this->target();
        $owner->Targets()->add($target);
        PurgeQueue::singleton()->reset();

        $owner->Targets()->remove($target);

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testRemovingByIdPurges(): void
    {
        $owner = $this->owner();
        $target = $this->target();
        $owner->Targets()->add($target);
        PurgeQueue::singleton()->reset();

        $owner->Targets()->removeByID($target->ID);

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testClearingTheListPurges(): void
    {
        $owner = $this->owner();
        $owner->Targets()->add($this->target());
        PurgeQueue::singleton()->reset();

        $owner->Targets()->removeAll();

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testSettingTheListByIdsPurges(): void
    {
        $owner = $this->owner();
        $first = $this->target('One');
        $second = $this->target('Two');

        $owner->Targets()->setByIDList([$first->ID, $second->ID]);

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testTheListStillWorksAfterThePurgeHook(): void
    {
        $owner = $this->owner();
        $owner->Targets()->add($this->target('One'));
        $owner->Targets()->add($this->target('Two'));

        $this->assertSame(['One', 'Two'], $owner->Targets()->sort('Title')->column('Title'));
    }

    public function testEditingFromTheOtherSideStillPurgesTheClassThatOptedIn(): void
    {
        $owner = $this->owner();
        $target = $this->target();

        // The target class did not opt in; the owner class did.
        $target->Owners()->add($owner);

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);

        PurgeQueue::singleton()->reset();
        $target->Owners()->remove($owner);

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testTheOtherSideUsesTheOwnersSettingNotItsOwn(): void
    {
        $owner = ListedJoinOwner::create(['Title' => 'Team']);
        $owner->write();
        $target = $this->target();

        $target->ListedOwners()->add($owner);

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertSame(['ec-class-ListedJoinOwner'], $pending['tags']);
    }

    public function testTheDefaultModePurgesTheOwnersClassTag(): void
    {
        $owner = ListedJoinOwner::create(['Title' => 'Team']);
        $owner->write();
        PurgeQueue::singleton()->reset();

        $owner->Targets()->add($this->target());

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertSame(['ec-class-ListedJoinOwner'], $pending['tags']);
    }

    public function testAnOwnerWithoutTheExtensionPurgesNothing(): void
    {
        $owner = PlainJoinOwner::create(['Title' => 'Plain']);
        $owner->write();
        PurgeQueue::singleton()->reset();

        $owner->Targets()->add($this->target());

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testAManyManyThroughRelationPurges(): void
    {
        $owner = ThroughOwner::create(['Title' => 'Through']);
        $owner->write();
        $target = $this->target();
        PurgeQueue::singleton()->reset();

        $owner->Targets()->add($target);

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);

        PurgeQueue::singleton()->reset();
        $owner->Targets()->remove($target);

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
    }

    public function testReorderingThroughTheGridFieldExtensionPurges(): void
    {
        $owner = $this->owner();
        $first = $this->target('One');
        $second = $this->target('Two');
        $owner->Targets()->add($first, ['Sort' => 1]);
        $owner->Targets()->add($second, ['Sort' => 2]);
        PurgeQueue::singleton()->reset();

        // What GridFieldOrderableRows does on a drag: the sort column is written with a raw query.
        $list = $owner->Targets()->sort('Sort');
        $component = new GridFieldOrderableRows('Sort');
        $reorder = new ReflectionMethod($component, 'reorderItems');
        $reorder->setAccessible(true);
        $reorder->invoke($component, $list, [], [1 => $second->ID, 2 => $first->ID]);

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
        $this->assertSame(['Two', 'One'], $owner->Targets()->sort('Sort')->column('Title'));
    }

    public function testReorderingAManyManyThroughListPurges(): void
    {
        $owner = ThroughOwner::create(['Title' => 'Through']);
        $owner->write();
        $first = $this->target('One');
        $second = $this->target('Two');
        $owner->Targets()->add($first, ['Sort' => 1]);
        $owner->Targets()->add($second, ['Sort' => 2]);
        PurgeQueue::singleton()->reset();

        // A through list reorders by saving the join record, which does not opt in to purging.
        $list = $owner->Targets()->sort('Sort');
        $component = new GridFieldOrderableRows('Sort');
        $reorder = new ReflectionMethod($component, 'reorderItems');
        $reorder->setAccessible(true);
        $reorder->invoke($component, $list, [], [1 => $second->ID, 2 => $first->ID]);

        $this->assertTrue(PurgeQueue::singleton()->pending()['everything']);
        $this->assertSame(['Two', 'One'], $owner->Targets()->sort('Sort')->column('Title'));
    }

    public function testReorderingAListThatIsNotManyManyDoesNothing(): void
    {
        (new EdgeCacheOrderableRowsExtension())->onAfterReorderItems(ArrayList::create(), [], []);

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testReorderingAnOwnerThatDidNotOptInPurgesNothing(): void
    {
        $owner = PlainJoinOwner::create(['Title' => 'Plain']);
        $owner->write();

        (new EdgeCacheOrderableRowsExtension())->onAfterReorderItems($owner->Targets(), [], []);

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }
}
