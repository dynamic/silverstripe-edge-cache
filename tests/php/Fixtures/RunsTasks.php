<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs a task's execute() with options given by name (a flag is `true`) and returns what it wrote.
 * The exit code is left in $exitCode.
 */
trait RunsTasks
{
    private int $exitCode = -1;

    /**
     * @param array<string, string|bool> $options
     */
    private function runBuildTask(BuildTask $task, array $options = []): string
    {
        $prefixed = [];
        foreach ($options as $name => $value) {
            $prefixed['--' . $name] = $value;
        }
        $input = new ArrayInput($prefixed, new InputDefinition($task->getOptions()));
        $buffer = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer);

        $this->exitCode = (fn () => $this->execute($input, $output))->call($task);

        return $buffer->fetch();
    }
}
