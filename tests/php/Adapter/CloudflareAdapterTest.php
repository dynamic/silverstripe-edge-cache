<?php

namespace Dynamic\EdgeCache\Tests\Adapter;

use Dynamic\EdgeCache\Adapter\CloudflareAdapter;
use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Policy\EdgePolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

class CloudflareAdapterTest extends SapphireTest
{
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('EDGECACHE_CLOUDFLARE_API_TOKEN', 'tok');
        Environment::setEnv('EDGECACHE_CLOUDFLARE_ZONE_ID', 'zone1');
        CloudflareAdapter::config()->set('max_retry_wait', 0);
        $this->history = [];
    }

    protected function tearDown(): void
    {
        Environment::setEnv('EDGECACHE_CLOUDFLARE_API_TOKEN', '');
        Environment::setEnv('EDGECACHE_CLOUDFLARE_ZONE_ID', '');
        parent::tearDown();
    }

    private function adapter(Response ...$responses): CloudflareAdapter
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return (new CloudflareAdapter())->setClient(new Client(['handler' => $stack, 'base_uri' => 'https://cf.test/']));
    }

    private function body(int $request): array
    {
        return json_decode((string) $this->history[$request]['request']->getBody(), true);
    }

    public function testEdgeHeader(): void
    {
        $headers = (new CloudflareAdapter())->edgeHeaders(new EdgePolicy(86400, 60, 3600));
        $this->assertSame(
            ['Cloudflare-CDN-Cache-Control' => 'max-age=86400, stale-while-revalidate=60, stale-if-error=3600'],
            $headers
        );

        $plain = (new CloudflareAdapter())->edgeHeaders(new EdgePolicy(300));
        $this->assertSame(['Cloudflare-CDN-Cache-Control' => 'max-age=300'], $plain);
    }

    public function testTagsAreChunkedAtOneHundred(): void
    {
        $ok = new Response(200, [], '{"success":true}');
        $adapter = $this->adapter($ok, $ok, $ok);

        $tags = array_map(fn ($i) => 't' . $i, range(1, 250));
        $this->assertTrue($adapter->purgeTags($tags));

        $this->assertCount(3, $this->history);
        $this->assertCount(100, $this->body(0)['tags']);
        $this->assertCount(50, $this->body(2)['tags']);
        $this->assertSame('/zones/zone1/purge_cache', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('Bearer tok', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testUrlsGoInFiles(): void
    {
        $adapter = $this->adapter(new Response(200, [], '{"success":true}'));
        $adapter->purgeUrls(['https://example.com/a.pdf']);

        $this->assertSame(['files' => ['https://example.com/a.pdf']], $this->body(0));
    }

    public function testEverythingPurgesTheSiteTagNotTheWholeZone(): void
    {
        $adapter = $this->adapter(new Response(200, [], '{"success":true}'));
        $adapter->purgeEverything();

        $this->assertSame(['tags' => [EdgeCache::SITE_TAG]], $this->body(0));
    }

    public function testRetriesOnRateLimit(): void
    {
        $adapter = $this->adapter(
            new Response(429, ['Retry-After' => '0'], '{}'),
            new Response(200, [], '{"success":true}')
        );

        $this->assertTrue($adapter->purgeTags(['a']));
        $this->assertCount(2, $this->history);
    }

    public function testGivesUpAfterTheConfiguredAttempts(): void
    {
        $adapter = $this->adapter(
            new Response(429, ['Retry-After' => '0'], '{}'),
            new Response(429, ['Retry-After' => '0'], '{}'),
            new Response(429, ['Retry-After' => '0'], '{}')
        );

        $this->assertFalse($adapter->purgeTags(['a']));
        $this->assertCount(3, $this->history);
    }

    public function testAnErrorStatusReturnsFalseAndDoesNotThrow(): void
    {
        $adapter = $this->adapter(new Response(403, [], '{"success":false}'));

        $this->assertFalse($adapter->purgeTags(['a']));
    }

    public function testUnconfiguredAdapterSendsNothing(): void
    {
        Environment::setEnv('EDGECACHE_CLOUDFLARE_API_TOKEN', '');
        $adapter = $this->adapter();

        $this->assertFalse($adapter->isConfigured());
        $this->assertFalse($adapter->purgeTags(['a']));
        $this->assertCount(0, $this->history);
    }

    public function testTagHeaderIsTruncatedBeforeTheLimit(): void
    {
        CloudflareAdapter::config()->set('max_tag_header_bytes', 20);
        $value = (new CloudflareAdapter())->formatTags(['aaaaaaaaaa', 'bbbbbbbbbb', 'cccccccccc']);

        $this->assertSame('aaaaaaaaaa', $value);
    }
}
