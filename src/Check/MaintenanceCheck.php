<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use OpenDxp\Maintenance\ExecutorInterface;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;

/**
 * Verifies that the opendxp:maintenance command is executed regularly (cron).
 * Without it, scheduled tasks, version cleanup and other housekeeping silently
 * stop working.
 */
final class MaintenanceCheck implements HealthCheckInterface
{
    public const string NAME = 'maintenance';

    public function __construct(
        private readonly ExecutorInterface $maintenanceExecutor,
        private readonly int $maxAgeMinutes = 60,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Maintenance Heartbeat';
    }

    public function run(): CheckResult
    {
        $lastExecution = $this->maintenanceExecutor->getLastExecution();

        if ($lastExecution === 0) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                'opendxp:maintenance has never been executed or its last run is too long ago '
                . '(the heartbeat entry has expired). Make sure the maintenance cron job is set up.',
            );
        }

        $ageMinutes = (int) floor((time() - $lastExecution) / 60);
        $lastExecutionFormatted = date('Y-m-d H:i:s T', $lastExecution);

        if ($ageMinutes > $this->maxAgeMinutes) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                sprintf(
                    'opendxp:maintenance last ran %d minute(s) ago (%s), expected at most %d minute(s). '
                    . 'Check the maintenance cron job.',
                    $ageMinutes,
                    $lastExecutionFormatted,
                    $this->maxAgeMinutes
                ),
            );
        }

        return new CheckResult(
            $this->getName(),
            $this->getLabel(),
            HealthStatus::OK,
            sprintf('opendxp:maintenance last ran %d minute(s) ago (%s).', $ageMinutes, $lastExecutionFormatted),
        );
    }
}
