<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\AuthorMember;
use Dynamic\EdgeCache\Tests\Fixtures\ChildrenHolderPage;
use Page;
use PageController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverStripe\TemplateEngine\SSTemplateEngine;
use SilverStripe\View\ViewLayerData;

/**
 * A page that lists authors is tagged with the Member class, and a change to a name purges it.
 */
class EdgeCacheMemberExtensionTest extends EdgeCacheTestCase
{
    protected static $extra_dataobjects = [AuthorMember::class, ChildrenHolderPage::class];

    private function member(): Member
    {
        $member = Member::create(['FirstName' => 'Ada', 'Surname' => 'Lovelace', 'Email' => 'ada@example.com']);
        $member->write();
        PurgeQueue::singleton()->reset();

        return $member;
    }

    public function testAPageThatQueriesMembersIsTagged(): void
    {
        $edge = EdgeCache::singleton();
        $edge->startCollecting();
        Member::get()->toArray();
        $edge->stopCollecting();

        $this->assertContains('ec-class-Member', $edge->getTags());
    }

    public function testChangingANamePurgesPagesThatListMembers(): void
    {
        $member = $this->member();

        $member->FirstName = 'Augusta';
        $member->write();

        $pending = PurgeQueue::singleton()->pending();
        $this->assertFalse($pending['everything']);
        $this->assertSame(['ec-class-Member'], $pending['tags']);
    }

    public function testChangingTheSurnameAlsoPurges(): void
    {
        $member = $this->member();

        $member->Surname = 'King';
        $member->write();

        $this->assertSame(['ec-class-Member'], PurgeQueue::singleton()->pending()['tags']);
    }

    public function testTheWritesALoginMakesDoNotPurge(): void
    {
        $member = $this->member();

        $member->LastVisited = '2026-10-07 12:00:00';
        $member->FailedLoginCount = 2;
        $member->Password = 'something-new-1A';
        $member->write();

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testASaveThatChangesNothingDoesNotPurge(): void
    {
        $member = $this->member();

        $member->write();

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testTheFlagDoesNotLeakIntoTheNextWrite(): void
    {
        $member = $this->member();
        $member->FirstName = 'Augusta';
        $member->write();
        PurgeQueue::singleton()->reset();

        $member->LastVisited = '2026-10-07 12:00:00';
        $member->write();

        $this->assertTrue(PurgeQueue::singleton()->isEmpty());
    }

    public function testDeletingAMemberPurges(): void
    {
        $member = $this->member();

        $member->delete();

        $this->assertSame(['ec-class-Member'], PurgeQueue::singleton()->pending()['tags']);
    }

    public function testTheFieldListIsConfigurable(): void
    {
        Member::config()->set('edge_cache_purge_fields', ['Email']);
        $member = $this->member();

        $member->FirstName = 'Augusta';
        $member->write();
        $this->assertTrue(PurgeQueue::singleton()->isEmpty(), 'FirstName is no longer a shown field');

        $member->Email = 'augusta@example.com';
        $member->write();
        $this->assertSame(['ec-class-Member'], PurgeQueue::singleton()->pending()['tags']);
    }

    public function testAMemberSubclassPurgesItsOwnTagAndMembers(): void
    {
        $author = AuthorMember::create(['FirstName' => 'Ada', 'Email' => 'author@example.com']);
        $author->write();
        PurgeQueue::singleton()->reset();

        $author->FirstName = 'Augusta';
        $author->write();

        $this->assertEqualsCanonicalizing(
            ['ec-class-Member', 'ec-class-AuthorMember'],
            PurgeQueue::singleton()->pending()['tags']
        );
    }

    public function testListingASubclassTagsIt(): void
    {
        $edge = EdgeCache::singleton();
        $edge->startCollecting();
        AuthorMember::get()->toArray();
        $edge->stopCollecting();

        $this->assertContains('ec-class-AuthorMember', $edge->getTags());
    }

    public function testAddingAMemberToAGroupPurgesPagesThatListMembers(): void
    {
        $member = $this->member();
        $group = Group::create(['Title' => 'Staff']);
        $group->write();
        PurgeQueue::singleton()->reset();

        $group->Members()->add($member);
        $this->assertSame(['ec-class-Member'], PurgeQueue::singleton()->pending()['tags']);

        PurgeQueue::singleton()->reset();
        $group->Members()->remove($member);
        $this->assertSame(['ec-class-Member'], PurgeQueue::singleton()->pending()['tags']);
    }

    public function testAddingAGroupToAMemberPurgesToo(): void
    {
        $member = $this->member();
        $group = Group::create(['Title' => 'Staff']);
        $group->write();
        PurgeQueue::singleton()->reset();

        $member->Groups()->add($group);

        $this->assertSame(['ec-class-Member'], PurgeQueue::singleton()->pending()['tags']);
    }

    public function testRenderingANavigationMenuDoesNotTagMembers(): void
    {
        $this->publish(Page::class, 'Home');
        $about = $this->publish(Page::class, 'About');
        $this->publish(Page::class, 'Team', $about->ID);

        $tags = $this->renderTags($about, '<% loop $Menu(1) %>$Title<% if $Children %>x<% end_if %><% end_loop %>');

        $this->assertNotContains('ec-class-Member', $tags);
        $this->assertSame([], array_filter($tags, fn ($t) => str_starts_with($t, 'ec-children-')), 'a plain page opts out');
    }

    public function testAMenuTagsAnOptedInHolderWhichIsWhyItIsOptIn(): void
    {
        $holder = $this->publish(ChildrenHolderPage::class, 'News');

        $tags = $this->renderTags($holder, '<% loop $Menu(1) %><% if $Children %>x<% end_if %><% end_loop %>');

        $this->assertContains('ec-children-' . $holder->ID, $tags);
    }

    public function testATemplateThatListsMembersTagsThePage(): void
    {
        $page = $this->publish(Page::class, 'Team');
        $this->member();

        $tags = $this->renderTags($page, '<% loop $Members %>$FirstName<% end_loop %>', ['Members' => Member::get()]);

        $this->assertContains('ec-class-Member', $tags);
    }

    private function publish(string $class, string $title, int $parentId = 0): Page
    {
        Versioned::set_stage(Versioned::DRAFT);
        $page = $class::create(['Title' => $title, 'ParentID' => $parentId, 'ShowInMenus' => true]);
        $page->write();
        $page->publishSingle();
        Versioned::set_stage(Versioned::LIVE);
        PurgeQueue::singleton()->reset();

        return $page;
    }

    /**
     * Render a template string for a page while recording queries, and return the page's tags.
     */
    private function renderTags(Page $page, string $template, array $extra = []): array
    {
        // The test case runs as the default admin; a page served from the edge is anonymous.
        Security::setCurrentUser(null);

        $edge = EdgeCache::singleton();
        $edge->reset();
        $edge->startCollecting();
        $controller = PageController::create(Page::get()->byID($page->ID));
        $controller->setRequest(new HTTPRequest('GET', $page->URLSegment));
        SSTemplateEngine::create()->renderString($template, ViewLayerData::create($controller), $extra);
        $edge->stopCollecting();

        return $edge->getTags();
    }
}
