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
     * The ref of one of this module's rules for a host. The host is part of it so two hosts in one
     * zone (apex and www) each keep their own rules.
     */
    public function ref(string $host, string $name): string
    {
        return self::REF_PREFIX . $this->slug($host) . '-' . $name;
    }

    protected function slug(string $host): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($host)), '-');
    }

    /**
     * Edge lifetime for `/_resources/`. These URLs carry a `?m=` cache-buster for CSS and JS, but
     * images do not, so keep it short and purge the prefix after a deploy that changes them.
     *
     * @config
     * @var int
     */
    private static $static_edge_ttl = 86400;

    /**
     * @config
     * @var int
     */
    private static $static_browser_ttl = 86400;

    /**
     * User-agent tokens of crawlers that bypass the cache, so the origin sees them (the aeo
     * crawler log). `cf.client.bot` would match verified bots, but Cache Rules reject it on the
     * Free plan, so crawlers are matched by user agent. An empty list leaves the rule out.
     *
     * @config
     * @var string[]
     */
    private static $bot_user_agents = [
        'Amazonbot', 'Amzn-SearchBot', 'Amzn-User', 'Applebot-Extended', 'CCBot', 'ChatGPT-User',
        'Claude-SearchBot', 'Claude-User', 'ClaudeBot', 'DuckAssistBot', 'GPTBot', 'Meta-ExternalAgent',
        'Meta-ExternalFetcher', 'Meta-WebIndexer', 'MistralAI-Index', 'MistralAI-Training',
        'MistralAI-User', 'OAI-SearchBot', 'Perplexity-User', 'PerplexityBot',
    ];

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
        $rawHost = $host;
        $host = $this->quote($host);
        $onHost = sprintf('(http.host eq "%s")', $host);

        $excluded = array_map(
            fn ($path) => sprintf('not starts_with(http.request.uri.path, "%s")', $this->quote($path)),
            (array) static::config()->get('excluded_paths')
        );
        // The pages rule and the bypasses share this condition, so a bypass never reaches the static
        // files the static rule caches, or paths the zone's other rules handle.
        $onPages = sprintf('%s and %s', $onHost, implode(' and ', $excluded));

        return array_merge([
            $this->rule(
                $rawHost,
                'pages',
                'Cache HTML pages the origin marks cacheable',
                sprintf('(%s)', $onPages),
                [
                    'cache' => true,
                    'edge_ttl' => ['mode' => 'bypass_by_default'],
                    // Without this the zone's Browser Cache TTL (4 hours by default) replaces the
                    // origin's short max-age, and browsers keep a page long after it is purged.
                    'browser_ttl' => ['mode' => 'respect_origin'],
                ]
            ),
            $this->rule(
                $rawHost,
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
                $rawHost,
                'bypass-session',
                'Bypass for a Silverstripe session cookie (editors and visitors with a session)',
                sprintf('(%s and (http.cookie contains "PHPSESSID" or http.cookie contains "SECSESSID"))', $onPages),
                ['cache' => false]
            ),
            $this->rule(
                $rawHost,
                'bypass-markdown',
                'Bypass when the client asks for Markdown (aeo content negotiation)',
                sprintf('(%s and any(http.request.headers["accept"][*] contains "text/markdown"))', $onPages),
                ['cache' => false]
            ),
        ], $this->botRule($rawHost, $onPages));
    }

    /**
     * @return array<int, array<string, mixed>> the bot bypass rule, or none when no crawlers are listed
     */
    protected function botRule(string $rawHost, string $onPages): array
    {
        $agents = array_values(array_filter((array) static::config()->get('bot_user_agents')));
        if (!$agents) {
            return [];
        }

        $match = implode(' or ', array_map(
            fn ($agent) => sprintf('http.user_agent contains "%s"', $this->quote((string) $agent)),
            $agents
        ));

        return [$this->rule(
            $rawHost,
            'bypass-bots',
            'Bypass for AI crawlers (by user agent) so the origin sees them',
            sprintf('(%s and (%s))', $onPages, $match),
            ['cache' => false]
        )];
    }

    /**
     * Whether a rule belongs to this module, and to the host when one is given.
     *
     * @param array<string, mixed> $rule
     */
    public function owns(array $rule, ?string $host = null): bool
    {
        $prefix = $host === null ? self::REF_PREFIX : self::REF_PREFIX . $this->slug($host) . '-';

        return str_starts_with((string) ($rule['ref'] ?? ''), $prefix);
    }

    /**
     * Existing rules from the zone, minus this module's rules for the host, with the host's rules
     * after them. Rules written for other hosts stay.
     *
     * @param array<int, array<string, mixed>> $existing rules as the API returned them
     * @return array<int, array<string, mixed>>
     */
    public function merge(array $existing, string $host): array
    {
        return array_merge($this->removeOwned($existing, $host), $this->rules($host));
    }

    /**
     * The zone's existing rules without this module's, for the rollback path.
     *
     * @param array<int, array<string, mixed>> $existing rules as the API returned them
     * @param string|null $host only that host's rules; null removes this module's rules for every host
     * @return array<int, array<string, mixed>>
     */
    public function removeOwned(array $existing, ?string $host = null): array
    {
        $kept = [];
        foreach ($existing as $rule) {
            if (!$this->owns($rule, $host)) {
                $kept[] = $this->writable($rule);
            }
        }

        return $kept;
    }

    /**
     * A rule as the API accepts it back: read-only fields (version, last_updated) dropped.
     *
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    protected function writable(array $rule): array
    {
        return array_intersect_key($rule, array_flip([
            'id', 'ref', 'expression', 'action', 'action_parameters', 'description', 'enabled',
        ]));
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    protected function rule(
        string $host,
        string $name,
        string $description,
        string $expression,
        array $parameters
    ): array {
        return [
            'ref' => $this->ref($host, $name),
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
