<?php

namespace Dynamic\EdgeCache\Tests\Task;

use Dynamic\EdgeCache\Cloudflare\RulesProvisioner;
use Dynamic\EdgeCache\Tests\Fixtures\TestableRulesTask;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

class EdgeCacheCloudflareRulesTaskTest extends SapphireTest
{
    private array $history = [];

    private TestableRulesTask $task;

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

    private function runTask(array $vars, Response ...$responses): string
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        Injector::inst()->registerService(
            (new RulesProvisioner())->setClient(new Client(['handler' => $stack, 'base_uri' => 'https://cf.test/'])),
            RulesProvisioner::class
        );

        $this->task = new TestableRulesTask();
        ob_start();
        $this->task->run(new HTTPRequest('GET', '', $vars));

        return (string) ob_get_clean();
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
        $out = $this->runTask(['host' => 'example.com', 'validate' => '1'], $this->ok(), $this->ok());

        $this->assertStringContainsString('Cloudflare accepted this ruleset (nothing written)', $out);
        $this->assertSame('dry_run=true', $this->history[1]['request']->getUri()->getQuery());
        $this->assertCount(2, $this->history);
    }

    public function testApplyWritesAfterAPassingCheck(): void
    {
        $out = $this->runTask(['host' => 'example.com', 'apply' => '1'], $this->ok(), $this->ok(), $this->ok());

        $this->assertStringContainsString('Wrote the ruleset for host example.com', $out);
        $this->assertCount(3, $this->history);
    }

    public function testAFailureGoesToStderrAndExitsNonZero(): void
    {
        $out = $this->runTask(
            ['host' => 'example.com', 'apply' => '1'],
            $this->ok(),
            new Response(400, [], json_encode(['success' => false, 'errors' => [['message' => 'bad expression']]]))
        );

        $this->assertSame('', $out, 'nothing is reported as a success');
        $this->assertStringContainsString('bad expression', $this->task->stderr);
        $this->assertStringContainsString('Failed:', $this->task->stderr);
        $this->assertSame(1, $this->task->exitCode);
        $this->assertCount(2, $this->history, 'the real write never happened');
    }

    public function testASuccessDoesNotExitNonZero(): void
    {
        $this->runTask(['host' => 'example.com', 'validate' => '1'], $this->ok(), $this->ok());

        $this->assertNull($this->task->exitCode);
        $this->assertSame('', $this->task->stderr);
    }

    public function testSwitchesGivenAsFalseAreOff(): void
    {
        foreach (['0', 'false', 'no', ''] as $value) {
            $this->history = [];
            $out = $this->runTask(['host' => 'example.com', 'apply' => $value], $this->ok());

            $this->assertStringContainsString('Dry run for', $out, "apply=$value");
            $this->assertCount(1, $this->history, "apply=$value wrote nothing");
        }
    }

    public function testValidateAndApplyRefuseWithoutAHost(): void
    {
        foreach (['validate', 'apply'] as $mode) {
            $this->history = [];
            $out = $this->runTask([$mode => '1']);

            $this->assertStringContainsString('Pass host=', $this->task->stderr, $mode);
            $this->assertSame(1, $this->task->exitCode, $mode);
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

        $out = $this->runTask(['remove' => '1'], $existing, $this->ok());

        $this->assertStringContainsString('Removed 5 rule(s)', $out);
    }

    public function testRemoveForAHostSaysWhichOne(): void
    {
        $ours = (new \Dynamic\EdgeCache\Cloudflare\CacheRuleset())->rules('example.com');
        $existing = new Response(200, [], json_encode(['success' => true, 'result' => ['rules' => $ours]]));

        $out = $this->runTask(['remove' => '1', 'host' => 'other.example.com'], $existing);

        $this->assertStringContainsString('no rules in the zone for other.example.com', $out);
    }
}
