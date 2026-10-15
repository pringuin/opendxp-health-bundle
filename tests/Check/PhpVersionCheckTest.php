<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use DateInterval;
use DateTimeImmutable;
use Pringuin\OpenDxpHealthBundle\Check\PhpVersionCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The check reads the running PHP version from the PHP_*_VERSION constants, so
 * the tests steer it by overriding the EOL date of exactly that minor version.
 */
#[CoversClass(PhpVersionCheck::class)]
final class PhpVersionCheckTest extends TestCase
{
    public function testReportsOkWhileTheEndOfLifeIsFarAway(): void
    {
        $check = new PhpVersionCheck([$this->runningMinorVersion() => $this->dateInDays(400)], warningDays: 90);

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('is supported until', $result->summary);
    }

    public function testWarnsWhenTheEndOfLifeIsWithinTheWarningPeriod(): void
    {
        $check = new PhpVersionCheck([$this->runningMinorVersion() => $this->dateInDays(30)], warningDays: 90);

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('reaches end of life on', $result->summary);
        self::assertStringContainsString('in 30 day(s)', $result->summary);
    }

    public function testWarnsOnTheLastDayOfTheWarningPeriod(): void
    {
        $check = new PhpVersionCheck([$this->runningMinorVersion() => $this->dateInDays(90)], warningDays: 90);

        self::assertSame(HealthStatus::WARNING, $check->run()->status);
    }

    public function testReportsCriticalAfterTheEndOfLife(): void
    {
        $check = new PhpVersionCheck([$this->runningMinorVersion() => $this->dateInDays(-10)]);

        $result = $check->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertStringContainsString('reached end of life', $result->summary);
        self::assertStringContainsString('10 day(s) ago', $result->summary);
    }

    public function testWarnsOnTheEndOfLifeDateItself(): void
    {
        $check = new PhpVersionCheck([$this->runningMinorVersion() => $this->dateInDays(0)]);

        self::assertSame(HealthStatus::WARNING, $check->run()->status);
    }

    public function testWarnsAboutAnUnparsableEndOfLifeDate(): void
    {
        $check = new PhpVersionCheck([$this->runningMinorVersion() => 'not-a-date']);

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('is not a valid Y-m-d date', $result->summary);
    }

    public function testTheBuiltInEolTableCoversTheRunningVersion(): void
    {
        $result = (new PhpVersionCheck())->run();

        self::assertStringNotContainsString('No EOL date is known', $result->summary);
    }

    private function runningMinorVersion(): string
    {
        return sprintf('%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
    }

    private function dateInDays(int $days): string
    {
        $today = new DateTimeImmutable('today');
        $interval = new DateInterval(sprintf('P%dD', abs($days)));

        return ($days < 0 ? $today->sub($interval) : $today->add($interval))->format('Y-m-d');
    }
}
