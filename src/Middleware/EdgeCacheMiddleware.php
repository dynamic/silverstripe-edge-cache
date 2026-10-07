<?php

namespace Dynamic\EdgeCache\Middleware;

use Dynamic\EdgeCache\CollectionState;
use Dynamic\EdgeCache\EdgeCache;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Throwable;

/**
 * Adds the CDN's edge headers to a page response, once Silverstripe has settled the browser
 * `Cache-Control`.
 *
 * Runs outside `HTTPCacheControlMiddleware`, so it reads the final state: a session turns the page
 * private, a form with a CSRF token turns it `no-store`, an error or redirect turns it `no-store`.
 * Edge headers go out only when the controller marked the page cacheable, the result is still
 * `public`, nothing in the response (a cookie, a non-200 status, a non-GET method) says otherwise,
 * and the page carries no more tags than `max_tags` allows. In every other case any edge header
 * already on the response is removed, and a page the controller made public is made private, so a
 * public header never leaves without edge handling behind it.
 *
 * While the page renders the middleware also switches on recording of queried classes as tags
 * (EdgeCacheQueryExtension). A request made from inside another request (Director::test()) passes
 * straight through: the outer request owns the page's tags and the recording flag.
 */
class EdgeCacheMiddleware implements HTTPMiddleware
{
    use Injectable;

    public function process(HTTPRequest $request, callable $delegate)
    {
        if (CollectionState::depth() > 0) {
            return $delegate($request);
        }

        $edge = EdgeCache::singleton();
        $edge->reset();

        if (in_array($request->httpMethod(), ['GET', 'HEAD'], true) && $edge->isEnvironmentEnabled()) {
            $edge->startCollecting();
        }

        CollectionState::enter();
        try {
            $response = $delegate($request);
        } finally {
            CollectionState::leave();
            $edge->stopCollecting();
        }

        if ($response instanceof HTTPResponse) {
            if ($this->shouldStamp($edge, $request, $response)) {
                $this->stamp($edge, $response);
            } else {
                $this->strip($edge, $response);
                $this->downgrade($edge, $response);
            }
        }

        return $response;
    }

    protected function shouldStamp(EdgeCache $edge, HTTPRequest $request, HTTPResponse $response): bool
    {
        if (!$this->isEligible($edge, $request, $response)) {
            return false;
        }

        if ($edge->isTagOverflow()) {
            $this->warnOverflow($edge, $request);

            return false;
        }

        return true;
    }

    /**
     * Everything that decides whether this response may go to the edge except the tag count.
     */
    protected function isEligible(EdgeCache $edge, HTTPRequest $request, HTTPResponse $response): bool
    {
        if (!$edge->isMarkedCacheable() || !$edge->isEnabled()) {
            return false;
        }
        if (!in_array($request->httpMethod(), ['GET', 'HEAD'], true) || $response->getStatusCode() !== 200) {
            return false;
        }
        if ($edge->isExcludedPath($request->getURL())) {
            return false;
        }
        if ($this->hasCookies($response)) {
            return false;
        }

        $control = strtolower((string) $response->getHeader('Cache-Control'));
        if (!$this->isPublic($control)) {
            return false;
        }

        // `no-store` or `private` alongside `public` is a contradiction; trust the stricter one.
        return !preg_match('/(^|[\s,])(no-store|private)([\s,=]|$)/', $control);
    }

    protected function stamp(EdgeCache $edge, HTTPResponse $response): void
    {
        $adapter = $edge->adapter();

        foreach ($adapter->edgeHeaders($edge->policy()) as $name => $value) {
            $response->addHeader($name, $value);
        }

        $tagHeader = $adapter->tagHeaderName();
        if ($tagHeader) {
            $response->addHeader($tagHeader, $adapter->formatTags($edge->getTags()));
        }
    }

    /**
     * A page the controller made public but that is not being handed to the edge (a cookie, too
     * many tags) must not be cached by anything: without tags it could not be purged.
     */
    protected function downgrade(EdgeCache $edge, HTTPResponse $response): void
    {
        $control = strtolower((string) $response->getHeader('Cache-Control'));
        if ($edge->isMarkedCacheable() && $this->isPublic($control)) {
            $response->addHeader('Cache-Control', 'private, must-revalidate');
        }
    }

    /**
     * Remove edge headers from a response that must not be cached, whoever added them.
     */
    protected function strip(EdgeCache $edge, HTTPResponse $response): void
    {
        $adapter = $edge->adapter();
        $names = array_keys($adapter->edgeHeaders($edge->policy()));
        if ($tagHeader = $adapter->tagHeaderName()) {
            $names[] = $tagHeader;
        }
        foreach ($names as $name) {
            $response->removeHeader($name);
        }
    }

    protected function isPublic(string $cacheControl): bool
    {
        return (bool) preg_match('/(^|[\s,])public([\s,=]|$)/', $cacheControl);
    }

    /**
     * A page over the tag limit is never edge-cached, so every hit reaches the origin. Say so, once
     * an hour per URL, with the tags that put it over. Never lets a logging problem break the page.
     */
    protected function warnOverflow(EdgeCache $edge, HTTPRequest $request): void
    {
        try {
            $key = 'overflow-' . md5($request->getURL());
            $cache = Injector::inst()->get(CacheInterface::class . '.EdgeCache');
            if ($cache->has($key)) {
                return;
            }
            $cache->set($key, 1);
        } catch (Throwable) {
            // No cache: log every time rather than not at all.
        }

        try {
            $tags = $edge->getTags();
            Injector::inst()->get(LoggerInterface::class)->warning(
                sprintf(
                    'Edge cache skipped /%s: %d tags is over the limit of %d, so the page is served from the origin. '
                    . 'Raise EdgeCache.max_tags, add a class to auto_tag_ignore, or reduce what the page lists.',
                    ltrim($request->getURL(), '/'),
                    count($tags),
                    (int) EdgeCache::config()->get('max_tags')
                ),
                ['first_tags' => array_slice($tags, 0, 20)]
            );
        } catch (Throwable) {
            // Logging must not break a page that rendered fine.
        }
    }

    /**
     * Silverstripe sends cookies (the session cookie, in particular) straight to PHP rather than
     * through the response object, so look at the headers PHP has queued as well.
     */
    protected function hasCookies(HTTPResponse $response): bool
    {
        foreach (array_keys($response->getHeaders()) as $name) {
            if (strtolower((string) $name) === 'set-cookie') {
                return true;
            }
        }

        foreach (headers_list() as $header) {
            if (stripos($header, 'set-cookie:') === 0) {
                return true;
            }
        }

        return false;
    }
}
