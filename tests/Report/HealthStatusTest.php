<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Report;

use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HealthStatus::class)]
final class HealthStatusTest extends TestCase
{
    #[DataProvider('thresholdCases')]
    public function testIsAtLeastComparesBySeverity(
        HealthStatus $status,
        HealthStatus $threshold,
        bool $expected
    ): void {
        self::assertSame($expected, $status->isAtLeast($threshold));
    }

    /**
     * @return iterable<string, array{HealthStatus, HealthStatus, bool}>
     */
    public static function thresholdCases(): iterable
    {
        yield 'ok threshold sends every status' => [HealthStatus::OK, HealthStatus::OK, true];
        yield 'warning threshold skips ok' => [HealthStatus::OK, HealthStatus::WARNING, false];
        yield 'warning threshold matches warning' => [HealthStatus::WARNING, HealthStatus::WARNING, true];
        yield 'critical threshold skips warning' => [HealthStatus::WARNING, HealthStatus::CRITICAL, false];
        yield 'critical threshold matches critical' => [HealthStatus::CRITICAL, HealthStatus::CRITICAL, true];
        yield 'critical threshold matches execution errors' => [HealthStatus::ERROR, HealthStatus::CRITICAL, true];
        yield 'error threshold skips critical' => [HealthStatus::CRITICAL, HealthStatus::ERROR, false];
    }
}
