<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Service;

use DateTimeImmutable;
use Pringuin\OpenDxpHealthBundle\Check\HealthCheckInterface;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthReport;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Symfony\Component\Lock\LockFactory;
use Throwable;

final class HealthCheckRunner
{
    private const string LOCK_RESOURCE = 'pringuin_opendxp_health_check_run';

    /**
     * Upper bound for a single check; the lock is refreshed before every check. It only matters
     * when a run dies without releasing the lock - the next run is possible after this time.
     */
    private const float LOCK_TTL_SECONDS = 900.0;

    /**
     * @param iterable<HealthCheckInterface> $checks
     * @param array<string, array<string, mixed>> $checksConfig
     */
    public function __construct(
        private readonly iterable $checks,
        private readonly array $checksConfig,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * Runs all enabled checks. Only one run may happen at a time across all processes (admin UI,
     * cron), because the composer checks are expensive and would otherwise pile up.
     *
     * @throws HealthCheckAlreadyRunningException when another run holds the lock
     */
    public function run(): HealthReport
    {
        $lock = $this->lockFactory->createLock(self::LOCK_RESOURCE, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            throw new HealthCheckAlreadyRunningException();
        }

        try {
            $results = [];
            foreach ($this->checks as $check) {
                if (!$this->isEnabled($check)) {
                    continue;
                }

                $lock->refresh();
                $results[] = $this->runCheck($check);
            }

            return new HealthReport(new DateTimeImmutable(), $results);
        } finally {
            $lock->release();
        }
    }

    private function runCheck(HealthCheckInterface $check): CheckResult
    {
        try {
            return $check->run();
        } catch (Throwable $exception) {
            return new CheckResult(
                $check->getName(),
                $check->getLabel(),
                HealthStatus::ERROR,
                sprintf('Check failed with %s: %s', $exception::class, $exception->getMessage()),
            );
        }
    }

    private function isEnabled(HealthCheckInterface $check): bool
    {
        return (bool) ($this->checksConfig[$check->getName()]['enabled'] ?? true);
    }
}
