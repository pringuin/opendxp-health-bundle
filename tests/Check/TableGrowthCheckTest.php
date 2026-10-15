<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use Doctrine\DBAL\Connection;
use Pringuin\OpenDxpHealthBundle\Check\TableGrowthCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Tests\Double\InMemorySettingsStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TableGrowthCheck::class)]
final class TableGrowthCheckTest extends TestCase
{
    private const int MEGABYTE = 1024 * 1024;

    public function testReportsOkWhileAllTablesStayBelowTheirThresholds(): void
    {
        $check = new TableGrowthCheck(
            $this->createConnection(['versions' => ['size' => 100 * self::MEGABYTE, 'rows' => 5_000, 'tables' => 1]]),
            new InMemorySettingsStorage(),
            ['versions' => ['warning_mb' => 1024, 'critical_mb' => 4096]]
        );

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('All 1 monitored table sets are below their size thresholds.', $result->summary);
        self::assertStringContainsString('"versions": 100.0 MB (approx. 5,000 rows, 1 table)', $result->details[0]);
    }

    public function testWarnsWhenATableSetExceedsTheWarningThreshold(): void
    {
        $check = new TableGrowthCheck(
            $this->createConnection(['versions' => ['size' => 2000 * self::MEGABYTE, 'rows' => 1, 'tables' => 1]]),
            new InMemorySettingsStorage(),
            ['versions' => ['warning_mb' => 1024, 'critical_mb' => 4096]]
        );

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('1 monitored table set(s) exceed their size thresholds', $result->summary);
        self::assertStringContainsString('exceeds warning threshold of 1024 MB', $result->details[0]);
    }

    public function testReportsCriticalWhenATableSetExceedsTheCriticalThreshold(): void
    {
        $check = new TableGrowthCheck(
            $this->createConnection(['versions' => ['size' => 5000 * self::MEGABYTE, 'rows' => 1, 'tables' => 1]]),
            new InMemorySettingsStorage(),
            ['versions' => ['warning_mb' => 1024, 'critical_mb' => 4096]]
        );

        $result = $check->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertStringContainsString('exceeds critical threshold of 4096 MB', $result->details[0]);
    }

    public function testCriticalWinsOverWarningAcrossTableSets(): void
    {
        $check = new TableGrowthCheck(
            $this->createConnection([
                'versions' => ['size' => 2000 * self::MEGABYTE, 'rows' => 1, 'tables' => 1],
                'email_log' => ['size' => 5000 * self::MEGABYTE, 'rows' => 1, 'tables' => 1],
            ]),
            new InMemorySettingsStorage(),
            [
                'versions' => ['warning_mb' => 1024, 'critical_mb' => 4096],
                'email_log' => ['warning_mb' => 1024, 'critical_mb' => 4096],
            ]
        );

        $result = $check->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertStringContainsString('2 monitored table set(s) exceed', $result->summary);
    }

    public function testReportsPatternsWithoutMatchingTables(): void
    {
        $check = new TableGrowthCheck(
            $this->createConnection(['application_logs%' => ['size' => 0, 'rows' => 0, 'tables' => 0]]),
            new InMemorySettingsStorage(),
            ['application_logs%' => ['warning_mb' => 1024, 'critical_mb' => 4096]]
        );

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame(['"application_logs%": no matching tables.'], $result->details);
    }

