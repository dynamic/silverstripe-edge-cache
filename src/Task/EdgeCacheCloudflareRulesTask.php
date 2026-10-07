<?php

namespace Dynamic\EdgeCache\Task;

use Dynamic\EdgeCache\Cloudflare\RulesProvisioner;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use Throwable;

/**
 * `sake dev/tasks/edge-cache-cloudflare-rules`
 *
 *   (no arguments)  print the Cache Rules the zone would end up with; writes nothing
 *   validate=1      have Cloudflare check them (a dry run) without writing
 *   apply=1         check with a dry run, then write
 *   novalidate=1    with apply=1, skip the dry run
 *   remove=1        remove this module's rules and keep every other rule (rollback)
 *   host=...        override the host taken from the site's base URL
 *
 * Reading needs Zone > Cache Settings > Read; validating and writing need Cache Settings > Write.
 */
class EdgeCacheCloudflareRulesTask extends BuildTask
{
    private static $segment = 'edge-cache-cloudflare-rules';

    protected $title = 'Cloudflare cache rules for edge caching';

    protected $description = 'Shows, validates (validate=1), writes (apply=1) or removes (remove=1) the Cloudflare '
        . 'Cache Rules the module needs.';

    public function run($request)
    {
        $provisioner = RulesProvisioner::singleton();

        try {
            if ($request->getVar('remove')) {
                $removed = $provisioner->remove();
                $this->out($removed
                    ? sprintf('Removed %d rule(s) belonging to this module; other rules were left alone.', $removed)
                    : 'This module has no rules in the zone; nothing changed.');

                return;
            }

            $host = (string) ($request->getVar('host') ?: parse_url(Director::absoluteBaseURL(), PHP_URL_HOST));
            $apply = (bool) $request->getVar('apply');
            $validate = (bool) $request->getVar('validate');

            if ($apply) {
                $rules = $provisioner->apply($host, !$request->getVar('novalidate'));
            } elseif ($validate) {
                $rules = $provisioner->validate($host);
            } else {
                $rules = $provisioner->plan($host);
            }
        } catch (Throwable $e) {
            $this->out('Failed: ' . $e->getMessage());

            return;
        }

        $this->out($this->report($rules, $host, $apply, $validate));
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function report(array $rules, string $host, bool $apply, bool $validate): string
    {
        $mode = $apply ? 'Applied to' : ($validate ? 'Cloudflare accepted this ruleset (nothing written) for' : 'Dry run for');
        $lines = [$mode . ' host ' . $host . ' (' . count($rules) . ' rules in the zone):', ''];
        foreach ($rules as $rule) {
            $lines[] = sprintf('- %s', $rule['description'] ?? $rule['ref'] ?? '(unnamed)');
            $lines[] = '    ' . ($rule['expression'] ?? '');
        }
        if (!$apply) {
            $lines[] = '';
            $lines[] = $validate
                ? 'Run again with apply=1 to write these.'
                : 'Nothing written, and not yet checked by Cloudflare. Add validate=1 to check, apply=1 to write.';
        }

        return implode("\n", $lines);
    }

    private function out(string $text): void
    {
        echo Director::is_cli() ? $text . "\n" : '<pre>' . htmlspecialchars($text) . '</pre>';
    }
}
