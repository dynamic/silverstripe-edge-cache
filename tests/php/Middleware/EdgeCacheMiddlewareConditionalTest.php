<?php

namespace Dynamic\EdgeCache\Tests\Middleware;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Middleware\EdgeCacheMiddleware;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use Page;
use PageController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\ChangeDetectionMiddleware;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Control\Session;
use SilverStripe\Versioned\Versioned;

/**
 * The conditional round trip through core's real stack: the edge revalidates a stale page with
 * `If-None-Match`, ChangeDetectionMiddleware answers 304 with the page's headers and no body, and
 * the middleware stamps that answer as it stamped the page.
 */
class EdgeCacheMiddlewareConditionalTest extends EdgeCacheTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The suite boots as a dev environment, where core forces caching off; the middleware is
        // tested in the state a live site has.
        HTTPCacheControlMiddleware::reset();
        HTTPCacheControlMiddleware::config()->set('defaultState', HTTPCacheControlMiddleware::STATE_ENABLED);
        HTTPCacheControlMiddleware::config()->set('defaultForcingLevel', 0);
    }

    public function testTheAnswerToARevalidationIsStampedLikeThePage(): void
    {
        $page = $this->publishedPage();

        $session = new Session([]);
        $first = $this->request($page, [], $session);
        // DIAG (temporary): explain a private 200 on CI.
        $control = HTTPCacheControlMiddleware::singleton();
        $internal = (fn () => ['state' => $this->state, 'forcingLevel' => $this->forcingLevel, 'directives' => $this->stateDirectives])->call($control);
        $edge = EdgeCache::singleton();
        fwrite(STDERR, "\nDIAG " . json_encode([
            'status' => $first->getStatusCode(),
            'cache-control' => $first->getHeader('Cache-Control'),
            'edge-cache-control' => $first->getHeader('Edge-Cache-Control'),
            'cache-tag' => $first->getHeader('Cache-Tag'),
            'vary' => $first->getHeader('Vary'),
            'set-cookie-header' => $first->getHeader('Set-Cookie'),
            'headers_list' => headers_list(),
            'session' => $session->getAll(),
            'session_started' => $session->isStarted(),
            'markedCacheable' => $edge->isMarkedCacheable(),
            'enabled' => $edge->isEnabled(),
            'envEnabled' => $edge->isEnvironmentEnabled(),
            'tags' => $edge->getTags(),
            'stage' => Versioned::get_stage(),
            'env' => \SilverStripe\Core\Environment::getEnv('SS_ENVIRONMENT_TYPE'),
            'isDev' => \SilverStripe\Control\Director::isDev(),
            'control' => $internal,
            'defaultState' => HTTPCacheControlMiddleware::config()->get('defaultState'),
            'defaultForcingLevel' => HTTPCacheControlMiddleware::config()->get('defaultForcingLevel'),
            'pageController' => get_parent_class(PageController::class),
            'adapter' => get_class($edge->adapter()),
            'allowedVary' => $edge->adapter()->allowedVary(),
            'siteconfig' => \SilverStripe\SiteConfig\SiteConfig::current_site_config()->EdgeCacheEnabled,
            'pageId' => $page->ID,
            'published' => $page->isPublished(),
            'canView' => $page->canView(),
            'currentUser' => \SilverStripe\Security\Security::getCurrentUser()?->ID,
            'body_len' => strlen((string) $first->getBody()),
            'body_head' => substr((string) $first->getBody(), 0, 300),
        ], JSON_UNESCAPED_SLASHES) . "\n");
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame('public, must-revalidate, max-age=60', $first->getHeader('Cache-Control'));
        $this->assertSame('max-age=21600', $first->getHeader('Edge-Cache-Control'));
        $this->assertNotEmpty($first->getHeader('ETag'));

        $second = $this->request($page, ['If-None-Match' => $first->getHeader('ETag')]);
        $this->assertSame(304, $second->getStatusCode());
        $this->assertSame('', (string) $second->getBody());
        $this->assertSame($first->getHeader('Cache-Control'), $second->getHeader('Cache-Control'));
        $this->assertSame($first->getHeader('Edge-Cache-Control'), $second->getHeader('Edge-Cache-Control'));
        $this->assertSame($first->getHeader('ETag'), $second->getHeader('ETag'));
        $tags = explode(',', (string) $second->getHeader('Cache-Tag'));
        $this->assertContains(EdgeCache::SITE_TAG, $tags);
        $this->assertContains(EdgeCache::pageTag($page->ID), $tags);
    }

    public function testTheAnswerToARevalidationOfAPrivatePageStaysBare(): void
    {
        $page = $this->publishedPage();
        // A session turns core's page private rather than no-store, so its 304 still reaches the
        // middleware.
        $session = new Session(['visitor' => 'known']);

        $first = $this->request($page, [], $session);
        $this->assertSame(200, $first->getStatusCode());
        $this->assertStringContainsString('private', (string) $first->getHeader('Cache-Control'));
        $this->assertNull($first->getHeader('Edge-Cache-Control'));

        $second = $this->request($page, ['If-None-Match' => $first->getHeader('ETag')], $session);
        $this->assertSame(304, $second->getStatusCode());
        $this->assertNull($second->getHeader('Edge-Cache-Control'));
        $this->assertNull($second->getHeader('Cache-Tag'));
        $this->assertStringContainsString('private', (string) $second->getHeader('Cache-Control'));
    }

    private function publishedPage(): Page
    {
        return Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::DRAFT);
            $page = Page::create(['Title' => 'About', 'URLSegment' => 'about']);
            $page->write();
            $page->publishSingle();

            return $page;
        });
    }

    /**
     * One request through the real stack in its real order: the module outermost, then
     * ChangeDetectionMiddleware, then HTTPCacheControlMiddleware, then the page's controller.
     *
     * @param array<string, string> $headers
     */
    private function request(Page $page, array $headers = [], ?Session $session = null): HTTPResponse
    {
        HTTPCacheControlMiddleware::reset();
        EdgeCache::singleton()->reset();

        $request = new HTTPRequest('GET', 'about');
        // Director matches the page rule and shifts the segment off before the page's controller
        // sees the request; the URL itself stays `about`.
        $request->match('$URLSegment//$Action/$ID/$OtherID', true);
        $request->setSession($session ?? new Session([]));
        foreach ($headers as $name => $value) {
            $request->addHeader($name, $value);
        }

        $controller = fn (HTTPRequest $request) => PageController::create($page)->handleRequest($request);
        $cacheControl = fn (HTTPRequest $request) => HTTPCacheControlMiddleware::singleton()->process($request, $controller);
        $changeDetection = fn (HTTPRequest $request) => (new ChangeDetectionMiddleware())->process($request, $cacheControl);

        return (new EdgeCacheMiddleware())->process($request, $changeDetection);
    }
}
