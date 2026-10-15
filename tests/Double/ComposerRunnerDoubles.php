<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Double;

use Pringuin\OpenDxpHealthBundle\Util\ComposerJsonRunner;
use Pringuin\OpenDxpHealthBundle\Util\ComposerRunnerInterface;
use Symfony\Component\Process\Process;

/**
 * Doubles for composer runs without spawning processes; for use in TestCase classes.
 */
trait ComposerRunnerDoubles
{
    /**
     * @param list<string>|null $resolvedCommand
     */
    private function createComposerJsonRunner(
        ?array $resolvedCommand,
        string $output = '',
        string $errorOutput = '',
        int $exitCode = 0,
    ): ComposerJsonRunner {
        return new ComposerJsonRunner(
            $this->createComposerRunner($resolvedCommand, $output, $errorOutput, $exitCode),
            retryDelaySeconds: 0,
        );
    }

    /**
     * @param list<string>|null $resolvedCommand
     */
    private function createComposerRunner(
        ?array $resolvedCommand,
        string $output = '',
        string $errorOutput = '',
        int $exitCode = 0,
    ): ComposerRunnerInterface {
        $runner = $this->createMock(ComposerRunnerInterface::class);
        $runner->method('resolveCommand')->willReturn($resolvedCommand);
        $runner->method('createProcess')->willReturn($this->createFinishedProcess($output, $errorOutput, $exitCode));

        return $runner;
    }

    private function createFinishedProcess(string $output, string $errorOutput = '', int $exitCode = 0): Process
    {
        $process = $this->createMock(Process::class);
        $process->method('run')->willReturn($exitCode);
        $process->method('getOutput')->willReturn($output);
        $process->method('getErrorOutput')->willReturn($errorOutput);
        $process->method('getExitCode')->willReturn($exitCode);

        return $process;
    }
}
