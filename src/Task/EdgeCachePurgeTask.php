<?php

namespace Dynamic\EdgeCache\Task;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeBacklog;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `sake tasks:edge-cache-purge`
 *
 *   --everything             purge every cached page of the site
 *   --tag=ec-page-12,...     purge by cache tag
 *   --purge-url=https://...  purge one or more absolute URLs (comma separated)
 *   --retry                  send the purges the CDN refused earlier (see edge-cache-status)
 *
 * Only runs in the environments the module is enabled for. Exits 1 when the CDN did not accept the
 * purge, so a deploy script can tell. Run `--everything` after a deploy that changes templates, the
 * theme or anything else that changes the HTML of pages nobody edited.
 *
 * A purge the CDN refuses is kept, and every send (this task included) tries what is waiting again.
 * Run `--retry` from cron to drain it between publishes, as the user that serves the site:
 *
 *     *\/5 * * * * cd /var/www/site && vendor/bin/sake tasks:edge-cache-purge --retry
 */
class EdgeCachePurgeTask extends BuildTask
{
    use ReportsTaskResults;

    protected static string $commandName = 'edge-cache-purge';

    protected string $title = 'Purge the CDN edge cache';

    protected static string $description = 'Purges cached pages at the CDN: --everything, --tag=a,b, '
        . '--purge-url=https://... or --retry';

    public function getOptions(): array
    {
        return [
            new InputOption('everything', null, InputOption::VALUE_NONE, 'Purge every cached page of the site'),
            new InputOption('tag', null, InputOption::VALUE_REQUIRED, 'Cache tags to purge, comma separated'),
            new InputOption('purge-url', null, InputOption::VALUE_REQUIRED, 'Absolute URLs to purge, comma separated'),
            new InputOption('retry', null, InputOption::VALUE_NONE, 'Send the purges the CDN refused earlier'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $edge = EdgeCache::singleton();
        if (!$edge->isEnvironmentEnabled()) {
            $this->out($output, 'Edge cache is not enabled for this environment; nothing purged.');

            return Command::SUCCESS;
        }

        $queue = PurgeQueue::singleton();
        $what = [];
        if ($input->getOption('everything')) {
            $queue->addEverything();
            $what[] = 'everything';
        }
        if ($tags = $this->list($input->getOption('tag'))) {
            $queue->addTags($tags);
            $what[] = 'tags ' . implode(', ', $tags);
        }
        if ($urls = $this->list($input->getOption('purge-url'))) {
            $queue->addUrls($urls);
            $what[] = 'urls ' . implode(', ', $urls);
        }

        $retry = (bool) $input->getOption('retry');
        if (!$what && !$retry) {
            // A script that meant to purge something must not read this as success.
            return $this->fail(
                $output,
                'Nothing to purge. Pass --everything, --tag=a,b, --purge-url=https://... or --retry'
            );
        }

        $waiting = PurgeBacklog::singleton()->peek();
        if ($retry && !$what && !$waiting) {
            $this->out($output, 'No failed purges are waiting.');

            return Command::SUCCESS;
        }
        if ($waiting) {
            $what[] = 'waiting from an earlier failure: ' . $this->describe($waiting);
        }

        if (!$queue->retry()) {
            return $this->fail($output, 'The CDN did not accept the purge: ' . implode('; ', $what)
                . '. Run edge-cache-status --verify to check the credentials; the error log has the detail.');
        }

        $this->out($output, 'Purge accepted: ' . implode('; ', $what));

        return Command::SUCCESS;
    }

    /**
     * @param array{everything: bool, tags: string[], urls: string[]} $waiting
     */
    private function describe(array $waiting): string
    {
        return $waiting['everything']
            ? 'everything'
            : count($waiting['tags']) . ' tag(s), ' . count($waiting['urls']) . ' URL(s)';
    }

    /**
     * @return string[]
     */
    private function list(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $value)))));
    }
}
