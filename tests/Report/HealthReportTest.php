<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Report;

use DateTimeImmutable;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthReport;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Report\InvalidReportDataException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HealthReport::class)]
#[CoversClass(CheckResult::class)]
#[CoversClass(HealthStatus::class)]
#[CoversClass(InvalidReportDataException::class)]
final class HealthReportTest extends TestCase
{
    public function testAnEmptyReportIsHealthy(): void
    {
        $report = new HealthReport(new DateTimeImmutable(), []);

        self::assertSame(HealthStatus::OK, $report->getOverallStatus());
        self::assertFalse($report->hasIssues());
        self::assertSame(0, $report->countIssues());
    }

    /**
     * @param list<HealthStatus> $statuses
     */
    #[DataProvider('overallStatusProvider')]
    public function testTheOverallStatusIsTheMostSevereOne(array $statuses, HealthStatus $expected): void
    {
        $report = new HealthReport(
            new DateTimeImmutable(),
            array_map(static fn (HealthStatus $s): CheckResult => new CheckResult('c', 'C', $s, 'summary'), $statuses)
        );

        self::assertSame($expected, $report->getOverallStatus());
    }

    /**
     * @return iterable<string, array{list<HealthStatus>, HealthStatus}>
     */
    public static function overallStatusProvider(): iterable
    {
        yield 'all ok' => [[HealthStatus::OK, HealthStatus::OK], HealthStatus::OK];
        yield 'warning wins over ok' => [[HealthStatus::OK, HealthStatus::WARNING], HealthStatus::WARNING];
        yield 'critical wins over warning' => [[HealthStatus::WARNING, HealthStatus::CRITICAL], HealthStatus::CRITICAL];
        yield 'error wins over critical' => [[HealthStatus::CRITICAL, HealthStatus::ERROR], HealthStatus::ERROR];
    }

    public function testCountsOnlyResultsWithIssues(): void
    {
        $report = new HealthReport(new DateTimeImmutable(), [
            new CheckResult('a', 'A', HealthStatus::OK, 'summary'),
            new CheckResult('b', 'B', HealthStatus::WARNING, 'summary'),
            new CheckResult('c', 'C', HealthStatus::CRITICAL, 'summary'),
        ]);

        self::assertSame(2, $report->countIssues());
        self::assertTrue($report->hasIssues());
    }

    public function testSurvivesAnArrayRoundTrip(): void
    {
        $report = new HealthReport(new DateTimeImmutable('2026-08-07 10:11:12'), [
            new CheckResult('email_log', 'Email Delivery', HealthStatus::WARNING, 'summary', ['detail one', 'detail two']),
        ]);

        $restored = HealthReport::fromArray($report->toArray());

        self::assertEquals($report->createdAt, $restored->createdAt);
        self::assertSame(HealthStatus::WARNING, $restored->getOverallStatus());
        self::assertSame('email_log', $restored->results[0]->checkName);
        self::assertSame('Email Delivery', $restored->results[0]->checkLabel);
        self::assertSame(['detail one', 'detail two'], $restored->results[0]->details);
    }

    public function testRestoresAResultWithoutDetails(): void
    {
        $result = CheckResult::fromArray([
            'checkName' => 'cache',
            'checkLabel' => 'Cache',
            'status' => 'ok',
            'summary' => 'fine',
        ]);

        self::assertSame([], $result->details);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function provideInvalidReportData(): iterable
    {
        $validResult = ['checkName' => 'cache', 'checkLabel' => 'Cache', 'status' => 'ok', 'summary' => 'fine', 'details' => []];

        yield 'missing date' => [['results' => []]];
        yield 'unparseable date' => [['createdAt' => 'yesterday-ish', 'results' => []]];
        yield 'results not a list' => [['createdAt' => '2026-09-24T03:17:00+00:00', 'results' => 'none']];
        yield 'result not an object' => [['createdAt' => '2026-09-24T03:17:00+00:00', 'results' => ['cache']]];
        yield 'unknown status' => [['createdAt' => '2026-09-24T03:17:00+00:00', 'results' => [['status' => 'fatal'] + $validResult]]];
        yield 'missing check name' => [['createdAt' => '2026-09-24T03:17:00+00:00', 'results' => [array_diff_key($validResult, ['checkName' => true])]]];
        yield 'details not a list' => [['createdAt' => '2026-09-24T03:17:00+00:00', 'results' => [['details' => 'one'] + $validResult]]];
        yield 'nested detail' => [['createdAt' => '2026-09-24T03:17:00+00:00', 'results' => [['details' => [['nested']]] + $validResult]]];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('provideInvalidReportData')]
    public function testRejectsInvalidReportData(array $data): void
    {
        $this->expectException(InvalidReportDataException::class);

        HealthReport::fromArray($data);
    }
}
