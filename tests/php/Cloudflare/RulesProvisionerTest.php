<?php

namespace Dynamic\EdgeCache\Tests\Cloudflare;

use Dynamic\EdgeCache\Cloudflare\CacheRuleset;
use Dynamic\EdgeCache\Cloudflare\RulesProvisioner;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
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

    private function provisioner(mixed ...$responses): RulesProvisioner
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return (new RulesProvisioner())->setClient(new Client(['handler' => $stack, 'base_uri' => 'https://cf.test/']));
    }

    private function ok(array $rules = []): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'result' => ['rules' => $rules]]));
    }

    private function methods(): array
    {
        return array_map(
            fn ($entry) => $entry['request']->getMethod() . ($entry['request']->getUri()->getQuery() ? '?dry_run' : ''),
            $this->history
        );
    }

    private function foreign(): array
    {
        return [
            'id' => 'abc',
            'ref' => 'mine',
            'expression' => 'true',
            'action' => 'set_cache_settings',
            'action_parameters' => ['cache' => false],
            'version' => '2',
        ];
    }

    public function testPlanWritesNothing(): void
    {
        $rules = $this->provisioner($this->ok())->plan('example.com');

        $this->assertCount(5, $rules);
        $this->assertSame(['GET'], $this->methods());
    }

    public function testApplyPutsTheMergedRulesetBackWithForeignRulesKept(): void
    {
        $provisioner = $this->provisioner($this->ok([$this->foreign()]), $this->ok(), $this->ok());

        $provisioner->apply('example.com');

        $this->assertSame(['GET', 'PUT?dry_run', 'PUT'], $this->methods(), 'read, dry run, write');
        $put = $this->history[2]['request'];
        $this->assertSame(
            '/zones/zone1/rulesets/phases/http_request_cache_settings/entrypoint',
            $put->getUri()->getPath()
        );
        $body = json_decode((string) $put->getBody(), true);
        $this->assertCount(6, $body['rules']);
        $this->assertSame('mine', $body['rules'][0]['ref']);
        $this->assertStringStartsWith(CacheRuleset::REF_PREFIX, $body['rules'][1]['ref']);
    }

    public function testApplyReportsTheRulesTheZoneHoldsAfterTheWrite(): void
    {
        $afterWrite = [$this->foreign(), ['ref' => 'dynamic-edge-cache-example-com-pages']];

        $rules = $this->provisioner($this->ok(), $this->ok(), $this->ok($afterWrite))->apply('example.com');

        $this->assertSame($afterWrite, $rules);
    }

    public function testValidateSendsADryRunAndNeverAWrite(): void
    {
        $rules = $this->provisioner($this->ok(), $this->ok())->validate('example.com');

        $this->assertCount(5, $rules);
        $this->assertSame(['GET', 'PUT?dry_run'], $this->methods());
        $this->assertSame('dry_run=true', $this->history[1]['request']->getUri()->getQuery());
    }

    public function testApplyCanSkipTheDryRun(): void
    {
        $this->provisioner($this->ok(), $this->ok())->apply('example.com', false);

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
            $this->assertStringContainsString('nothing was written', $e->getMessage());
        }

        $this->assertSame(['GET', 'PUT?dry_run'], $this->methods(), 'no real write followed the failed check');
    }

    public function testAFailedWriteSaysCloudflareRejectedIt(): void
    {
        $provisioner = $this->provisioner(
            $this->ok(),
            new Response(403, [], json_encode(['success' => false, 'errors' => [['message' => 'forbidden']]]))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cloudflare rejected the write');
        $provisioner->apply('example.com', false);
    }

    public function testATimeoutDuringTheWriteSaysItMayHaveBeenApplied(): void
    {
        $provisioner = $this->provisioner($this->ok(), new ConnectException('timed out', new Request('PUT', 'x')));

        try {
            $provisioner->apply('example.com', false);
            $this->fail('Expected an exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('may or may not have been applied', $e->getMessage());
            $this->assertStringContainsString('no arguments', $e->getMessage());
        }
    }

    public function testATimeoutDuringTheDryRunSaysNothingWasWritten(): void
    {
        $provisioner = $this->provisioner($this->ok(), new ConnectException('timed out', new Request('PUT', 'x')));

        $this->expectExceptionMessage('nothing was written');
        $provisioner->validate('example.com');
    }

    public function testNoEntrypointYetIsAnEmptyZone(): void
    {
        $body = json_encode(
            ['success' => false, 'errors' => [['code' => 10003, 'message' => 'could not find entrypoint']]]
        );

        $this->assertSame([], $this->provisioner(new Response(404, [], $body))->current());
    }

    public function testAnyOther404IsNotTreatedAsAnEmptyZone(): void
    {
        $body = json_encode(['success' => false, 'errors' => [['code' => 7003, 'message' => 'no route']]]);

        $this->expectException(RuntimeException::class);
        $this->provisioner(new Response(404, [], $body))->current();
    }

    public function testABareNotFoundWithNoBodyIsNotAnEmptyZone(): void
    {
        $this->expectException(RuntimeException::class);
        $this->provisioner(new Response(404, [], ''))->current();
    }

    public function testASuccessWithoutARulesetIsNotAnEmptyZone(): void
    {
        foreach (
            [
            new Response(200, [], json_encode(['success' => true, 'result' => null])),
            new Response(200, [], json_encode(['success' => true])),
            new Response(200, [], json_encode(['success' => true, 'result' => ['something' => 'else']])),
            ] as $response
        ) {
            try {
                $this->provisioner($response)->current();
                $this->fail('Expected an exception');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('ruleset', $e->getMessage());
            }
        }
    }

    public function testAnEmptyRulesetThatOmitsTheRulesKeyIsEmpty(): void
    {
        $empty = new Response(200, [], json_encode(['success' => true, 'result' => ['id' => 'r1', 'kind' => 'zone']]));

        $this->assertSame([], $this->provisioner($empty)->current());
    }

    public function testAnErrorOnTheReadStopsApplyBeforeAnyWrite(): void
    {
        $denied = new Response(403, [], json_encode(['success' => false, 'errors' => [['code' => 9109]]]));
        $provisioner = $this->provisioner($denied);

        try {
            $provisioner->apply('example.com');
            $this->fail('Expected an exception');
        } catch (RuntimeException $e) {
            $this->assertSame(['GET'], $this->methods());
        }
    }

    public function testRedirectsAreNeverFollowed(): void
    {
        $this->provisioner($this->ok(), $this->ok())->apply('example.com', false);

        foreach ($this->history as $entry) {
            $this->assertFalse($entry['options']['allow_redirects']);
        }
    }

    public function testRemoveWritesBackOnlyTheOtherRules(): void
    {
        $ours = (new CacheRuleset())->rules('example.com');
        $provisioner = $this->provisioner($this->ok(array_merge([$this->foreign()], $ours)), $this->ok());

        $removed = $provisioner->remove();

        $this->assertSame(5, $removed);
        $body = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame(['mine'], array_column($body['rules'], 'ref'));
    }

    public function testRemoveForOneHostLeavesTheOthers(): void
    {
        $ruleset = new CacheRuleset();
        $existing = array_merge($ruleset->rules('example.com'), $ruleset->rules('www.example.com'));
        $provisioner = $this->provisioner($this->ok($existing), $this->ok());

        $this->assertSame(5, $provisioner->remove('example.com'));

        $body = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertCount(5, $body['rules']);
        $this->assertStringContainsString('www-example-com', $body['rules'][0]['ref']);
    }

    public function testRemoveWithNothingOfOursWritesNothing(): void
    {
        $provisioner = $this->provisioner($this->ok([$this->foreign()]));

        $this->assertSame(0, $provisioner->remove());
        $this->assertSame(['GET'], $this->methods());
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
}
