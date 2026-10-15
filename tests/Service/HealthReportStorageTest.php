<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Service;

use DateTimeImmutable;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthReport;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Service\HealthReportStorage;
use Pringuin\OpenDxpHealthBundle\Tests\Double\InMemorySettingsStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HealthReportStorage::class)]
final class HealthReportStorageTest extends TestCase
{
    public function testReturnsNullWhenNoReportWasStoredYet(): void
    {
        self::assertNull((new HealthReportStorage(new InMemorySettingsStorage()))->getLastReport());
    }

    public function testRestoresAPreviouslySavedReport(): void
    {
        $storage = new HealthReportStorage(new InMemorySettingsStorage());
        $report = new HealthReport(new DateTimeImmutable('2026-08-07 08:00:00'), [
            new CheckResult('email_log', 'Email Delivery', HealthStatus::WARNING, 'summary', ['detail']),
        ]);

        $storage->save($report);
        $restored = $storage->getLastReport();

        self::assertNotNull($restored);
        self::assertEquals($report->createdAt, $restored->createdAt);
        self::assertSame(HealthStatus::WARNING, $restored->getOverallStatus());
        self::assertSame(['detail'], $restored->results[0]->details);
    }

    public function testOverwritesThePreviousReport(): void
    {
        $storage = new HealthReportStorage(new InMemorySettingsStorage());

        $storage->save(new HealthReport(new DateTimeImmutable(), [
            new CheckResult('cache', 'Cache', HealthStatus::CRITICAL, 'broken'),
        ]));
        $storage->save(new HealthReport(new DateTimeImmutable(), [
            new CheckResult('cache', 'Cache', HealthStatus::OK, 'fine'),
        ]));

        self::assertSame(HealthStatus::OK, $storage->getLastReport()?->getOverallStatus());
    }

    public function testReturnsNullForACorruptedStoredReport(): void
    {
        $storage = new HealthReportStorage(new InMemorySettingsStorage(['last_report' => '{not valid json']));

        self::assertNull($storage->getLastReport());
    }

    public function testReturnsNullForAStoredReportWithAnUnknownStatus(): void
    {
        $storedReport = json_encode([
            'createdAt' => '2026-09-24T03:17:00+00:00',
            'results' => [['checkName' => 'cache', 'checkLabel' => 'Cache', 'status' => 'fatal', 'summary' => 'broken']],
        ], JSON_THROW_ON_ERROR);
        $storage = new HealthReportStorage(new InMemorySettingsStorage(['last_report' => $storedReport]));

        self::assertNull($storage->getLastReport());
    }

    public function testReturnsNullWhenTheStoredValueIsNotAnObject(): void
    {
        $storage = new HealthReportStorage(new InMemorySettingsStorage(['last_report' => '"just a string"']));

        self::assertNull($storage->getLastReport());
    }
}
