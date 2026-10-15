<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;

/**
 * Checks that the configured directories (var/ subdirectories and the local
 * asset storage by default) are writable by the web/CLI user. After a
 * deployment run as the wrong user these often break, which then only surfaces
 * as runtime errors when OpenDXP tries to write caches, logs or assets.
 * Relative paths are resolved against the project directory.
 */
final class WritablePathsCheck implements HealthCheckInterface
{
    public const string NAME = 'writable_paths';

    /**
     * @param list<string> $paths
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly array $paths = ['var/cache', 'var/log', 'var/tmp', 'public/var'],
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Writable Paths';
    }

    public function run(): CheckResult
    {
        if ($this->paths === []) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::OK,
                'No paths configured - nothing to check.',
            );
        }

        $status = HealthStatus::OK;
        $notWritableCount = 0;
        $missingCount = 0;
        $details = [];

        foreach ($this->paths as $path) {
            $resolvedPath = $this->isAbsolutePath($path)
                ? $path
                : $this->projectDir . DIRECTORY_SEPARATOR . $path;

            if (!file_exists($resolvedPath)) {
                $details[] = sprintf('%s: does not exist.', $path);
                $missingCount++;
                if ($status === HealthStatus::OK) {
                    $status = HealthStatus::WARNING;
                }

                continue;
            }

            if (!is_writable($resolvedPath)) {
                $details[] = sprintf('%s: NOT writable by the current user.', $path);
                $notWritableCount++;
                $status = HealthStatus::CRITICAL;

                continue;
            }

            $details[] = sprintf('%s: writable.', $path);
        }

        $summary = match (true) {
            $notWritableCount > 0 => sprintf(
                '%d configured path(s) are not writable%s.',
                $notWritableCount,
                $missingCount > 0 ? sprintf(', %d missing', $missingCount) : ''
            ),
            $missingCount > 0 => sprintf('%d configured path(s) do not exist.', $missingCount),
            default => sprintf('All %d configured path(s) are writable.', count($this->paths)),
        };

        return new CheckResult($this->getName(), $this->getLabel(), $status, $summary, $details);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) === 1;
    }
}
