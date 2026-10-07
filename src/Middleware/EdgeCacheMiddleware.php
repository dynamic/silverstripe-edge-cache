<?php

namespace Dynamic\EdgeCache\Middleware;

use Dynamic\EdgeCache\EdgeCache;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Injector\Injectable;

/**
 * Adds the CDN's edge headers to a page response, once Silverstripe has settled the browser
 * `Cache-Control`.
 *
 * Runs outside `HTTPCacheControlMiddleware`, so it reads the final state: a session turns the page
 * private, a form with a CSRF token turns it `no-store`, an error or redirect turns it `no-store`.
 * Edge headers go out only when the controller marked the page cacheable, the result is still
 * `public`, and nothing in the response (a cookie, a non-200 status, a non-GET method) says
 * otherwise. In every other case any edge header already on the response is removed.
 */
class EdgeCacheMiddleware implements HTTPMiddleware
{
    use Injectable;

    public function process(HTTPRequest $request, callable $delegate)
    {
        $edge = EdgeCache::singleton();
        $edge->reset();

        $response = $delegate($request);

        if ($response instanceof HTTPResponse) {
            if ($this->shouldStamp($edge, $request, $response)) {
                $this->stamp($edge, $response);
            } else {
                $this->strip($edge, $response);
            }
        }

        return $response;
    }

    protected function shouldStamp(EdgeCache $edge, HTTPRequest $request, HTTPResponse $response): bool
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
        if (!preg_match('/(^|[\s,])public([\s,=]|$)/', $control)) {
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
     * Silverstripe sends cookies (the session cookie, in particular) straight to PHP rather than
     * through the response object, so look at the headers PHP has queued as well.
     */
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
