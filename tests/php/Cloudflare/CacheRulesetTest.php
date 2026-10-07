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
        $refs = array_column((new CacheRuleset())->rules('example.com'), 'ref');

        $this->assertSame(
            ['pages', 'static', 'bypass-session', 'bypass-markdown', 'bypass-bots'],
            array_map(fn ($ref) => substr($ref, strlen(CacheRuleset::REF_PREFIX)), $refs)
        );
    }

    public function testPagesRuleRespectsTheOriginAndSkipsAdmin(): void
    {
        $pages = (new CacheRuleset())->rules('example.com')[0];

        $this->assertSame(['cache' => true, 'edge_ttl' => ['mode' => 'bypass_by_default']], $pages['action_parameters']);
        $this->assertStringContainsString('not starts_with(http.request.uri.path, "/admin")', $pages['expression']);
    }

    public function testBypassRules(): void
    {
        $rules = array_column((new CacheRuleset())->rules('example.com'), null, 'ref');
        $prefix = CacheRuleset::REF_PREFIX;

        $this->assertStringContainsString('PHPSESSID', $rules[$prefix . 'bypass-session']['expression']);
        $this->assertStringContainsString('SECSESSID', $rules[$prefix . 'bypass-session']['expression']);
        $this->assertStringContainsString('text/markdown', $rules[$prefix . 'bypass-markdown']['expression']);
        $this->assertStringContainsString('cf.client.bot', $rules[$prefix . 'bypass-bots']['expression']);
        $this->assertSame(['cache' => false], $rules[$prefix . 'bypass-bots']['action_parameters']);
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
        $rules = array_column((new CacheRuleset())->rules('example.com'), null, 'ref');
        $prefix = CacheRuleset::REF_PREFIX;

        foreach (['pages', 'bypass-session', 'bypass-markdown', 'bypass-bots'] as $name) {
            $expression = $rules[$prefix . $name]['expression'];
            $this->assertStringContainsString('not starts_with(http.request.uri.path, "/_resources")', $expression, $name);
            $this->assertStringContainsString('not starts_with(http.request.uri.path, "/assets")', $expression, $name);
        }
        $this->assertStringNotContainsString('not starts_with', $rules[$prefix . 'static']['expression']);
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
}
