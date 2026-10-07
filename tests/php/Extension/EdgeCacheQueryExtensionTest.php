<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Middleware\EdgeCacheMiddleware;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Dynamic\EdgeCache\Tests\Fixtures\ListedOwner;
use Dynamic\EdgeCache\Tests\Fixtures\ListedPage;
use Dynamic\EdgeCache\Tests\Fixtures\ListedSubThing;
use Dynamic\EdgeCache\Tests\Fixtures\ListedThing;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use RuntimeException;
use SilverStripe\Core\Injector\Injector;
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
    protected static $extra_dataobjects = [
        ListedThing::class,
        ListedSubThing::class,
        ListedOwner::class,
        ListedPage::class,
    ];

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

    public function testRelationListsAndSingleRecordLookupsAreTagged(): void
    {
        $owner = ListedOwner::create(['Title' => 'Owner']);
        $owner->write();
        $thing = ListedThing::create(['Title' => 'Thing', 'OwnerID' => $owner->ID]);
        $thing->write();

        foreach (
            [
            'has_many list' => fn () => $owner->Things()->toArray(),
            'first()' => fn () => ListedThing::get()->first(),
            'byID()' => fn () => ListedThing::get()->byID($thing->ID),
            'count()' => fn () => ListedThing::get()->count(),
            ] as $shape => $render
        ) {
            EdgeCache::singleton()->reset();
            $this->assertContains('ec-class-ListedThing', $this->tags($render), $shape);
        }
    }

    public function testEveryDefaultIgnoredClassAddsNoTag(): void
    {
        $classes = array_merge(
            (array) EdgeCache::config()->get('auto_tag_ignore'),
            (array) EdgeCache::config()->get('auto_tag_ignore_descendants'),
            ['\\Page', 'page', 'SILVERSTRIPE\\CMS\\MODEL\\SITETREE']
        );

        foreach ($classes as $class) {
            EdgeCache::singleton()->reset();
            EdgeCache::singleton()->collectClass($class);

            $this->assertSame(['ec-site'], EdgeCache::singleton()->getTags(), $class);
        }
    }

    public function testTheDefaultIgnoreListsNameTheExpectedClasses(): void
    {
        $this->assertEqualsCanonicalizing(
            ['SilverStripe\\CMS\\Model\\SiteTree', 'Page', 'SilverStripe\\ORM\\DataObject'],
            (array) EdgeCache::config()->get('auto_tag_ignore')
        );
        $this->assertEqualsCanonicalizing(
            [
                'DNADesign\\Elemental\\Models\\BaseElement',
                'DNADesign\\Elemental\\Models\\ElementalArea',
                'SilverStripe\\SiteConfig\\SiteConfig',
                'SilverStripe\\Assets\\File',
                'SilverStripe\\Security\\Group',
            ],
            (array) EdgeCache::config()->get('auto_tag_ignore_descendants')
        );
    }

    public function testSubclassesOfTheDescendantIgnoresAreIgnoredToo(): void
    {
        $edge = EdgeCache::singleton();
        foreach (
            [
            'SilverStripe\\Assets\\Image',
            'SilverStripe\\Assets\\Folder',
            'DNADesign\\Elemental\\Models\\ElementContent',
            ] as $class
        ) {
            $this->assertTrue($edge->isIgnoredClass($class), $class);
        }
        $this->assertFalse($edge->isIgnoredClass(ListedPage::class), 'a page subclass lists its own records');
    }

    public function testASubclassOfAnIgnoredClassIsStillTagged(): void
    {
        // A blog post is a Page; Page is ignored but the post's own class is not.
        $tags = $this->tags(fn () => ListedPage::get()->toArray());

        $this->assertContains('ec-class-ListedPage', $tags);
    }

    public function testCollectionStopsWhenTheDelegateThrows(): void
    {
        try {
            (new EdgeCacheMiddleware())->process(new HTTPRequest('GET', 'blog'), function () {
                $this->assertTrue(EdgeCache::isCollecting(), 'collection is on while the page renders');
                ListedThing::get()->toArray();

                throw new RuntimeException('render failed');
            });
            $this->fail('The exception should propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('render failed', $e->getMessage());
        }

        $this->assertFalse(EdgeCache::isCollecting());
    }

    public function testHeadRequestsCollectAndAStaleFlagDoesNotLeakIntoAPost(): void
    {
        (new EdgeCacheMiddleware())->process(new HTTPRequest('HEAD', 'blog'), function () {
            $this->assertTrue(EdgeCache::isCollecting());

            return new HTTPResponse('');
        });

        EdgeCache::singleton()->startCollecting();
        (new EdgeCacheMiddleware())->process(new HTTPRequest('POST', 'blog'), function () {
            $this->assertFalse(EdgeCache::isCollecting(), 'a flag left on by an earlier request is cleared');

            return new HTTPResponse('');
        });
    }

    private function overflowRequest(string $url): HTTPResponse
    {
        return (new EdgeCacheMiddleware())->process(new HTTPRequest('GET', $url), function () {
            EdgeCache::singleton()->markCacheable();
            EdgeCache::singleton()->addTags(['a', 'b']);
            $response = new HTTPResponse('body');
            $response->addHeader('Cache-Control', 'public, max-age=60');

            return $response;
        });
    }

    public function testTheTagLimitWarningIsLoggedOncePerUrl(): void
    {
        $handler = new TestHandler();
        Injector::inst()->registerService(new Logger('test', [$handler]), LoggerInterface::class);
        Injector::inst()->registerService(
            new Psr16Cache(new ArrayAdapter()),
            CacheInterface::class . '.EdgeCache'
        );
        EdgeCache::config()->set('max_tags', 2);

        $this->overflowRequest('blog');
        $this->overflowRequest('blog');
        $this->overflowRequest('news');

        $records = array_filter($handler->getRecords(), fn ($r) => str_contains($r['message'], 'over the limit of 2'));
        $this->assertCount(2, $records, 'once for each URL, not once per request');
        $this->assertSame(['ec-site', 'a', 'b'], array_values($records)[0]['context']['first_tags']);
    }

    public function testAnOverflowingPageIsMadePrivateSoNothingCachesItUntagged(): void
    {
        Injector::inst()->registerService(
            new Psr16Cache(new ArrayAdapter()),
            CacheInterface::class . '.EdgeCache'
        );
        EdgeCache::config()->set('max_tags', 2);

        $response = $this->overflowRequest('blog');

        $this->assertSame('private, must-revalidate', $response->getHeader('Cache-Control'));
    }

    public function testACookieOnAPublicPageMakesItPrivate(): void
    {
        $response = (new EdgeCacheMiddleware())->process(new HTTPRequest('GET', 'blog'), function () {
            EdgeCache::singleton()->markCacheable();
            $response = new HTTPResponse('body');
            $response->addHeader('Cache-Control', 'public, max-age=60');
            $response->addHeader('Set-Cookie', 'PHPSESSID=abc; path=/');

            return $response;
        });

        $this->assertSame('private, must-revalidate', $response->getHeader('Cache-Control'));
        $this->assertNull($response->getHeader('Edge-Cache-Control'));
    }

    public function testAPageTheControllerNeverMarkedKeepsItsCacheControl(): void
    {
        $response = (new EdgeCacheMiddleware())->process(new HTTPRequest('GET', 'blog'), function () {
            $response = new HTTPResponse('body');
            $response->addHeader('Cache-Control', 'public, max-age=3600');

            return $response;
        });

        $this->assertSame('public, max-age=3600', $response->getHeader('Cache-Control'), 'not our page, not our header');
    }

    public function testARequestMadeWhileAPageRendersDoesNotDisturbTheOuterRequest(): void
    {
        $middleware = new EdgeCacheMiddleware();

        $response = $middleware->process(new HTTPRequest('GET', 'outer'), function () use ($middleware) {
            EdgeCache::singleton()->markCacheable();
            EdgeCache::singleton()->addTags('ec-page-1');
            ListedThing::get()->toArray();

            $inner = $middleware->process(new HTTPRequest('GET', 'inner'), function () {
                return new HTTPResponse('inner');
            });
            $this->assertSame('inner', $inner->getBody());

            $this->assertTrue(EdgeCache::isCollecting(), 'the outer page is still recording');
            ListedSubThing::get()->toArray();

            $response = new HTTPResponse('outer');
            $response->addHeader('Cache-Control', 'public, max-age=60');

            return $response;
        });

        $this->assertSame(
            'ec-site,ec-page-1,ec-class-ListedThing,ec-class-ListedSubThing',
            $response->getHeader('Cache-Tag')
        );
    }

    public function testALazyLoadOfThePagesOwnFieldsIsNotATagButAnotherRecordsIs(): void
    {
        Versioned::set_stage(Versioned::DRAFT);
        $own = ListedPage::create(['Title' => 'Own', 'Summary' => 'a']);
        $own->write();
        $own->publishSingle();
        $other = ListedPage::create(['Title' => 'Other', 'Summary' => 'b']);
        $other->write();
        $other->publishSingle();
        Versioned::set_stage(Versioned::LIVE);

        $edge = EdgeCache::singleton();
        $edge->reset();
        $edge->startCollecting();
        $edge->setCurrentPageId((int) $own->ID);
        // Hydrated through the base class, so the subclass table is lazy-loaded when a field is read.
        $page = SiteTree::get()->byID($own->ID);
        $this->assertSame('a', $page->Summary);
        $edge->stopCollecting();
        $this->assertSame(['ec-site'], $edge->getTags(), 'the page loading its own fields is not a listing');

        $edge->startCollecting();
        $listed = SiteTree::get()->byID($other->ID);
        $this->assertSame('b', $listed->Summary);
        $edge->stopCollecting();
        $this->assertContains('ec-class-ListedPage', $edge->getTags(), 'a listed record is');
    }

    public function testASiteCanIgnoreItsPageBaseClassSoNavigationDoesNotTieEveryPageToIt(): void
    {
        $edge = EdgeCache::singleton();
        $edge->reset();
        $edge->startCollecting();
        $edge->setCurrentPageId(1);
        $edge->collectLazyClass(ListedPage::class, 9);
        $this->assertContains('ec-class-ListedPage', $edge->getTags(), 'tagged by default');

        EdgeCache::config()->merge('auto_tag_ignore_descendants', [ListedPage::class]);
        $edge->reset();
        $edge->startCollecting();
        $edge->setCurrentPageId(1);
        $edge->collectLazyClass(ListedPage::class, 9);

        $this->assertNotContains('ec-class-ListedPage', $edge->getTags());
    }
}
