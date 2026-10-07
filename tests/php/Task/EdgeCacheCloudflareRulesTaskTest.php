<?php

namespace Dynamic\EdgeCache\Tests\Task;

use Dynamic\EdgeCache\Cloudflare\RulesProvisioner;
use Dynamic\EdgeCache\Task\EdgeCacheCloudflareRulesTask;
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

        ob_start();
        (new EdgeCacheCloudflareRulesTask())->run(new HTTPRequest('GET', '', $vars));

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

        $this->assertStringContainsString('Applied to host example.com', $out);
        $this->assertCount(3, $this->history);
    }

    public function testAFailureIsReportedNotThrown(): void
    {
        $out = $this->runTask(
            ['host' => 'example.com', 'apply' => '1'],
            $this->ok(),
            new Response(400, [], json_encode(['success' => false, 'errors' => [['message' => 'bad expression']]]))
        );

        $this->assertStringContainsString('Failed:', $out);
        $this->assertStringContainsString('bad expression', $out);
        $this->assertCount(2, $this->history, 'the real write never happened');
    }

    public function testRemoveReportsWhatItRemoved(): void
    {
        $ours = (new \Dynamic\EdgeCache\Cloudflare\CacheRuleset())->rules('example.com');
        $existing = new Response(200, [], json_encode(['success' => true, 'result' => ['rules' => $ours]]));

        $out = $this->runTask(['remove' => '1'], $existing, $this->ok());

        $this->assertStringContainsString('Removed 5 rule(s)', $out);
    }
}
