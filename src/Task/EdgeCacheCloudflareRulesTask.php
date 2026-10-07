<?php

namespace Dynamic\EdgeCache\Task;

use Dynamic\EdgeCache\Cloudflare\RulesProvisioner;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use Throwable;

/**
 * `sake dev/tasks/edge-cache-cloudflare-rules`
 *
 * Prints the Cache Rules the zone would end up with. Pass `apply=1` to write them. Pass
 * `host=www.example.com` to override the host taken from the site's base URL.
 *
 * The token needs Zone > Cache Rules > Edit, on top of Cache Purge.
 */
class EdgeCacheCloudflareRulesTask extends BuildTask
{
    private static $segment = 'edge-cache-cloudflare-rules';

    protected $title = 'Cloudflare cache rules for edge caching';

    protected $description = 'Shows (and with apply=1 writes) the Cloudflare Cache Rules the module needs.';

    public function run($request)
    {
        $host = (string) ($request->getVar('host') ?: parse_url(Director::absoluteBaseURL(), PHP_URL_HOST));
        $apply = (bool) $request->getVar('apply');

        try {
            $provisioner = RulesProvisioner::singleton();
            $rules = $apply ? $provisioner->apply($host) : $provisioner->plan($host);
        } catch (Throwable $e) {
            $this->out('Failed: ' . $e->getMessage());
            return;
        }

        $lines = [($apply ? 'Applied to' : 'Dry run for') . ' host ' . $host . ' (' . count($rules) . ' rules in the zone):', ''];
        foreach ($rules as $rule) {
            $lines[] = sprintf('- %s', $rule['description'] ?? $rule['ref'] ?? '(unnamed)');
            $lines[] = '    ' . ($rule['expression'] ?? '');
        }
        if (!$apply) {
            $lines[] = '';
            $lines[] = 'Nothing written. Run again with apply=1 to write these.';
        }

        $this->out(implode("\n", $lines));
    }

    private function out(string $text): void
    {
        echo Director::is_cli() ? $text . "\n" : '<pre>' . htmlspecialchars($text) . '</pre>';
    }
}
