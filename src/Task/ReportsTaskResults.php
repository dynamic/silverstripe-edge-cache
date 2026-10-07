<?php

namespace Dynamic\EdgeCache\Task;

use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Output and failure reporting for the module's tasks. A failure is written as an error and returned
 * as a non-zero exit code, so a deploy script running the task can tell it failed (sake exits 1).
 */
trait ReportsTaskResults
{
    /**
     * Writes plain text, one line per line break. Text is escaped so a `<` in a message is not read
     * as console formatting.
     */
    protected function out(PolyOutput $output, string $text): void
    {
        $output->writeln(explode("\n", OutputFormatter::escape($text)));
    }

    /**
     * Writes the message as an error and returns the failure exit code for the task to return.
     */
    protected function fail(PolyOutput $output, string $message): int
    {
        $output->writeln('<error>' . OutputFormatter::escape($message) . '</error>');

        return Command::FAILURE;
    }
}
