<?php

namespace Dynamic\EdgeCache\Task;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeBacklog;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\SiteConfig\SiteConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * `sake tasks:edge-cache-status`
 *
 * Shows whether edge caching would run here and why not, and (with --verify) proves the CDN
 * credentials work by purging a tag no page carries. Exits 1 when --verify fails. Run it before
 * ticking the Settings box, and after changing credentials.
 */
class EdgeCacheStatusTask extends BuildTask
{
    use ReportsTaskResults;

    protected static string $commandName = 'edge-cache-status';

    protected string $title = 'Edge cache status';

    protected static string $description = 'Shows whether edge caching runs here; --verify checks the CDN credentials.';

    public function getOptions(): array
    {
        return [
            new InputOption(
                'verify',
                null,
                InputOption::VALUE_NONE,
                'Purge a tag no page carries to prove the CDN credentials work'
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $edge = EdgeCache::singleton();
        $adapter = $edge->adapter();

        $lines = [
            'Environment:      ' . (Environment::getEnv('SS_ENVIRONMENT_TYPE') ?: '(not set)')
                . ($edge->isEnvironmentEnabled() ? ' (edge caching and purging run here)' : ' (not enabled; runs in: '
                    . implode(', ', (array) EdgeCache::config()->get('enabled_environments')) . ')'),
            'Adapter:          ' . $adapter::class,
            'Credentials set:  ' . ($adapter->isConfigured() ? 'yes' : 'NO: pages are not edge-cached and nothing is purged'),
            'Settings switch:  ' . $this->switchState(),
            'Failed purges:    ' . $this->backlogState(),
        ];

        $failed = false;
        if ($input->getOption('verify')) {
            $result = $adapter->verify();
            $failed = !$result['ok'];
            $lines[] = 'Verify:           ' . ($failed ? 'FAILED: ' : 'ok: ') . $result['message'];
        } else {
            $lines[] = 'Verify:           not run (add --verify to purge a tag no page carries and prove the credentials)';
        }

        $this->out($output, implode("\n", $lines));

        return $failed ? $this->fail($output, 'The CDN credentials did not verify.') : Command::SUCCESS;
    }

    private function backlogState(): string
    {
        $waiting = PurgeBacklog::singleton()->peek();
        if (!$waiting) {
            return 'none waiting';
        }

        $what = $waiting['everything']
            ? 'everything'
            : count($waiting['tags']) . ' tag(s), ' . count($waiting['urls']) . ' URL(s)';

        return sprintf(
            'WAITING: %s; first failed %d min ago, %d attempt(s). Send with: sake tasks:edge-cache-purge --retry',
            $what,
            (int) round((time() - $waiting['firstFailed']) / 60),
            $waiting['attempts']
        );
    }

    private function switchState(): string
    {
        try {
            return SiteConfig::current_site_config()->EdgeCacheEnabled
                ? 'ticked'
                : 'not ticked (Settings > Caching)';
        } catch (Throwable) {
            return 'unknown (run dev/build)';
        }
    }
}
