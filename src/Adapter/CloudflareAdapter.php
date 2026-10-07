<?php

namespace Dynamic\EdgeCache\Adapter;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Policy\EdgePolicy;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;

/**
 * Cloudflare.
 *
 * Edge lifetime goes in `Cloudflare-CDN-Cache-Control`. Cloudflare then ignores `Cache-Control`
 * for the edge and does not forward the header, so `Cache-Control` stays the browser policy, and
 * `stale-while-revalidate` / `stale-if-error` can be used even though Silverstripe's public cache
 * state carries `must-revalidate` (which would otherwise switch stale serving off).
 *
 * Needs `EDGECACHE_CLOUDFLARE_API_TOKEN` (Zone > Cache Purge > Purge) and
 * `EDGECACHE_CLOUDFLARE_ZONE_ID`.
 */
class CloudflareAdapter implements EdgeCacheAdapter
{
    use Configurable;

    /**
     * Items per purge request. Cloudflare allows 100 on Free, Pro and Business, 500 on Enterprise.
     *
     * @config
     * @var int
     */
    private static $max_items_per_request = 100;

    /**
     * Attempts per request when Cloudflare answers 429 (Free allows 5 tag purges a minute).
     *
     * @config
     * @var int
     */
    private static $max_attempts = 3;

    /**
     * Cap on seconds to wait between attempts, whatever Retry-After says.
     *
     * @config
     * @var int
     */
    private static $max_retry_wait = 15;

    /**
     * Largest `Cache-Tag` header value in bytes. Cloudflare allows 16 KB.
     *
     * @config
     * @var int
     */
    private static $max_tag_header_bytes = 16000;

    /**
     * A tag no page carries, purged to prove the token and zone work.
     */
    private const VERIFY_TAG = 'ec-status-check';

    private ?ClientInterface $client = null;

    public function setClient(ClientInterface $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->zone() !== '';
    }

    public function edgeHeaders(EdgePolicy $policy): array
    {
        $value = 'max-age=' . $policy->getEdgeTtl();
        if ($policy->getStaleWhileRevalidate() > 0) {
            $value .= ', stale-while-revalidate=' . $policy->getStaleWhileRevalidate();
        }
        if ($policy->getStaleIfError() > 0) {
            $value .= ', stale-if-error=' . $policy->getStaleIfError();
        }

        return ['Cloudflare-CDN-Cache-Control' => $value];
    }

    public function tagHeaderName(): ?string
    {
        return 'Cache-Tag';
    }

    public function formatTags(array $tags): string
    {
        $limit = (int) static::config()->get('max_tag_header_bytes');
        $out = [];
        $length = 0;
        foreach ($tags as $tag) {
            $length += strlen($tag) + 1;
            if ($length > $limit) {
                break;
            }
            $out[] = $tag;
        }

        return implode(',', $out);
    }

    public function allowedVary(): ?array
    {
        // Cloudflare ignores Vary other than Accept-Encoding, so nothing needs removing.
        return null;
    }

    public function purgeTags(array $tags): bool
    {
        return $this->purgeChunked('tags', $tags);
    }

    public function purgeUrls(array $urls): bool
    {
        return $this->purgeChunked('files', $urls);
    }

    public function purgeEverything(): bool
    {
        // The site tag is on every page this module caches; `purge_everything` would also drop
        // the static assets, which only change with their cache-busting URL.
        return $this->purgeChunked('tags', [EdgeCache::SITE_TAG]);
    }

    protected function purgeChunked(string $key, array $items): bool
    {
        $items = array_values(array_unique($items));
        if (!$items) {
            return true;
        }
        if (!$this->isConfigured()) {
            $this->logger()->warning('Edge cache purge skipped: Cloudflare token or zone id is not set');

            return false;
        }

        $ok = true;
        $size = max(1, (int) static::config()->get('max_items_per_request'));
        foreach (array_chunk($items, $size) as $chunk) {
            $ok = $this->send([$key => $chunk]) && $ok;
        }

        return $ok;
    }

    public function verify(): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'EDGECACHE_CLOUDFLARE_API_TOKEN or EDGECACHE_CLOUDFLARE_ZONE_ID is not set.',
            ];
        }

        $result = $this->post(['tags' => [self::VERIFY_TAG]]);

        return [
            'ok' => $result['ok'],
            'message' => $result['ok']
                ? 'Cloudflare accepted a purge for this zone with this token.'
                : $result['message'],
        ];
    }

    /**
     * POST one purge request and log a failure. Never throws: a failed purge must not break a publish.
     */
    protected function send(array $body): bool
    {
        $result = $this->post($body);
        if (!$result['ok']) {
            $this->logger()->error('Edge cache purge failed: ' . $result['message']);
        }

        return $result['ok'];
    }

    /**
     * POST one purge request, retrying on 429.
     *
     * @return array{ok: bool, message: string}
     */
    protected function post(array $body): array
    {
        $attempts = max(1, (int) static::config()->get('max_attempts'));
        $uri = sprintf('zones/%s/purge_cache', $this->zone());
        $message = '';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->client()->request('POST', $uri, [
                    'headers' => ['Authorization' => 'Bearer ' . $this->token()],
                    'json' => $body,
                    'http_errors' => false,
                    'timeout' => 10,
                    'allow_redirects' => false,
                ]);
            } catch (GuzzleException $e) {
                return ['ok' => false, 'message' => 'Cloudflare could not be reached: ' . $e->getMessage()];
            }

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return ['ok' => true, 'message' => ''];
            }

            $message = sprintf('Cloudflare answered %d: %s', $status, substr((string) $response->getBody(), 0, 300));
            if ($status === 429 && $attempt < $attempts) {
                $retryAfter = $response->getHeaderLine('Retry-After');
                $wait = $retryAfter !== '' ? (int) $retryAfter : 2 ** $attempt;
                sleep(min($wait, (int) static::config()->get('max_retry_wait')));
                continue;
            }

            break;
        }

        return ['ok' => false, 'message' => $message];
    }

    protected function client(): ClientInterface
    {
        return $this->client ??= new Client(['base_uri' => 'https://api.cloudflare.com/client/v4/']);
    }

    protected function token(): string
    {
        return (string) Environment::getEnv('EDGECACHE_CLOUDFLARE_API_TOKEN');
    }

    protected function zone(): string
    {
        return (string) Environment::getEnv('EDGECACHE_CLOUDFLARE_ZONE_ID');
    }

    protected function logger(): LoggerInterface
    {
        return Injector::inst()->get(LoggerInterface::class);
    }
}
