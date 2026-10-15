<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Util\ByteFormatter;

/**
 * Monitors free disk space on the filesystems holding the configured paths
 * (by default var/ and the local asset storage). Paths on the same filesystem
 * are reported as one measurement. Relative paths are resolved against the
 * project directory; paths pointing to remote storage (e.g. S3) cannot be
 * checked this way and should not be configured here.
 */
final class DiskSpaceCheck implements HealthCheckInterface
{
    public const string NAME = 'disk_space';

    /**
     * @param list<string> $paths
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly array $paths = ['var', 'public/var'],
        private readonly int $warningPercent = 15,
        private readonly int $criticalPercent = 5,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Disk Space';
    }

    public function run(): CheckResult
    {
        $status = HealthStatus::OK;
        $details = [];
        $lowSpaceCount = 0;

        ['groups' => $filesystemGroups, 'missing' => $missingPaths] = $this->groupPathsByFilesystem();

        foreach ($filesystemGroups as $group) {
            $measuredPath = $group['paths'][0];
            $pathLabel = implode(', ', $group['paths']);

            $totalBytes = @disk_total_space($measuredPath);
            $freeBytes = @disk_free_space($measuredPath);
            if ($totalBytes === false || $freeBytes === false || $totalBytes <= 0) {
                $details[] = sprintf('%s: disk space could not be determined.', $pathLabel);
                if ($status === HealthStatus::OK) {
                    $status = HealthStatus::WARNING;
                }

                continue;
            }

            $freePercent = $freeBytes / $totalBytes * 100;
            $line = sprintf(
                '%s: %s free of %s (%.1f%% free)',
                $pathLabel,
                ByteFormatter::format((int) $freeBytes),
                ByteFormatter::format((int) $totalBytes),
                $freePercent
            );

            if ($freePercent < $this->criticalPercent) {
                $line .= sprintf(' - below critical threshold of %d%%', $this->criticalPercent);
                $status = HealthStatus::CRITICAL;
                $lowSpaceCount++;
            } elseif ($freePercent < $this->warningPercent) {
                $line .= sprintf(' - below warning threshold of %d%%', $this->warningPercent);
                if ($status !== HealthStatus::CRITICAL) {
                    $status = HealthStatus::WARNING;
                }
                $lowSpaceCount++;
            }

            $details[] = $line;
        }

        foreach ($missingPaths as $missingPath) {
            $details[] = sprintf('%s: path does not exist, skipped.', $missingPath);
        }

        $summary = match (true) {
            $lowSpaceCount > 0 => sprintf('%d filesystem(s) are running low on disk space.', $lowSpaceCount),
            $status === HealthStatus::WARNING => 'Disk space could not be determined for all configured paths.',
            default => 'All monitored filesystems have sufficient free disk space.',
        };

        return new CheckResult($this->getName(), $this->getLabel(), $status, $summary, $details);
    }

    /**
     * Groups the configured paths by the filesystem they live on, so shared
     * partitions are only measured and reported once.
     *
     * @return array{groups: list<array{paths: list<string>}>, missing: list<string>}
     */
    private function groupPathsByFilesystem(): array
    {
        $missingPaths = [];
        $groups = [];

        foreach ($this->paths as $path) {
            $resolvedPath = $this->isAbsolutePath($path)
                ? $path
                : $this->projectDir . DIRECTORY_SEPARATOR . $path;

            $realPath = realpath($resolvedPath);
            if ($realPath === false || !is_dir($realPath)) {
                $missingPaths[] = $resolvedPath;

                continue;
            }

            $stat = @stat($realPath);
            $filesystemKey = is_array($stat) && ($stat['dev'] ?? 0) !== 0 ? 'dev-' . $stat['dev'] : $realPath;

            $groups[$filesystemKey]['paths'][] = $realPath;
        }

        return ['groups' => array_values($groups), 'missing' => $missingPaths];
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) === 1;
    }
}
