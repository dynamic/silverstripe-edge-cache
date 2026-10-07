<?php

namespace Dynamic\EdgeCache\Cloudflare;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * The Cloudflare Cache Rules this module needs, as the API's rule objects.
 *
 * Cloudflare does not cache HTML unless a Cache Rule makes it eligible. Rules are evaluated in
 * order and, when they conflict, the last matching rule wins, so the bypasses come after the rule
 * that makes pages eligible:
 *
 * 1. Pages: eligible, lifetime from the origin's `Cloudflare-CDN-Cache-Control` (bypass when the
 *    origin sends none, so a private page is never cached by accident).
 * 2. Static files under `/_resources/`: cached for `static_edge_ttl`.
 * 3. Bypass for a request carrying a Silverstripe session cookie, so editors see fresh pages.
 * 4. Bypass for a client asking for Markdown, which aeo serves at the page URL, since the edge
 *    cache keys on the URL alone and ignores `Vary: Accept`.
 * 5. Bypass for verified bots, so the origin still sees them (the aeo crawler log).
 */
class CacheRuleset
{
    use Configurable;
    use Injectable;

    public const REF_PREFIX = 'dynamic-edge-cache-';

    /**
     * Edge lifetime for `/_resources/`. These URLs carry a `?m=` cache-buster for CSS and JS, but
     * images do not, so keep it well under a year.
     *
     * @config
     * @var int
     */
    private static $static_edge_ttl = 604800;

    /**
     * @config
     * @var int
     */
    private static $static_browser_ttl = 86400;

    /**
     * Paths pages never cache, matched as prefixes.
     *
     * @config
     * @var string[]
     */
    private static $excluded_paths = ['/admin', '/Security', '/dev', '/_resources', '/assets'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rules(string $host): array
    {
        $host = $this->quote($host);
        $onHost = sprintf('(http.host eq "%s")', $host);

        $excluded = array_map(
            fn ($path) => sprintf('not starts_with(http.request.uri.path, "%s")', $this->quote($path)),
            (array) static::config()->get('excluded_paths')
        );

        return [
            $this->rule(
                'pages',
                'Cache HTML pages the origin marks cacheable',
                sprintf('(%s and %s)', $onHost, implode(' and ', $excluded)),
                ['cache' => true, 'edge_ttl' => ['mode' => 'bypass_by_default']]
            ),
            $this->rule(
                'static',
                'Cache theme and module static files',
                sprintf('(%s and starts_with(http.request.uri.path, "/_resources/"))', $onHost),
                [
                    'cache' => true,
                    'edge_ttl' => ['mode' => 'override_origin', 'default' => (int) static::config()->get('static_edge_ttl')],
                    'browser_ttl' => [
                        'mode' => 'override_origin',
                        'default' => (int) static::config()->get('static_browser_ttl'),
                    ],
                ]
            ),
            $this->rule(
                'bypass-session',
                'Bypass for a Silverstripe session cookie (editors and visitors with a session)',
                sprintf('(%s and (http.cookie contains "PHPSESSID" or http.cookie contains "SECSESSID"))', $onHost),
                ['cache' => false]
            ),
            $this->rule(
                'bypass-markdown',
                'Bypass when the client asks for Markdown (aeo content negotiation)',
                sprintf('(%s and any(http.request.headers["accept"][*] contains "text/markdown"))', $onHost),
                ['cache' => false]
            ),
            $this->rule(
                'bypass-bots',
                'Bypass for verified bots so the origin sees them',
                sprintf('(%s and cf.client.bot)', $onHost),
                ['cache' => false]
            ),
        ];
    }

    /**
     * Whether a rule belongs to this module.
     *
     * @param array<string, mixed> $rule
     */
    public function owns(array $rule): bool
    {
        return str_starts_with((string) ($rule['ref'] ?? ''), self::REF_PREFIX);
    }

    /**
     * Existing rules from the zone, minus this module's, with our rules after them.
     *
     * @param array<int, array<string, mixed>> $existing rules as the API returned them
     * @return array<int, array<string, mixed>>
     */
    public function merge(array $existing, string $host): array
    {
        $kept = [];
        foreach ($existing as $rule) {
            if (!$this->owns($rule)) {
                $kept[] = array_intersect_key($rule, array_flip([
                    'id', 'ref', 'expression', 'action', 'action_parameters', 'description', 'enabled',
                ]));
            }
        }

        return array_merge($kept, $this->rules($host));
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    protected function rule(string $name, string $description, string $expression, array $parameters): array
    {
        return [
            'ref' => self::REF_PREFIX . $name,
            'description' => 'dynamic/edge-cache: ' . $description,
            'expression' => $expression,
            'action' => 'set_cache_settings',
            'action_parameters' => $parameters,
            'enabled' => true,
        ];
    }

    protected function quote(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
