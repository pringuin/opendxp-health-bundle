<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use OpenDxp\Maintenance\ExecutorInterface;
use Pringuin\OpenDxpHealthBundle\Check\MaintenanceCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaintenanceCheck::class)]
final class MaintenanceCheckTest extends TestCase
{
    public function testWarnsWhenMaintenanceHasNeverRun(): void
    {
        $result = (new MaintenanceCheck($this->createExecutor(0)))->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('has never been executed', $result->summary);
    }

    public function testReportsOkForARecentExecution(): void
    {
        $result = (new MaintenanceCheck($this->createExecutor(time() - 10 * 60), maxAgeMinutes: 60))->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('last ran 10 minute(s) ago', $result->summary);
    }

    public function testReportsOkExactlyAtTheConfiguredMaximumAge(): void
    {
        $result = (new MaintenanceCheck($this->createExecutor(time() - 60 * 60), maxAgeMinutes: 60))->run();

        self::assertSame(HealthStatus::OK, $result->status);
    }

    public function testWarnsWhenTheLastExecutionIsTooOld(): void
    {
        $result = (new MaintenanceCheck($this->createExecutor(time() - 180 * 60), maxAgeMinutes: 60))->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('last ran 180 minute(s) ago', $result->summary);
        self::assertStringContainsString('expected at most 60 minute(s)', $result->summary);
    }

    public function testIncludesTheLastExecutionTimestampInTheSummary(): void
    {
        $lastExecution = time() - 5 * 60;

        $result = (new MaintenanceCheck($this->createExecutor($lastExecution)))->run();

        self::assertStringContainsString(date('Y-m-d H:i:s T', $lastExecution), $result->summary);
    }

    private function createExecutor(int $lastExecution): ExecutorInterface
    {
        $executor = $this->createMock(ExecutorInterface::class);
        $executor->method('getLastExecution')->willReturn($lastExecution);

        return $executor;
    }
}
