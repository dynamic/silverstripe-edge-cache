<?php

namespace Dynamic\EdgeCache\Task;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeQueue;
use SilverStripe\Dev\BuildTask;

/**
 * `sake dev/tasks/edge-cache-purge`
 *
 *   everything=1        purge every cached page of the site
 *   tag=ec-page-12,...  purge by cache tag
 *   purge_url=https://... purge one or more absolute URLs (comma separated)
 *
 * Only runs in the environments the module is enabled for. Exits 1 when the CDN did not accept the
 * purge, so a deploy script can tell. Run `everything=1` after a deploy that changes templates, the
 * theme or anything else that changes the HTML of pages nobody edited.
 */
class EdgeCachePurgeTask extends BuildTask
{
    use ReportsTaskResults;

    private static $segment = 'edge-cache-purge';

    protected $title = 'Purge the CDN edge cache';

    protected $description = 'Purges cached pages at the CDN: everything=1, tag=a,b or purge_url=https://...';

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
        if ($urls = $this->urls($request)) {
            $queue->addUrls($urls);
            $what[] = 'urls ' . implode(', ', $urls);
        }

        if (!$what) {
            // A script that meant to purge something must not read this as success. Under sake `url=`
            // never arrives (sake overwrites it with the task's path), so the old form lands here.
            $this->fail('Nothing to purge. Pass everything=1, tag=a,b or purge_url=https://... (under sake, '
                . 'url= is replaced by the task path: use purge_url=).');

            return;
        }

        if (!$queue->flush()) {
            $this->fail('The CDN did not accept the purge: ' . implode('; ', $what) . '. Run edge-cache-status verify=1 '
                . 'to check the credentials; the error log has the detail.');

            return;
        }

        $this->out('Purge accepted: ' . implode('; ', $what));
    }

    /**
     * Absolute URLs from purge_url. sake overwrites its `url` variable with the task's path, so `url=`
     * never reaches the task under sake; it is only read over HTTP, and only when it is an absolute URL.
     *
     * @return string[]
     */
    private function urls($request): array
    {
        $urls = $this->list($request->getVar('purge_url'));
        foreach ($this->list($request->getVar('url')) as $url) {
            if (preg_match('#^https?://#i', $url)) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return string[]
     */
    private function list($value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }
}
