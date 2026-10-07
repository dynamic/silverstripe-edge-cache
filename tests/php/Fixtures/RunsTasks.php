<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs a task's execute() with options given by name and returns what it wrote. The options are parsed
 * from real command-line tokens, as sake does, so an option declared with the wrong mode fails here too:
 * a flag is `true` (`--name`), anything else is a value (`--name=value`). The exit code is left in $exitCode.
 */
trait RunsTasks
{
    private int $exitCode = -1;

    /**
     * @param array<string, string|bool> $options
     */
    private function runBuildTask(BuildTask $task, array $options = []): string
    {
        return $this->executeTask($task, $this->argvInput($task, $options));
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function argvInput(BuildTask $task, array $options): InputInterface
    {
        $tokens = ['sake'];
        foreach ($options as $name => $value) {
            if ($value === true) {
                $tokens[] = '--' . $name;
            } elseif ($value !== false) {
                $tokens[] = '--' . $name . '=' . $value;
            }
        }

        return new ArgvInput($tokens, new InputDefinition($task->getOptions()));
    }

    private function executeTask(BuildTask $task, InputInterface $input): string
    {
        $buffer = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer);

        $this->exitCode = (fn () => $this->execute($input, $output))->call($task);

        return $buffer->fetch();
    }
}
