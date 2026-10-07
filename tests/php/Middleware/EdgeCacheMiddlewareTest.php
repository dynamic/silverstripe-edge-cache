<?php

namespace Dynamic\EdgeCache\Tests\Middleware;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Middleware\EdgeCacheMiddleware;
use Dynamic\EdgeCache\Tests\EdgeCacheTestCase;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\Versioned\Versioned;

/**
 * The header matrix: which responses get edge headers and which never do.
 */
class EdgeCacheMiddlewareTest extends EdgeCacheTestCase
{
    private function respond(
        string $cacheControl = 'public, max-age=60, must-revalidate',
        bool $cacheable = true,
        int $status = 200,
        string $method = 'GET',
        string $url = 'about',
        array $headers = []
    ): HTTPResponse {
        $request = new HTTPRequest($method, $url);
        $response = new HTTPResponse('body', $status);
        $response->addHeader('Cache-Control', $cacheControl);
        foreach ($headers as $name => $value) {
            $response->addHeader($name, $value);
        }

        return (new EdgeCacheMiddleware())->process($request, function () use ($response, $cacheable) {
            if ($cacheable) {
                EdgeCache::singleton()->markCacheable();
                EdgeCache::singleton()->addTags([EdgeCache::pageTag(7), EdgeCache::classTag('App\\Pages\\AboutPage')]);
            }

            return $response;
        });
    }

    public function testAnonymousLivePageGetsEdgeHeadersAndTags(): void
    {
        $response = $this->respond();

        $this->assertSame('max-age=21600', $response->getHeader('Edge-Cache-Control'));
        $this->assertSame('ec-site,ec-page-7,ec-class-AboutPage', $response->getHeader('Cache-Tag'));
        $this->assertSame('public, max-age=60, must-revalidate', $response->getHeader('Cache-Control'));
    }

