<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Page;
use SilverStripe\Control\Director;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * A page that lists authors is tagged with the Member class, and a change to a name purges it.
 */
class EdgeCacheMemberExtensionTest extends EdgeCacheTestCase
{
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

    public function testAnAnonymousPageRenderIsNotTaggedWithMembers(): void
    {
        // Core switches HTTP caching off in dev; run as a live site would.
        HTTPCacheControlMiddleware::reset();
        HTTPCacheControlMiddleware::config()->set('defaultState', HTTPCacheControlMiddleware::STATE_ENABLED);
        HTTPCacheControlMiddleware::config()->set('defaultForcingLevel', 0);

        Versioned::set_stage(Versioned::DRAFT);
        $page = Page::create(['Title' => 'About', 'URLSegment' => 'about']);
        $page->write();
        $page->publishSingle();
        Versioned::set_stage(Versioned::LIVE);

        $response = Director::test('about');

        $this->assertSame(200, $response->getStatusCode());
        $tags = (string) $response->getHeader('Cache-Tag');
        $this->assertStringContainsString('ec-page-' . $page->ID, $tags, 'the page was stamped for the edge');
        $this->assertStringNotContainsString('ec-class-Member', $tags);
    }
}
