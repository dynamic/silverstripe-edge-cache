<?php

namespace Dynamic\EdgeCache\Task;

use Dynamic\EdgeCache\EdgeCache;
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
 *
 * Only runs in the environments the module is enabled for. Exits 1 when the CDN did not accept the
 * purge, so a deploy script can tell. Run `--everything` after a deploy that changes templates, the
 * theme or anything else that changes the HTML of pages nobody edited.
 */
class EdgeCachePurgeTask extends BuildTask
{
    use ReportsTaskResults;

    protected static string $commandName = 'edge-cache-purge';

    protected string $title = 'Purge the CDN edge cache';

    protected static string $description = 'Purges cached pages at the CDN: --everything, --tag=a,b or --purge-url=https://...';

    public function getOptions(): array
    {
        return [
            new InputOption('everything', null, InputOption::VALUE_NONE, 'Purge every cached page of the site'),
            new InputOption('tag', null, InputOption::VALUE_REQUIRED, 'Cache tags to purge, comma separated'),
            new InputOption('purge-url', null, InputOption::VALUE_REQUIRED, 'Absolute URLs to purge, comma separated'),
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

        if (!$what) {
            // A script that meant to purge something must not read this as success.
            return $this->fail($output, 'Nothing to purge. Pass --everything, --tag=a,b or --purge-url=https://...');
        }

        if (!$queue->flush()) {
            return $this->fail($output, 'The CDN did not accept the purge: ' . implode('; ', $what)
                . '. Run edge-cache-status --verify to check the credentials; the error log has the detail.');
        }

        $this->out($output, 'Purge accepted: ' . implode('; ', $what));

        return Command::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function list(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $value)))));
    }
}