    public function testNotInAnEnabledEnvironment(): void
    {
        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'dev');
        $this->assertNull($this->respond()->getHeader('Edge-Cache-Control'));

        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'test');
        $this->assertNull($this->respond()->getHeader('Edge-Cache-Control'));
    }

    public function testEnvironmentListIsConfigurable(): void
    {
        Environment::setEnv('SS_ENVIRONMENT_TYPE', 'test');
        EdgeCache::config()->set('enabled_environments', ['live', 'test']);

        $this->assertNotNull($this->respond()->getHeader('Edge-Cache-Control'));
    }

    public function testSettingsToggleOff(): void
    {
        $this->setToggle(false);

        $this->assertNull($this->respond()->getHeader('Edge-Cache-Control'));
    }

    public function testDraftStageNeverGetsEdgeHeaders(): void
    {
        Versioned::set_stage(Versioned::DRAFT);

        $response = $this->respond();

        $this->assertNull($response->getHeader('Edge-Cache-Control'));
        $this->assertNull($response->getHeader('Cache-Tag'));
    }

    public function testPageTheControllerDidNotMarkCacheable(): void
    {
        $this->assertNull($this->respond(cacheable: false)->getHeader('Edge-Cache-Control'));
    }

    public function testPrivateAndNoStoreAreRespected(): void
    {
        // A session downgrades the page to private; a CSRF form downgrades it to no-store.
        $this->assertNull($this->respond('private, must-revalidate')->getHeader('Edge-Cache-Control'));
        $this->assertNull($this->respond('no-cache, no-store, must-revalidate')->getHeader('Edge-Cache-Control'));
        $this->assertNull($this->respond('public, no-store')->getHeader('Edge-Cache-Control'));
        $this->assertNull($this->respond('no-cache, must-revalidate')->getHeader('Edge-Cache-Control'));
    }

    public function testErrorsAndRedirectsAreNotCached(): void
    {
        foreach ([301, 302, 404, 500] as $status) {
            $this->assertNull($this->respond(status: $status)->getHeader('Edge-Cache-Control'), (string) $status);
        }
    }

    public function testOnlyGetAndHead(): void
    {
        $this->assertNull($this->respond(method: 'POST')->getHeader('Edge-Cache-Control'));
        $this->assertNotNull($this->respond(method: 'HEAD')->getHeader('Edge-Cache-Control'));
    }

    public function testResponseWithACookieIsNotCached(): void
    {
        $this->assertNull($this->respond(headers: ['Set-Cookie' => 'PHPSESSID=abc; path=/'])->getHeader('Edge-Cache-Control'));
    }

    public function testExcludedPaths(): void
    {
        foreach (['admin', 'admin/pages', 'Security/login', 'dev/build'] as $url) {
            $this->assertNull($this->respond(url: $url)->getHeader('Edge-Cache-Control'), $url);
        }
        // A page whose URL merely starts with the same letters is not excluded.
        $this->assertNotNull($this->respond(url: 'administration-services')->getHeader('Edge-Cache-Control'));
    }

    public function testEdgeHeadersAddedElsewhereAreRemovedFromUncacheableResponses(): void
    {
        $response = $this->respond('private, must-revalidate', headers: [
            'Edge-Cache-Control' => 'max-age=999',
            'Cache-Tag' => 'x',
        ]);

        $this->assertNull($response->getHeader('Edge-Cache-Control'));
        $this->assertNull($response->getHeader('Cache-Tag'));
    }

    public function testTagsAreSanitised(): void
    {
        $edge = EdgeCache::singleton();
        $edge->addTags(['has space', 'a,b', 'ok-1', '']);

        $this->assertSame(['ec-site', 'hasspace', 'ab', 'ok-1'], $edge->getTags());
    }

    public function testVaryIsLeftAloneWhenTheEdgeHasNoRestriction(): void
    {
        $response = $this->respond(headers: ['Vary' => 'X-Forwarded-Protocol, Accept']);

        $this->assertSame('X-Forwarded-Protocol, Accept', $response->getHeader('Vary'));
    }

    public function testVaryIsRestrictedToWhatTheEdgeAllows(): void
    {
        $this->adapter->allowedVary = ['Accept-Encoding'];

        $response = $this->respond(headers: ['Vary' => 'X-Forwarded-Protocol, Accept, accept-encoding']);

        $this->assertSame('accept-encoding', $response->getHeader('Vary'));
    }

    public function testVaryIsRemovedWhenNothingAllowedIsLeft(): void
    {
        $this->adapter->allowedVary = ['Accept-Encoding'];

        $response = $this->respond(headers: ['Vary' => 'X-Forwarded-Protocol, Accept']);

        $this->assertNull($response->getHeader('Vary'));
    }

    public function testVaryOnAResponseThatIsNotCachedIsNotTouched(): void
    {
        $this->adapter->allowedVary = ['Accept-Encoding'];

        $response = $this->respond('private, must-revalidate', headers: ['Vary' => 'X-Forwarded-Protocol, Accept']);

        $this->assertSame('X-Forwarded-Protocol, Accept', $response->getHeader('Vary'));
    }

    public function testAVaryTheEdgeCannotDropKeepsThePageOutOfTheEdge(): void
    {
        $this->adapter->allowedVary = ['Accept-Encoding'];

        foreach (['Cookie', 'Accept-Language', '*', 'X-Forwarded-Protocol, Cookie'] as $vary) {
            $response = $this->respond(headers: ['Vary' => $vary]);

            $this->assertNull($response->getHeader('Edge-Cache-Control'), $vary);
            $this->assertSame('private, must-revalidate', $response->getHeader('Cache-Control'), $vary);
            $this->assertSame($vary, $response->getHeader('Vary'), 'a response that is not cached keeps its Vary');
        }
    }

    public function testTheIgnorableVaryValuesAreConfigurable(): void
    {
        $this->adapter->allowedVary = ['Accept-Encoding'];
        EdgeCache::config()->set('vary_ignorable', ['Accept-Language']);

        $response = $this->respond(headers: ['Vary' => 'Accept-Language']);

        $this->assertNotNull($response->getHeader('Edge-Cache-Control'));
        $this->assertNull($response->getHeader('Vary'));
    }
}
