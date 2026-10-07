<?php

namespace Dynamic\EdgeCache\Tests\Cloudflare;

use Dynamic\EdgeCache\Cloudflare\CacheRuleset;
use Dynamic\EdgeCache\Cloudflare\RulesProvisioner;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

class RulesProvisionerTest extends SapphireTest
{
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('EDGECACHE_CLOUDFLARE_API_TOKEN', 'tok');
        Environment::setEnv('EDGECACHE_CLOUDFLARE_ZONE_ID', 'zone1');
        $this->history = [];
    }

    protected function tearDown(): void
    {
        Environment::setEnv('EDGECACHE_CLOUDFLARE_API_TOKEN', '');
        Environment::setEnv('EDGECACHE_CLOUDFLARE_ZONE_ID', '');
        parent::tearDown();
    }

    private function provisioner(Response ...$responses): RulesProvisioner
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return (new RulesProvisioner())->setClient(new Client(['handler' => $stack, 'base_uri' => 'https://cf.test/']));
    }

    private function ok(array $rules = []): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'result' => ['rules' => $rules]]));
    }

    public function testPlanWritesNothing(): void
    {
        $rules = $this->provisioner($this->ok())->plan('example.com');

        $this->assertCount(5, $rules);
        $this->assertCount(1, $this->history);
        $this->assertSame('GET', $this->history[0]['request']->getMethod());
    }

    public function testApplyPutsTheMergedRulesetBackWithForeignRulesKept(): void
    {
        $foreign = ['id' => 'abc', 'ref' => 'mine', 'expression' => 'true', 'action' => 'set_cache_settings',
            'action_parameters' => ['cache' => false], 'version' => '2'];
        $provisioner = $this->provisioner($this->ok([$foreign]), $this->ok(), $this->ok());

        $provisioner->apply('example.com');

        $this->assertCount(3, $this->history, 'read, dry run, write');
        $put = $this->history[2]['request'];
        $this->assertSame('PUT', $put->getMethod());
        $this->assertSame(
            '/zones/zone1/rulesets/phases/http_request_cache_settings/entrypoint',
            $put->getUri()->getPath()
        );
        $body = json_decode((string) $put->getBody(), true);
        $this->assertCount(6, $body['rules']);
        $this->assertSame('mine', $body['rules'][0]['ref']);
        $this->assertStringStartsWith(CacheRuleset::REF_PREFIX, $body['rules'][1]['ref']);
    }

    public function testAZoneWithNoRulesetYetIsEmpty(): void
    {
        $provisioner = $this->provisioner(new Response(404, [], '{"success":false}'));

        $this->assertSame([], $provisioner->current());
    }

    public function testAFailedWriteThrows(): void
    {
        $provisioner = $this->provisioner(
            $this->ok(),
            new Response(403, [], json_encode(['success' => false, 'errors' => [['message' => 'forbidden']]]))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $provisioner->apply('example.com');
    }

    public function testMissingCredentialsThrowBeforeAnyRequest(): void
    {
        Environment::setEnv('EDGECACHE_CLOUDFLARE_API_TOKEN', '');
        $provisioner = $this->provisioner();

        try {
            $provisioner->plan('example.com');
            $this->fail('Expected an exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('TOKEN', $e->getMessage());
        }
        $this->assertCount(0, $this->history);
    }

    private function methods(): array
    {
        return array_map(
            fn ($entry) => $entry['request']->getMethod() . ($entry['request']->getUri()->getQuery() ? '?dry_run' : ''),
            $this->history
        );
    }

    public function testValidateSendsADryRunAndNeverAWrite(): void
    {
        $provisioner = $this->provisioner($this->ok(), $this->ok());

        $rules = $provisioner->validate('example.com');

        $this->assertCount(5, $rules);
        $this->assertSame(['GET', 'PUT?dry_run'], $this->methods());
        $this->assertSame('dry_run=true', $this->history[1]['request']->getUri()->getQuery());
    }

    public function testApplyChecksWithADryRunBeforeWriting(): void
    {
        $provisioner = $this->provisioner($this->ok(), $this->ok(), $this->ok());

        $provisioner->apply('example.com');

        $this->assertSame(['GET', 'PUT?dry_run', 'PUT'], $this->methods());
    }

    public function testApplyCanSkipTheDryRun(): void
    {
        $provisioner = $this->provisioner($this->ok(), $this->ok());

        $provisioner->apply('example.com', false);

        $this->assertSame(['GET', 'PUT'], $this->methods());
    }

    public function testARejectedDryRunStopsBeforeAnythingIsWritten(): void
    {
        $provisioner = $this->provisioner(
            $this->ok(),
            new Response(400, [], json_encode(['success' => false, 'errors' => [['message' => 'unknown field']]]))
        );

        try {
            $provisioner->apply('example.com');
            $this->fail('Expected an exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unknown field', $e->getMessage());
        }

        $this->assertSame(['GET', 'PUT?dry_run'], $this->methods(), 'no real write followed the failed check');
    }

    public function testRemoveWritesBackOnlyTheOtherRules(): void
    {
        $foreign = ['id' => 'abc', 'ref' => 'mine', 'expression' => 'true', 'action' => 'set_cache_settings',
            'action_parameters' => ['cache' => false]];
        $ours = (new CacheRuleset())->rules('example.com');
        $provisioner = $this->provisioner($this->ok(array_merge([$foreign], $ours)), $this->ok());

        $removed = $provisioner->remove();

        $this->assertSame(5, $removed);
        $body = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame(['mine'], array_column($body['rules'], 'ref'));
    }

    public function testRemoveWithNothingOfOursWritesNothing(): void
    {
        $foreign = ['id' => 'abc', 'ref' => 'mine', 'expression' => 'true', 'action' => 'set_cache_settings',
            'action_parameters' => ['cache' => false]];
        $provisioner = $this->provisioner($this->ok([$foreign]));

        $this->assertSame(0, $provisioner->remove());
        $this->assertSame(['GET'], $this->methods());
    }
}
