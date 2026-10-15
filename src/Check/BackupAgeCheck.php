<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use DateTimeImmutable;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Util\ByteFormatter;

/**
 * Checks the age of the most recent backup file in a configured directory.
 * An external backup job is expected to drop its dumps there; this check only
 * confirms that a recent file exists, not that its contents are valid.
 * Without a configured directory the check does nothing.
 */
final class BackupAgeCheck implements HealthCheckInterface
{
    public const string NAME = 'backup_age';

    public function __construct(
        private readonly ?string $directory = null,
        private readonly string $filePattern = '*',
        private readonly int $warningHours = 26,
        private readonly int $criticalHours = 50,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Backup Age';
    }

    public function run(): CheckResult
    {
        if ($this->directory === null || $this->directory === '') {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::OK,
                'No backup directory configured - nothing to check.',
            );
        }

        if (!is_dir($this->directory)) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                sprintf('Backup directory "%s" does not exist or is not a directory.', $this->directory),
            );
        }

        $newestFile = $this->findNewestFile();
        if ($newestFile === null) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::CRITICAL,
                sprintf('No backup files matching "%s" found in "%s".', $this->filePattern, $this->directory),
            );
        }

        ['path' => $path, 'mtime' => $mtime, 'size' => $size] = $newestFile;

        $ageSeconds = time() - $mtime;
        $ageHours = $ageSeconds / 3600;

        $status = match (true) {
            $ageHours >= $this->criticalHours => HealthStatus::CRITICAL,
            $ageHours >= $this->warningHours => HealthStatus::WARNING,
            default => HealthStatus::OK,
        };

        $line = sprintf(
            'Newest backup: %s (%s, %s, from %s)',
            basename($path),
            $this->formatAge($ageSeconds),
            ByteFormatter::format($size),
            (new DateTimeImmutable('@' . $mtime))->setTimezone((new DateTimeImmutable())->getTimezone())->format('Y-m-d H:i')
        );

        $summary = $status === HealthStatus::OK
            ? sprintf('The most recent backup is %s old.', $this->formatAge($ageSeconds))
            : sprintf(
                'The most recent backup is %s old (threshold: %d h).',
                $this->formatAge($ageSeconds),
                $status === HealthStatus::CRITICAL ? $this->criticalHours : $this->warningHours
            );

        return new CheckResult($this->getName(), $this->getLabel(), $status, $summary, [$line]);
    }

    /**
     * @return array{path: string, mtime: int, size: int}|null
     */
    private function findNewestFile(): array|null
    {
        $pattern = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $this->filePattern;
        $matches = glob($pattern, GLOB_NOSORT);
        if ($matches === false) {
            return null;
        }

        $newest = null;
        foreach ($matches as $match) {
            if (!is_file($match)) {
                continue;
            }
            $mtime = filemtime($match);
            if ($mtime === false) {
                continue;
            }
            if ($newest === null || $mtime > $newest['mtime']) {
                $newest = ['path' => $match, 'mtime' => $mtime, 'size' => (int) (filesize($match) ?: 0)];
            }
        }

        return $newest;
    }

    private function formatAge(int $ageSeconds): string
    {
        $hours = intdiv($ageSeconds, 3600);
        if ($hours < 48) {
            return sprintf('%d h %d min', $hours, intdiv($ageSeconds % 3600, 60));
        }

        return sprintf('%d day(s)', intdiv($hours, 24));
    }
}
