<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use JsonException;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Storage\SettingsStorageInterface;
use Pringuin\OpenDxpHealthBundle\Util\ByteFormatter;

/**
 * Monitors the size of housekeeping-sensitive tables (versions, logs,
 * messenger queue, ...). Excessive size usually means cleanup is not running
 * (maintenance cron) or retention is configured too high.
 *
 * Config keys may contain SQL LIKE wildcards (e.g. "application_logs%" to
 * include the monthly archive tables); all matching tables are aggregated.
 * Sizes are read from information_schema and are estimates (InnoDB statistics),
 * which is sufficient for threshold alerts. The previous measurement is kept in
 * the settings store so the report can show the growth since the last run.
 */
final class TableGrowthCheck implements HealthCheckInterface
{
    public const string NAME = 'table_growth';

    private const string STORAGE_ID = 'table_sizes';

    /**
     * @param array<string, array{warning_mb: int, critical_mb: int}> $tables
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly SettingsStorageInterface $settingsStorage,
        private readonly array $tables,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Table Growth';
    }

    public function run(): CheckResult
    {
        $database = $this->connection->getDatabase();
        $previousMeasurement = $this->loadPreviousMeasurement();

        $status = HealthStatus::OK;
        $exceededCount = 0;
        $details = [];
        $currentSizes = [];

        foreach ($this->tables as $pattern => $thresholds) {
            $row = $this->connection->fetchAssociative(
                'SELECT COUNT(*) AS table_count,
                        COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS total_size,
                        COALESCE(SUM(TABLE_ROWS), 0) AS total_rows
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = :schema AND TABLE_NAME LIKE :pattern AND TABLE_TYPE = \'BASE TABLE\'',
                ['schema' => $database, 'pattern' => $pattern]
            );

            $tableCount = (int) ($row['table_count'] ?? 0);
            if ($tableCount === 0) {
                $details[] = sprintf('"%s": no matching tables.', $pattern);

                continue;
            }

            $sizeBytes = (int) $row['total_size'];
            $currentSizes[$pattern] = $sizeBytes;

            $line = sprintf(
                '"%s": %s (approx. %s rows, %d table%s)',
                $pattern,
                ByteFormatter::format($sizeBytes),
                number_format((int) $row['total_rows']),
                $tableCount,
                $tableCount === 1 ? '' : 's'
            );

            $growthLine = $this->describeGrowth($pattern, $sizeBytes, $previousMeasurement);
            if ($growthLine !== null) {
                $line .= ' ' . $growthLine;
            }

            $sizeMb = $sizeBytes / (1024 * 1024);
            if ($sizeMb >= $thresholds['critical_mb']) {
                $line .= sprintf(' - exceeds critical threshold of %d MB', $thresholds['critical_mb']);
                $status = HealthStatus::CRITICAL;
                $exceededCount++;
            } elseif ($sizeMb >= $thresholds['warning_mb']) {
                $line .= sprintf(' - exceeds warning threshold of %d MB', $thresholds['warning_mb']);
                if ($status !== HealthStatus::CRITICAL) {
                    $status = HealthStatus::WARNING;
                }
                $exceededCount++;
            }

            $details[] = $line;
        }

        $this->saveMeasurement($currentSizes);

        if ($exceededCount > 0) {
            $summary = sprintf(
                '%d monitored table set(s) exceed their size thresholds. Check the maintenance cron '
                . 'and the retention settings (e.g. version history).',
                $exceededCount
            );
        } else {
            $summary = sprintf('All %d monitored table sets are below their size thresholds.', count($this->tables));
        }

        return new CheckResult($this->getName(), $this->getLabel(), $status, $summary, $details);
    }

    /**
     * @return array{createdAt: string, sizes: array<string, int>}|null
     */
    private function loadPreviousMeasurement(): ?array
    {
        $storedMeasurement = $this->settingsStorage->get(self::STORAGE_ID);
        if ($storedMeasurement === null) {
            return null;
        }

        try {
            $data = json_decode($storedMeasurement, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) && isset($data['createdAt'], $data['sizes']) ? $data : null;
    }

    /**
     * @param array<string, int> $currentSizes
     */
    private function saveMeasurement(array $currentSizes): void
    {
        $this->settingsStorage->set(
            self::STORAGE_ID,
            json_encode(
                [
                    'createdAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                    'sizes' => $currentSizes,
                ],
                JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * @param array{createdAt: string, sizes: array<string, int>}|null $previousMeasurement
     */
    private function describeGrowth(string $pattern, int $sizeBytes, ?array $previousMeasurement): ?string
    {
        if ($previousMeasurement === null || !isset($previousMeasurement['sizes'][$pattern])) {
            return null;
        }

        $delta = $sizeBytes - (int) $previousMeasurement['sizes'][$pattern];
        $since = (new DateTimeImmutable($previousMeasurement['createdAt']))->format('Y-m-d H:i');

        if ($delta === 0) {
            return sprintf('[unchanged since %s]', $since);
        }

        return sprintf(
            '[%s%s since %s]',
            $delta > 0 ? '+' : '-',
            ByteFormatter::format(abs($delta)),
            $since
        );
    }
}
