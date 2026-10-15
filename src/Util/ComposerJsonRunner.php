<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Util;

/**
 * Runs a composer command with JSON output and decodes the result.
 * Shared by the composer_audit and composer_outdated checks.
 */
final class ComposerJsonRunner
{
    private const string NETWORK_FAILURE_PATTERN = '/curl error \d+|could not resolve host|connection (?:reset|refused|timed out)'
        . '|operation timed out|network is unreachable|TransportException/i';

    private const string DEPRECATION_NOTICE_PATTERN = '/^(?:PHP )?(?:Deprecation Notice|Deprecated):/i';

    /**
     * @param int $retryDelaySeconds pause before the single retry of a failed run, which rides out short network hiccups
     */
    public function __construct(
        private readonly ComposerRunnerInterface $composer,
        private readonly int $retryDelaySeconds = 5,
    ) {
    }

    /**
     * @param non-empty-list<string> $arguments composer command and its options, without the output format options
     * @param string $requiredKey top-level key of the output that has to hold an array
     *
     * @return array<string, mixed>
     *
     * @throws ComposerCommandException when no composer binary is found or the output is not the expected JSON,
     *                                   also after one retry
     */
    public function run(array $arguments, string $requiredKey, int $timeout): array
    {
        $composer = $this->composer->resolveCommand();
        if ($composer === null) {
            throw new ComposerCommandException(
                'No executable composer binary found. Set the COMPOSER_BINARY environment variable or add composer to PATH.'
            );
        }

        // --no-plugins: the checks may run inside a web request, where composer plugins from vendor/
        // or the composer home must not execute code; neither audit nor outdated needs them
        $command = [...$composer, ...$arguments, '--format=json', '--no-interaction', '--no-plugins'];

        try {
            return $this->runOnce($command, $arguments[0], $requiredKey, $timeout);
        } catch (ComposerCommandException) {
            if ($this->retryDelaySeconds > 0) {
                sleep($this->retryDelaySeconds);
            }

            return $this->runOnce($command, $arguments[0], $requiredKey, $timeout);
        }
    }

    /**
     * @param list<string> $command
     *
     * @return array<string, mixed>
     *
     * @throws ComposerCommandException
     */
    private function runOnce(array $command, string $commandName, string $requiredKey, int $timeout): array
    {
        $process = $this->composer->createProcess($command, $timeout);
        $process->run();

        // composer audit exits non-zero when advisories exist, so the exit code
        // alone is not an error indicator - only unparseable output is.
        $data = $this->decodeJsonObject($process->getOutput());
        if ($data === null || !is_array($data[$requiredKey] ?? null)) {
            $errorOutput = $process->getErrorOutput();

            throw new ComposerCommandException(
                sprintf('composer %s did not return parseable JSON (exit code %d).', $commandName, $process->getExitCode() ?? -1),
                $this->extractErrorDetails($errorOutput, $commandName),
                preg_match(self::NETWORK_FAILURE_PATTERN, $errorOutput) === 1,
            );
        }

        return $data;
    }

    /**
     * Reduce composer's error output to the lines that explain the failure: PHP deprecation notices
     * of an outdated composer and the usage synopsis printed after an exception are dropped.
     *
     * @return list<string>
     */
    private function extractErrorDetails(string $errorOutput, string $commandName): array
    {
        $details = [];
        foreach (preg_split('/\R/', $errorOutput) ?: [] as $line) {
            $line = trim($line);
            if ($line === ''
                || preg_match(self::DEPRECATION_NOTICE_PATTERN, $line) === 1
                || str_starts_with($line, $commandName . ' [')
            ) {
                continue;
            }
            $details[] = $line;
        }

        return array_values(array_unique($details));
    }

    /**
     * Decode the JSON object, tolerating extra output around it.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $output): ?array
    {
        $output = trim($output);
        if ($output === '') {
            return null;
        }

        $data = json_decode($output, true);
        if (is_array($data)) {
            return $data;
        }

        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($output, $start, $end - $start + 1), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }
}
