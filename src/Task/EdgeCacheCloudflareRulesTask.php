<?php

namespace Dynamic\EdgeCache\Task;

use Dynamic\EdgeCache\Cloudflare\RulesProvisioner;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * `sake tasks:edge-cache-cloudflare-rules`
 *
 *   (no options)    print the Cache Rules the zone would end up with; writes nothing
 *   --validate      have Cloudflare check them (a dry run) without writing
 *   --apply         check with a dry run, then write
 *   --no-validate   with --apply, skip the dry run
 *   --remove        remove this module's rules and keep every other rule (rollback);
 *                   with --host=, only the rules written for that host
 *   --host=...      the public host the rules are for (www.example.com)
 *
 * --validate and --apply require --host: the base URL of a local or staging site is not the host the
 * zone serves, and rules written for it would not match production traffic. Each host keeps its own
 * rules, so apex and www can both be provisioned in one zone.
 *
 * Needs the Cloudflare API permission group "Cache Settings" (dashboard: Cache Rules): Read to print
 * the plan, Write (Edit) to validate or change it. Export the token as EDGECACHE_CLOUDFLARE_API_TOKEN
 * for the run.
 *
 * A failure prints an error and exits 1 under sake, so a script running it can tell.
 */
class EdgeCacheCloudflareRulesTask extends BuildTask
{
    use ReportsTaskResults;

    protected static string $commandName = 'edge-cache-cloudflare-rules';

    protected string $title = 'Cloudflare cache rules for edge caching';

    protected static string $description = 'Shows, validates (--validate), writes (--apply) or removes (--remove) the '
        . 'Cloudflare Cache Rules the module needs.';

    public function getOptions(): array
    {
        return [
            new InputOption('host', null, InputOption::VALUE_REQUIRED, 'The public host the rules are for (www.example.com)'),
            new InputOption('validate', null, InputOption::VALUE_NONE, 'Have Cloudflare check the rules without writing'),
            new InputOption('apply', null, InputOption::VALUE_NONE, 'Check with a dry run, then write the rules'),
            new InputOption('no-validate', null, InputOption::VALUE_NONE, 'With --apply, skip the dry run'),
            new InputOption('remove', null, InputOption::VALUE_NONE, 'Remove this module\'s rules and keep every other rule'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $provisioner = RulesProvisioner::singleton();
        $host = trim((string) $input->getOption('host'));

        try {
            if ($input->getOption('remove')) {
                $removed = $provisioner->remove($host !== '' ? $host : null);
                $scope = $host !== '' ? ' for ' . $host : '';
                $this->out($output, $removed
                    ? sprintf('Removed %d rule(s) belonging to this module%s; other rules were left alone.', $removed, $scope)
                    : 'This module has no rules in the zone' . $scope . '; nothing changed.');

                return Command::SUCCESS;
            }

            $apply = (bool) $input->getOption('apply');
            $validate = (bool) $input->getOption('validate');
            if (($apply || $validate) && $host === '') {
                return $this->fail($output, 'Pass --host=www.example.com: the host the zone serves, not this site\'s '
                    . 'base URL. Rules written for the wrong host match no real traffic.');
            }
            $host = $host ?: (string) parse_url(Director::absoluteBaseURL(), PHP_URL_HOST);

            if ($apply) {
                $rules = $provisioner->apply($host, !$input->getOption('no-validate'));
            } elseif ($validate) {
                $rules = $provisioner->validate($host);
            } else {
                $rules = $provisioner->plan($host);
            }
        } catch (Throwable $e) {
            return $this->fail($output, 'Failed: ' . $e->getMessage());
        }

        $this->out($output, $this->report($rules, $host, $apply, $validate));

        return Command::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function report(array $rules, string $host, bool $apply, bool $validate): string
    {
        $mode = $apply
            ? 'Wrote the ruleset for'
            : ($validate ? 'Cloudflare accepted this ruleset (nothing written) for' : 'Dry run for');
        $lines = [$mode . ' host ' . $host . ' (' . count($rules) . ' rules in the zone' . ($apply ? ' now' : '') . '):', ''];
        foreach ($rules as $rule) {
            $lines[] = sprintf('- %s', $rule['description'] ?? $rule['ref'] ?? '(unnamed)');
            $lines[] = '    ' . ($rule['expression'] ?? '');
        }
        if (!$apply) {
            $lines[] = '';
            $lines[] = $validate
                ? 'Run again with --apply to write these.'
                : 'Nothing written, and not yet checked by Cloudflare. Add --validate to check, --apply to write.';
        }

        return implode("\n", $lines);
    }
}
