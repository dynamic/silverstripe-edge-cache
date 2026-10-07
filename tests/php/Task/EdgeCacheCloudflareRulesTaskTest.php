<?php

namespace Dynamic\EdgeCache\Tests\Task;

use Dynamic\EdgeCache\Cloudflare\RulesProvisioner;
use Dynamic\EdgeCache\Task\EdgeCacheCloudflareRulesTask;
use Dynamic\EdgeCache\Tests\Fixtures\RunsTasks;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

class EdgeCacheCloudflareRulesTaskTest extends SapphireTest
{
    use RunsTasks;

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

    private function runTask(array $options, Response ...$responses): string
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        Injector::inst()->registerService(
            (new RulesProvisioner())->setClient(new Client(['handler' => $stack, 'base_uri' => 'https://cf.test/'])),
            RulesProvisioner::class
        );

        return $this->runBuildTask(new EdgeCacheCloudflareRulesTask(), $options);
    }

    private function ok(): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'result' => ['rules' => []]]));
    }

    public function testWithNoArgumentsItOnlyPrintsThePlan(): void
    {
        $out = $this->runTask(['host' => 'example.com'], $this->ok());

        $this->assertStringContainsString('Dry run for host example.com (5 rules', $out);
        $this->assertStringContainsString('not yet checked by Cloudflare', $out);
        $this->assertCount(1, $this->history);
    }

    public function testValidateReportsCloudflaresVerdictWithoutWriting(): void
    {
        $out = $this->runTask(['host' => 'example.com', 'validate' => true], $this->ok(), $this->ok());

        $this->assertStringContainsString('Cloudflare accepted this ruleset (nothing written)', $out);
        $this->assertSame('dry_run=true', $this->history[1]['request']->getUri()->getQuery());
        $this->assertCount(2, $this->history);
    }

    public function testApplyWritesAfterAPassingCheck(): void
    {
        $out = $this->runTask(['host' => 'example.com', 'apply' => true], $this->ok(), $this->ok(), $this->ok());

        $this->assertStringContainsString('Wrote the ruleset for host example.com', $out);
        $this->assertCount(3, $this->history);
    }

    public function testAFailureIsReportedAndExitsNonZero(): void
    {
        $out = $this->runTask(
            ['host' => 'example.com', 'apply' => true],
            $this->ok(),
            new Response(400, [], json_encode(['success' => false, 'errors' => [['message' => 'bad expression']]]))
        );

        $this->assertStringNotContainsString('Wrote the ruleset', $out, 'nothing is reported as a success');
        $this->assertStringContainsString('bad expression', $out);
        $this->assertStringContainsString('Failed:', $out);
        $this->assertSame(1, $this->exitCode);
        $this->assertCount(2, $this->history, 'the real write never happened');
    }

    public function testASuccessDoesNotExitNonZero(): void
    {
        $out = $this->runTask(['host' => 'example.com', 'validate' => true], $this->ok(), $this->ok());

        $this->assertSame(0, $this->exitCode);
        $this->assertStringNotContainsString('Failed', $out);
    }

    public function testValidateAndApplyRefuseWithoutAHost(): void
    {
        foreach (['validate', 'apply'] as $mode) {
            $this->history = [];
            $out = $this->runTask([$mode => true]);

            $this->assertStringContainsString('Pass --host=', $out, $mode);
            $this->assertSame(1, $this->exitCode, $mode);
            $this->assertCount(0, $this->history, 'nothing was sent to Cloudflare for ' . $mode);
        }
    }

    public function testPrintingThePlanDoesNotNeedAHost(): void
    {
        $out = $this->runTask([], $this->ok());

        $this->assertStringContainsString('Dry run for host', $out);
    }

    public function testRemoveReportsWhatItRemoved(): void
    {
        $ours = (new \Dynamic\EdgeCache\Cloudflare\CacheRuleset())->rules('example.com');
        $existing = new Response(200, [], json_encode(['success' => true, 'result' => ['rules' => $ours]]));

        $out = $this->runTask(['remove' => true], $existing, $this->ok());

        $this->assertStringContainsString('Removed 5 rule(s)', $out);
    }

    public function testRemoveForAHostSaysWhichOne(): void
    {
        $ours = (new \Dynamic\EdgeCache\Cloudflare\CacheRuleset())->rules('example.com');
        $existing = new Response(200, [], json_encode(['success' => true, 'result' => ['rules' => $ours]]));

        $out = $this->runTask(['remove' => true, 'host' => 'other.example.com'], $existing);

        $this->assertStringContainsString('no rules in the zone for other.example.com', $out);
    }

    public function testNoValidateSkipsTheDryRunWhenApplying(): void
    {
        $out = $this->runTask(['host' => 'example.com', 'apply' => true, 'no-validate' => true], $this->ok(), $this->ok());

        $this->assertStringContainsString('Wrote the ruleset for', $out);
        $this->assertCount(2, $this->history, 'one read and one write, no dry run');
        foreach ($this->history as $transaction) {
            $this->assertStringNotContainsString('dry_run', $transaction['request']->getUri()->getQuery());
        }
    }

    public function testNoValidateWithoutApplyWritesNothing(): void
    {
        $out = $this->runTask(['host' => 'example.com', 'no-validate' => true], $this->ok());

        $this->assertStringContainsString('Dry run for host example.com', $out);
        $this->assertCount(1, $this->history);
    }

    public function testAnErrorMessageWithMarkupIsShownLiterally(): void
    {
        $out = $this->runTask(
            ['host' => 'example.com', 'validate' => true],
            $this->ok(),
            new Response(400, [], json_encode(['success' => false, 'errors' => [['message' => 'unexpected <info>x</info>']]]))
        );

        $this->assertStringContainsString('unexpected <info>x', $out);
        $this->assertSame(1, $this->exitCode);
    }

    public function testItIsRegisteredUnderTheNameTheDocsUse(): void
    {
        $this->assertSame('tasks:edge-cache-cloudflare-rules', EdgeCacheCloudflareRulesTask::getName());
    }
}
