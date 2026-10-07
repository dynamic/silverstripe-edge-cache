<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

/**
 * Task failure output captured instead of ending the test run.
 */
trait CapturesTaskFailures
{
    public string $stderr = '';

    public ?int $exitCode = null;

    protected function writeError(string $text): void
    {
        $this->stderr .= $text;
    }

    protected function terminate(int $code): void
    {
        $this->exitCode = $code;
    }
}
