<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Middleware\EdgeCacheMiddleware;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\ListedSubThing;
use Dynamic\EdgeCache\Tests\Fixtures\ListedThing;
use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * A page is tagged with the classes it queries, so publishing one of them purges the page.
 */
class EdgeCacheQueryExtensionTest extends EdgeCacheTestCase
{
    protected static $extra_dataobjects = [ListedThing::class, ListedSubThing::class];

    private function tags(callable $render): array
    {
        $edge = EdgeCache::singleton();
        $edge->startCollecting();
        $render();
        $edge->stopCollecting();

        return $edge->getTags();
    }

    public function testQueriedClassBecomesATag(): void
    {
        $tags = $this->tags(fn () => ListedThing::get()->toArray());

        $this->assertSame(['ec-site', 'ec-class-ListedThing'], $tags);
    }

    public function testASubclassQueryTagsTheSubclass(): void
    {
        $tags = $this->tags(fn () => ListedSubThing::get()->toArray());

        $this->assertContains('ec-class-ListedSubThing', $tags);
    }

    public function testNavigationAndPageClassesAreNotTags(): void
    {
        $tags = $this->tags(function () {
            Page::get()->toArray();
            SiteTree::get()->toArray();
            SiteConfig::current_site_config();
        });

        $this->assertSame(['ec-site'], $tags);
    }

    public function testNothingIsRecordedWhenNotCollecting(): void
    {
        ListedThing::get()->toArray();

        $this->assertSame(['ec-site'], EdgeCache::singleton()->getTags());
    }

    public function testTheMiddlewareCollectsForAGetRequestAndEmitsTheTag(): void
    {
        $response = (new EdgeCacheMiddleware())->process(new HTTPRequest('GET', 'blog'), function () {
            ListedThing::get()->toArray();
            EdgeCache::singleton()->markCacheable();
            $response = new HTTPResponse('body');
            $response->addHeader('Cache-Control', 'public, max-age=60');

            return $response;
        });

        $this->assertSame('ec-site,ec-class-ListedThing', $response->getHeader('Cache-Tag'));
        $this->assertFalse(EdgeCache::isCollecting(), 'collection stops when the request ends');
    }

    public function testThePostRequestIsNotCollected(): void
    {
        (new EdgeCacheMiddleware())->process(new HTTPRequest('POST', 'blog'), function () {
            ListedThing::get()->toArray();

            return new HTTPResponse('body');
        });

        $this->assertSame(['ec-site'], EdgeCache::singleton()->getTags());
    }

    public function testCollectionIsOffOutsideAnEnabledEnvironment(): void
    {
        \SilverStripe\Core\Environment::setEnv('SS_ENVIRONMENT_TYPE', 'dev');
        (new EdgeCacheMiddleware())->process(new HTTPRequest('GET', 'blog'), function () {
            $this->assertFalse(EdgeCache::isCollecting());

            return new HTTPResponse('body');
        });
    }

    public function testClassChainTagsCoverEveryParentBelowDataObject(): void
    {
        $this->assertSame(
            ['ec-class-ListedThing', 'ec-class-ListedSubThing'],
            EdgeCache::classChainTags(ListedSubThing::class)
        );
        $this->assertContains('ec-class-SiteTree', EdgeCache::classChainTags(Page::class));
        $this->assertContains('ec-class-Page', EdgeCache::classChainTags(Page::class));
    }

    public function testAPageOverTheTagLimitIsNotEdgeCached(): void
    {
        EdgeCache::config()->set('max_tags', 3);

        $response = (new EdgeCacheMiddleware())->process(new HTTPRequest('GET', 'blog'), function () {
            EdgeCache::singleton()->markCacheable();
            EdgeCache::singleton()->addTags(['a', 'b', 'c']);
            $response = new HTTPResponse('body');
            $response->addHeader('Cache-Control', 'public, max-age=60');

            return $response;
        });

        $this->assertNull($response->getHeader('Cache-Tag'));
        $this->assertNull($response->getHeader('Edge-Cache-Control'));
    }

    public function testAPageAtTheTagLimitIsStillCached(): void
    {
        EdgeCache::config()->set('max_tags', 3);

        $response = (new EdgeCacheMiddleware())->process(new HTTPRequest('GET', 'blog'), function () {
            EdgeCache::singleton()->markCacheable();
            EdgeCache::singleton()->addTags(['a', 'b']);
            $response = new HTTPResponse('body');
            $response->addHeader('Cache-Control', 'public, max-age=60');

            return $response;
        });

        $this->assertSame('ec-site,a,b', $response->getHeader('Cache-Tag'));
    }
}
