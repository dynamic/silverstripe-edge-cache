<?php

namespace Dynamic\EdgeCache\Tests\Cloudflare;

use Dynamic\EdgeCache\Cloudflare\CacheRuleset;
use SilverStripe\Dev\SapphireTest;

class CacheRulesetTest extends SapphireTest
{
    public function testRulesAreScopedToTheHost(): void
    {
        $rules = (new CacheRuleset())->rules('www.example.com');

        $this->assertCount(5, $rules);
        foreach ($rules as $rule) {
            $this->assertStringContainsString('http.host eq "www.example.com"', $rule['expression']);
            $this->assertSame('set_cache_settings', $rule['action']);
            $this->assertStringStartsWith(CacheRuleset::REF_PREFIX, $rule['ref']);
        }
    }

    public function testBypassesComeAfterTheRulesThatMakePagesEligible(): void
    {
        $ruleset = new CacheRuleset();
        $refs = array_column($ruleset->rules('example.com'), 'ref');

        $this->assertSame(
            array_map(
                fn ($name) => $ruleset->ref('example.com', $name),
                ['pages', 'static', 'bypass-session', 'bypass-markdown', 'bypass-bots']
            ),
            $refs
        );
        $this->assertSame('dynamic-edge-cache-example-com-pages', $refs[0]);
    }

    public function testPagesRuleRespectsTheOriginAndSkipsAdmin(): void
    {
        $pages = (new CacheRuleset())->rules('example.com')[0];

        $this->assertSame(
            [
                'cache' => true,
                'edge_ttl' => ['mode' => 'bypass_by_default'],
                'browser_ttl' => ['mode' => 'respect_origin'],
            ],
            $pages['action_parameters']
        );
        $this->assertStringContainsString('not starts_with(http.request.uri.path, "/admin")', $pages['expression']);
    }

    public function testBypassRules(): void
    {
        $ruleset = new CacheRuleset();
        $rules = array_column($ruleset->rules('example.com'), null, 'ref');
        $ref = fn ($name) => $ruleset->ref('example.com', $name);

        $this->assertStringContainsString('PHPSESSID', $rules[$ref('bypass-session')]['expression']);
        $this->assertStringContainsString('SECSESSID', $rules[$ref('bypass-session')]['expression']);
        $this->assertStringContainsString('text/markdown', $rules[$ref('bypass-markdown')]['expression']);
        $this->assertStringContainsString('http.user_agent contains "GPTBot"', $rules[$ref('bypass-bots')]['expression']);
        $this->assertStringContainsString('http.user_agent contains "ClaudeBot"', $rules[$ref('bypass-bots')]['expression']);
        $this->assertStringNotContainsString('cf.client.bot', $rules[$ref('bypass-bots')]['expression'], 'rejected on Free');
        $this->assertSame(['cache' => false], $rules[$ref('bypass-bots')]['action_parameters']);
    }

    public function testMergeKeepsForeignRulesAndReplacesItsOwn(): void
    {
        $ruleset = new CacheRuleset();
        $foreign = [
            'id' => 'abc',
            'ref' => 'someone-elses',
            'expression' => 'true',
            'action' => 'set_cache_settings',
            'action_parameters' => ['cache' => false],
            'last_updated' => '2026-01-01',
            'version' => '3',
        ];

        $first = $ruleset->merge([$foreign], 'example.com');
        $second = $ruleset->merge($first, 'example.com');

        $this->assertSame($first, $second, 'applying twice changes nothing');
        $this->assertSame('someone-elses', $first[0]['ref']);
        $this->assertArrayNotHasKey('last_updated', $first[0]);
        $this->assertCount(6, $first);
    }

    public function testHostIsEscaped(): void
    {
        $rules = (new CacheRuleset())->rules('ex"ample.com');

        $this->assertStringContainsString('ex\\"ample.com', $rules[0]['expression']);
    }

    public function testOnlyTheStaticRuleReachesStaticFiles(): void
    {
        $ruleset = new CacheRuleset();
        $rules = array_column($ruleset->rules('example.com'), null, 'ref');

        foreach (['pages', 'bypass-session', 'bypass-markdown', 'bypass-bots'] as $name) {
            $expression = $rules[$ruleset->ref('example.com', $name)]['expression'];
            $this->assertStringContainsString('not starts_with(http.request.uri.path, "/_resources")', $expression, $name);
            $this->assertStringContainsString('not starts_with(http.request.uri.path, "/assets")', $expression, $name);
        }
        $this->assertStringNotContainsString('not starts_with', $rules[$ruleset->ref('example.com', 'static')]['expression']);
    }

    public function testRemoveOwnedKeepsEveryOtherRule(): void
    {
        $ruleset = new CacheRuleset();
        $foreign = [
            'id' => 'abc',
            'ref' => 'someone-elses',
            'expression' => 'true',
            'action' => 'set_cache_settings',
            'action_parameters' => ['cache' => false],
            'version' => '3',
        ];

        $kept = $ruleset->removeOwned($ruleset->merge([$foreign], 'example.com'));

        $this->assertCount(1, $kept);
        $this->assertSame('someone-elses', $kept[0]['ref']);
        $this->assertArrayNotHasKey('version', $kept[0]);
    }

    public function testStaticFilesAreCachedForADayAtTheEdge(): void
    {
        $static = (new CacheRuleset())->rules('example.com')[1];

        $this->assertSame(86400, $static['action_parameters']['edge_ttl']['default']);
    }

    public function testThePagesRuleLeavesTheBrowserLifetimeToTheOrigin(): void
    {
        $pages = (new CacheRuleset())->rules('example.com')[0];

        $this->assertSame(['mode' => 'respect_origin'], $pages['action_parameters']['browser_ttl']);
        $this->assertSame(['mode' => 'bypass_by_default'], $pages['action_parameters']['edge_ttl']);
    }

    public function testNoCrawlersListedLeavesTheBotRuleOut(): void
    {
        CacheRuleset::config()->set('bot_user_agents', []);

        $refs = array_column((new CacheRuleset())->rules('example.com'), 'ref');

        $this->assertCount(4, $refs);
        $this->assertNotContains('dynamic-edge-cache-example-com-bypass-bots', $refs);
    }

    public function testTheCrawlerListIsConfigurableAndEscaped(): void
    {
        CacheRuleset::config()->set('bot_user_agents', ['My"Bot']);

        $rules = array_column((new CacheRuleset())->rules('example.com'), null, 'ref');

        $expression = $rules['dynamic-edge-cache-example-com-bypass-bots']['expression'];
        $this->assertStringContainsString('http.user_agent contains "My\\"Bot"', $expression);
    }

    public function testEachHostKeepsItsOwnRules(): void
    {
        $ruleset = new CacheRuleset();

        $both = $ruleset->merge($ruleset->merge([], 'example.com'), 'www.example.com');
        $again = $ruleset->merge($both, 'example.com');

        $this->assertCount(10, $both, 'the second host did not replace the first');
        $this->assertCount(10, $again, 're-applying one host replaces only its own rules');
        $this->assertCount(5, $ruleset->removeOwned($both, 'example.com'), 'removing one host leaves the other');
        $this->assertCount(0, $ruleset->removeOwned($both), 'with no host, every host');
    }
}
