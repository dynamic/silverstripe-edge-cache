<?php

namespace Dynamic\EdgeCache\Task;

use Dynamic\EdgeCache\EdgeCache;
use Dynamic\EdgeCache\Purge\PurgeBacklog;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\SiteConfig\SiteConfig;
use Throwable;

/**
 * `sake dev/tasks/edge-cache-status`
 *
 * Shows whether edge caching would run here and why not, and (with verify=1) proves the CDN
 * credentials work by purging a tag no page carries. Exits 1 when verify=1 fails. Run it before
 * ticking the Settings box, and after changing credentials.
 */
class EdgeCacheStatusTask extends BuildTask
{
    use ReportsTaskResults;

    private static $segment = 'edge-cache-status';

    protected $title = 'Edge cache status';

    protected $description = 'Shows whether edge caching runs here; verify=1 checks the CDN credentials.';

    public function run($request)
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
        if ($request->getVar('verify')) {
            $result = $adapter->verify();
            $failed = !$result['ok'];
            $lines[] = 'Verify:           ' . ($failed ? 'FAILED: ' : 'ok: ') . $result['message'];
        } else {
            $lines[] = 'Verify:           not run (add verify=1 to purge a tag no page carries and prove the credentials)';
        }

        $this->out(implode("\n", $lines));
        if ($failed) {
            $this->fail('The CDN credentials did not verify.');
        }
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
            'WAITING: %s; first failed %d min ago, %d attempt(s). Send with: sake dev/tasks/edge-cache-purge retry=1',
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
