<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use Doctrine\Migrations\DependencyFactory;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;

/**
 * Reports Doctrine migrations that are registered but have not been executed
 * yet - the classic post-deployment mistake of a forgotten
 * "doctrine:migrations:migrate".
 */
final class DoctrineMigrationsCheck implements HealthCheckInterface
{
    public const string NAME = 'doctrine_migrations';

    private const int LIST_LIMIT = 20;

    public function __construct(
        private readonly DependencyFactory $dependencyFactory,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Doctrine Migrations';
    }

    public function run(): CheckResult
    {
        $statusCalculator = $this->dependencyFactory->getMigrationStatusCalculator();

        $newMigrations = $statusCalculator->getNewMigrations();
        $executedUnavailable = $statusCalculator->getExecutedUnavailableMigrations();

        if (count($newMigrations) > 0) {
            $details = [];
            foreach ($newMigrations->getItems() as $index => $migration) {
                if ($index >= self::LIST_LIMIT) {
                    $details[] = sprintf('... and %d more migration(s).', count($newMigrations) - self::LIST_LIMIT);

                    break;
                }
                $details[] = (string) $migration->getVersion();
            }

            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::CRITICAL,
                sprintf(
                    '%d pending migration(s). Run "bin/console doctrine:migrations:migrate".',
                    count($newMigrations)
                ),
                $details,
            );
        }

        if (count($executedUnavailable) > 0) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                sprintf(
                    '%d executed migration(s) are no longer available in the codebase. '
                    . 'This usually indicates a version mismatch between database and deployed code.',
                    count($executedUnavailable)
                ),
            );
        }

        return new CheckResult(
            $this->getName(),
            $this->getLabel(),
            HealthStatus::OK,
            'All migrations have been executed.',
        );
    }
}