    public function testStoresTheCurrentMeasurementForTheNextRun(): void
    {
        $storage = new InMemorySettingsStorage();
        $check = new TableGrowthCheck(
            $this->createConnection(['versions' => ['size' => 100 * self::MEGABYTE, 'rows' => 1, 'tables' => 1]]),
            $storage,
            ['versions' => ['warning_mb' => 1024, 'critical_mb' => 4096]]
        );

        $check->run();

        $measurement = json_decode((string) $storage->get('table_sizes'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['versions' => 100 * self::MEGABYTE], $measurement['sizes']);
        self::assertArrayHasKey('createdAt', $measurement);
    }

    public function testShowsTheGrowthSinceThePreviousMeasurement(): void
    {
        $storage = $this->createStorageWithMeasurement('2026-08-01T09:30:00+00:00', ['versions' => 100 * self::MEGABYTE]);
        $check = new TableGrowthCheck(
            $this->createConnection(['versions' => ['size' => 150 * self::MEGABYTE, 'rows' => 1, 'tables' => 1]]),
            $storage,
            ['versions' => ['warning_mb' => 1024, 'critical_mb' => 4096]]
        );

        $result = $check->run();

        self::assertStringContainsString('[+50.0 MB since 2026-08-01 09:30]', $result->details[0]);
    }

    public function testShowsAShrinkingTableSet(): void
    {
        $storage = $this->createStorageWithMeasurement('2026-08-01T09:30:00+00:00', ['versions' => 150 * self::MEGABYTE]);
        $check = new TableGrowthCheck(
            $this->createConnection(['versions' => ['size' => 100 * self::MEGABYTE, 'rows' => 1, 'tables' => 1]]),
            $storage,
            ['versions' => ['warning_mb' => 1024, 'critical_mb' => 4096]]
        );

        self::assertStringContainsString('[-50.0 MB since 2026-08-01 09:30]', $check->run()->details[0]);
    }

    public function testShowsAnUnchangedTableSet(): void
    {
        $storage = $this->createStorageWithMeasurement('2026-08-01T09:30:00+00:00', ['versions' => 100 * self::MEGABYTE]);
        $check = new TableGrowthCheck(
            $this->createConnection(['versions' => ['size' => 100 * self::MEGABYTE, 'rows' => 1, 'tables' => 1]]),
            $storage,
            ['versions' => ['warning_mb' => 1024, 'critical_mb' => 4096]]
        );

        self::assertStringContainsString('[unchanged since 2026-08-01 09:30]', $check->run()->details[0]);
    }

    public function testOmitsTheGrowthWhenThePatternIsNewInTheConfiguration(): void
    {
        $storage = $this->createStorageWithMeasurement('2026-08-01T09:30:00+00:00', ['other_table' => 1]);
        $check = new TableGrowthCheck(
            $this->createConnection(['versions' => ['size' => 100 * self::MEGABYTE, 'rows' => 1, 'tables' => 1]]),
            $storage,
            ['versions' => ['warning_mb' => 1024, 'critical_mb' => 4096]]
        );

        self::assertStringNotContainsString('since', $check->run()->details[0]);
    }

    public function testIgnoresACorruptedPreviousMeasurement(): void
    {
        $storage = new InMemorySettingsStorage(['table_sizes' => '{not valid json']);
        $check = new TableGrowthCheck(
            $this->createConnection(['versions' => ['size' => 100 * self::MEGABYTE, 'rows' => 1, 'tables' => 1]]),
            $storage,
            ['versions' => ['warning_mb' => 1024, 'critical_mb' => 4096]]
        );

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringNotContainsString('since', $result->details[0]);
    }

    public function testIgnoresAPreviousMeasurementWithoutTheExpectedKeys(): void
    {
        $storage = new InMemorySettingsStorage(['table_sizes' => '{"unexpected":true}']);
        $check = new TableGrowthCheck(
            $this->createConnection(['versions' => ['size' => 100 * self::MEGABYTE, 'rows' => 1, 'tables' => 1]]),
            $storage,
            ['versions' => ['warning_mb' => 1024, 'critical_mb' => 4096]]
        );

        self::assertStringNotContainsString('since', $check->run()->details[0]);
    }

    /**
     * @param array<string, int> $sizes
     */
    private function createStorageWithMeasurement(string $createdAt, array $sizes): InMemorySettingsStorage
    {
        return new InMemorySettingsStorage([
            'table_sizes' => json_encode(['createdAt' => $createdAt, 'sizes' => $sizes], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param array<string, array{size: int, rows: int, tables: int}> $tablesByPattern
     */
    private function createConnection(array $tablesByPattern): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('opendxp');
        $connection->method('fetchAssociative')->willReturnCallback(
            /** @param array<string, mixed> $parameters */
            static function (string $sql, array $parameters) use ($tablesByPattern): array {
                $table = $tablesByPattern[$parameters['pattern']] ?? ['size' => 0, 'rows' => 0, 'tables' => 0];

                return [
                    'table_count' => $table['tables'],
                    'total_size' => $table['size'],
                    'total_rows' => $table['rows'],
                ];
            }
        );

        return $connection;
    }
}
