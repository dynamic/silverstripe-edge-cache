<?php

namespace Dynamic\EdgeCache\Task;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;

/**
 * `sake dev/tasks/edge-cache-purge`
 *
 *   everything=1        purge every cached page of the site
 *   tag=ec-page-12,...  purge by cache tag
 *   url=https://...     purge one or more absolute URLs (comma separated)
 *
 * Only runs in the environments the module is enabled for.
 */
class EdgeCachePurgeTask extends BuildTask
{
    private static $segment = 'edge-cache-purge';

    protected $title = 'Purge the CDN edge cache';

    protected $description = 'Purges cached pages at the CDN: everything=1, tag=a,b or url=https://...';

    public function run($request)
    {
        $edge = EdgeCache::singleton();
        if (!$edge->isEnvironmentEnabled()) {
            $this->out('Edge cache is not enabled for this environment; nothing purged.');
            return;
        }

        $queue = PurgeQueue::singleton();
        $what = [];
        if ($request->getVar('everything')) {
            $queue->addEverything();
            $what[] = 'everything';
        }
        if ($tags = $this->list($request->getVar('tag'))) {
            $queue->addTags($tags);
            $what[] = 'tags ' . implode(', ', $tags);
        }
        if ($urls = $this->list($request->getVar('url'))) {
            $queue->addUrls($urls);
            $what[] = 'urls ' . implode(', ', $urls);
        }

        if (!$what) {
            $this->out('Nothing to purge. Pass everything=1, tag=a,b or url=https://...');
            return;
        }

        $queue->flush();
        $this->out('Purge sent: ' . implode('; ', $what));
    }

    /**
     * @return string[]
     */
    private function list($value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    private function out(string $text): void
    {
        echo Director::is_cli() ? $text . "\n" : '<pre>' . htmlspecialchars($text) . '</pre>';
    }
}
