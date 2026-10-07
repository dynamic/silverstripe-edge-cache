<?php

namespace Dynamic\EdgeCache\Tests\Extension;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Page;
use PageController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Session;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Core\Environment;
use SilverStripe\Versioned\Versioned;

class EdgeCacheControllerExtensionTest extends EdgeCacheTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Core switches HTTP caching off in dev (defaultState disabled, forcing level 3). The
        // tests run as a live site would.
        HTTPCacheControlMiddleware::reset();
        HTTPCacheControlMiddleware::config()->set('defaultState', HTTPCacheControlMiddleware::STATE_ENABLED);
        HTTPCacheControlMiddleware::config()->set('defaultForcingLevel', 0);
    }

    private function init(
        string $url = 'about',
        bool $ajax = false,
        string $stage = Versioned::LIVE,
        string $method = 'GET'
    ): PageController {
        Versioned::set_stage(Versioned::DRAFT);
        $page = Page::create(['Title' => 'About']);
        $page->write();
        $page->publishSingle();
        Versioned::set_stage($stage);

        $request = new HTTPRequest($method, $url);
        if ($ajax) {
            $request->addHeader('X-Requested-With', 'XMLHttpRequest');
        }
        $controller = PageController::create($page);
        $controller->setRequest($request);
        $controller->doInit();

        return $controller;
    }

    public function testLivePageBecomesPublicWithABriefBrowserLifetime(): void
    {
        $this->init();

        $middleware = HTTPCacheControlMiddleware::singleton();
                $this->assertSame(HTTPCacheControlMiddleware::STATE_PUBLIC, $middleware->getState());
        $this->assertTrue(EdgeCache::singleton()->isMarkedCacheable());

        $response = new HTTPResponse('x');
        $middleware->applyToResponse($response);
        $this->assertStringContainsString('max-age=60', $response->getHeader('Cache-Control'));
        $this->assertStringNotContainsString('s-maxage', $response->getHeader('Cache-Control'));
    }

    public function testPageIsTaggedWithItsId(): void
    {
        $controller = $this->init();
        $tags = EdgeCache::singleton()->getTags();

        $this->assertContains('ec-page-' . $controller->data()->ID, $tags);
        $this->assertNotContains('ec-class-Page', $tags, 'a plain page must not tie itself to every page');
    }

    public function testAPageCanDeclareADependencyOnAnIgnoredClass(): void
    {
        // A sitemap lists every page but queries SiteTree, which is ignored when tagging.
        Page::config()->set('edge_cache_depends_on', [\SilverStripe\CMS\Model\SiteTree::class]);
        $this->init();

        $this->assertContains('ec-class-SiteTree', EdgeCache::singleton()->getTags());
    }

    public function testADeclaredDependencyIsRememberedForScheduledFields(): void
    {
        Page::config()->set('edge_cache_depends_on', [\Dynamic\EdgeCache\Tests\Fixtures\ScheduledThing::class]);

        $this->init();

        $this->assertContains(
            \Dynamic\EdgeCache\Tests\Fixtures\ScheduledThing::class,
            EdgeCache::singleton()->collectedClasses()
        );
    }

    public function testAPageWithItsOwnScheduledFieldsDeclaresItsClass(): void
    {
        Page::config()->set('edge_cache_schedule_fields', ['LastEdited']);

        $this->init();

        $this->assertContains(Page::class, EdgeCache::singleton()->collectedClasses());
    }

    public function testNothingHappensOutsideLive(): void
    {
        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'dev');
        $this->init();

        $this->assertFalse(EdgeCache::singleton()->isMarkedCacheable());
        $this->assertNotSame(HTTPCacheControlMiddleware::STATE_PUBLIC, HTTPCacheControlMiddleware::singleton()->getState());
    }

    public function testToggleOffLeavesCoreDefaults(): void
    {
        $this->setToggle(false);
        $this->init();

        $this->assertFalse(EdgeCache::singleton()->isMarkedCacheable());
        $this->assertNotSame(HTTPCacheControlMiddleware::STATE_PUBLIC, HTTPCacheControlMiddleware::singleton()->getState());
    }

    public function testPostRequestsAreLeftAlone(): void
    {
        $this->init(method: 'POST');

        $this->assertFalse(EdgeCache::singleton()->isMarkedCacheable());
        $this->assertNotSame(HTTPCacheControlMiddleware::STATE_PUBLIC, HTTPCacheControlMiddleware::singleton()->getState());
    }

    public function testAjaxRequestsAreNeverCached(): void
    {
        $this->init(ajax: true);

        $this->assertFalse(EdgeCache::singleton()->isMarkedCacheable());
        $this->assertSame(HTTPCacheControlMiddleware::STATE_DISABLED, HTTPCacheControlMiddleware::singleton()->getState());
    }

    public function testASessionStillMakesThePagePrivate(): void
    {
        // Public is requested without force, so core's session rule still wins.
        $request = new HTTPRequest('GET', 'about');
        $request->setSession(new Session(['loggedInAs' => 5]));

        $response = HTTPCacheControlMiddleware::singleton()->process($request, function () {
            $this->init();

            return new HTTPResponse('x');
        });

        $this->assertStringContainsString('private', $response->getHeader('Cache-Control'));
        $this->assertStringNotContainsString('public', $response->getHeader('Cache-Control'));
    }

    public function testStageRequestIsNotCached(): void
    {
        $this->init('about?stage=Stage', stage: Versioned::DRAFT);

        $this->assertFalse(EdgeCache::singleton()->isMarkedCacheable());
    }
}
