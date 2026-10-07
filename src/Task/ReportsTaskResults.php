<?php

namespace Dynamic\EdgeCache\Task;

use SilverStripe\Control\Director;

/**
 * Output and failure reporting for the module's tasks. A failure goes to stderr and ends a sake run
 * with exit status 1 (a 500 over HTTP), so a deploy script running a task can tell it failed.
 */
trait ReportsTaskResults
{
    protected function out(string $text): void
    {
        echo Director::is_cli() ? $text . "\n" : '<pre>' . htmlspecialchars($text) . '</pre>';
    }

    protected function fail(string $message): void
    {
        if (Director::is_cli()) {
            $this->writeError($message . "\n");
        } else {
            http_response_code(500);
            echo '<pre>' . htmlspecialchars($message) . '</pre>';
        }
        $this->terminate(1);
    }

    /**
     * Writes to stderr. A test overrides it.
     */
    protected function writeError(string $text): void
    {
        fwrite(STDERR, $text);
    }

    /**
     * Ends the process in CLI. A test overrides it.
     */
    protected function terminate(int $code): void
    {
        if (Director::is_cli()) {
            exit($code);
        }
    }
}
