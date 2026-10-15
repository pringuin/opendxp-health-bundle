<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Service;

use Pringuin\OpenDxpHealthBundle\Check\HealthCheckInterface;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Service\HealthCheckAlreadyRunningException;
use Pringuin\OpenDxpHealthBundle\Service\HealthCheckRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(HealthCheckRunner::class)]
#[CoversClass(HealthCheckAlreadyRunningException::class)]
final class HealthCheckRunnerTest extends TestCase
{
    public function testRunsAllChecksThatAreNotDisabled(): void
    {
        $runner = new HealthCheckRunner(
            [$this->createCheck('first'), $this->createCheck('second')],
            ['first' => ['enabled' => true], 'second' => ['enabled' => true]],
            $this->createLockFactory()
        );

        $report = $runner->run();

        self::assertCount(2, $report->results);
        self::assertSame(['first', 'second'], array_map(static fn (CheckResult $r): string => $r->checkName, $report->results));
    }

    public function testSkipsDisabledChecks(): void
    {
        $runner = new HealthCheckRunner(
            [$this->createCheck('first'), $this->createCheck('second')],
            ['first' => ['enabled' => false], 'second' => ['enabled' => true]],
            $this->createLockFactory()
        );

        $report = $runner->run();

        self::assertCount(1, $report->results);
        self::assertSame('second', $report->results[0]->checkName);
    }

    public function testTreatsChecksWithoutConfigurationAsEnabled(): void
    {
        $runner = new HealthCheckRunner([$this->createCheck('unconfigured')], [], $this->createLockFactory());

        self::assertCount(1, $runner->run()->results);
    }

    public function testConvertsAnExceptionIntoAnErrorResult(): void
    {
        $runner = new HealthCheckRunner([$this->createFailingCheck('broken', new RuntimeException('database gone'))], [], $this->createLockFactory());

        $report = $runner->run();

        self::assertSame(HealthStatus::ERROR, $report->results[0]->status);
        self::assertStringContainsString(RuntimeException::class, $report->results[0]->summary);
        self::assertStringContainsString('database gone', $report->results[0]->summary);
    }

    public function testAFailingCheckDoesNotStopTheRemainingChecks(): void
    {
        $runner = new HealthCheckRunner(
            [$this->createFailingCheck('broken', new RuntimeException('boom')), $this->createCheck('healthy')],
            [],
            $this->createLockFactory()
        );

        $report = $runner->run();

        self::assertCount(2, $report->results);
        self::assertSame(HealthStatus::OK, $report->results[1]->status);
    }

    public function testRefusesToRunWhileAnotherRunHoldsTheLock(): void
    {
        $lockFactory = $this->createLockFactory();
        $check = $this->createMock(HealthCheckInterface::class);
        $check->method('getName')->willReturn('expensive');
        $check->expects(self::never())->method('run');
        $runningLock = $lockFactory->createLock('pringuin_opendxp_health_check_run');
        $runningLock->acquire();

        $this->expectException(HealthCheckAlreadyRunningException::class);

        (new HealthCheckRunner([$check], [], $lockFactory))->run();
    }

    public function testReleasesTheLockAfterARun(): void
    {
        $lockFactory = $this->createLockFactory();
        $runner = new HealthCheckRunner([$this->createCheck('first')], [], $lockFactory);

        $runner->run();

        self::assertCount(1, $runner->run()->results);
    }

    public function testHoldsTheLockWhileTheChecksRun(): void
    {
        $lockFactory = $this->createLockFactory();
        $lockHeldDuringCheck = null;
        $check = $this->createMock(HealthCheckInterface::class);
        $check->method('getName')->willReturn('probe');
        $check->method('getLabel')->willReturn('Probe');
        $check->method('run')->willReturnCallback(function () use ($lockFactory, &$lockHeldDuringCheck): CheckResult {
            $lockHeldDuringCheck = !$lockFactory->createLock('pringuin_opendxp_health_check_run')->acquire();

            return new CheckResult('probe', 'Probe', HealthStatus::OK, 'summary');
        });

        (new HealthCheckRunner([$check], [], $lockFactory))->run();

        self::assertTrue($lockHeldDuringCheck);
    }

    private function createLockFactory(): LockFactory
    {
        return new LockFactory(new InMemoryStore());
    }

    private function createCheck(string $name, HealthStatus $status = HealthStatus::OK): HealthCheckInterface
    {
        $check = $this->createMock(HealthCheckInterface::class);
        $check->method('getName')->willReturn($name);
        $check->method('getLabel')->willReturn(ucfirst($name));
        $check->method('run')->willReturn(new CheckResult($name, ucfirst($name), $status, 'summary'));

        return $check;
    }

    private function createFailingCheck(string $name, \Throwable $exception): HealthCheckInterface
    {
        $check = $this->createMock(HealthCheckInterface::class);
        $check->method('getName')->willReturn($name);
        $check->method('getLabel')->willReturn(ucfirst($name));
        $check->method('run')->willThrowException($exception);

        return $check;
    }
}
