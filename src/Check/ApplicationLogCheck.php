<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;

/**
 * Reports recent error-level entries in the application log
 * (application_logs table of the ApplicationLoggerBundle).
 */
final class ApplicationLogCheck implements HealthCheckInterface
{
    public const string NAME = 'application_log';

    private const string TABLE_NAME = 'application_logs';

    private const int RECENT_ENTRIES_LIMIT = 5;

    private const int MESSAGE_EXCERPT_LENGTH = 160;

    /**
     * @param list<string> $priorities
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly int $maxAgeHours = 24,
        private readonly array $priorities = ['emergency', 'alert', 'critical', 'error'],
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Application Log';
    }

    public function run(): CheckResult
    {
        if (!$this->connection->createSchemaManager()->tablesExist([self::TABLE_NAME])) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                sprintf(
                    'Table "%s" does not exist. Is the ApplicationLoggerBundle installed? Otherwise disable this check.',
                    self::TABLE_NAME
                ),
            );
        }

        // the application logger stores timestamps in UTC
        $threshold = new DateTimeImmutable(
            sprintf('-%d hours', $this->maxAgeHours),
            new DateTimeZone('UTC')
        );
        $parameters = [
            'threshold' => $threshold->format('Y-m-d H:i:s'),
            'priorities' => $this->priorities,
        ];
        $parameterTypes = ['priorities' => ArrayParameterType::STRING];

        $countsPerPriority = $this->connection->fetchAllKeyValue(
            'SELECT priority, COUNT(*) FROM ' . self::TABLE_NAME
            . ' WHERE timestamp >= :threshold AND priority IN (:priorities) GROUP BY priority',
            $parameters,
            $parameterTypes
        );

        if ($countsPerPriority === []) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::OK,
                sprintf(
                    'No %s entries in the application log within the last %d hour(s).',
                    implode('/', $this->priorities),
                    $this->maxAgeHours
                ),
            );
        }

        $total = array_sum(array_map(intval(...), $countsPerPriority));

        $details = [];
        foreach ($countsPerPriority as $priority => $entryCount) {
            $details[] = sprintf('%s: %d entr%s', $priority, (int) $entryCount, (int) $entryCount === 1 ? 'y' : 'ies');
        }

        $recentEntries = $this->connection->fetchAllAssociative(
            'SELECT timestamp, priority, component, message FROM ' . self::TABLE_NAME
            . ' WHERE timestamp >= :threshold AND priority IN (:priorities)'
            . ' ORDER BY timestamp DESC LIMIT ' . self::RECENT_ENTRIES_LIMIT,
            $parameters,
            $parameterTypes
        );
        foreach ($recentEntries as $entry) {
            $message = trim((string) $entry['message']);
            if (mb_strlen($message) > self::MESSAGE_EXCERPT_LENGTH) {
                $message = mb_substr($message, 0, self::MESSAGE_EXCERPT_LENGTH) . '...';
            }
            $details[] = sprintf(
                '[%s UTC] %s (%s): %s',
                (string) $entry['timestamp'],
                (string) $entry['priority'],
                (string) ($entry['component'] ?? '-'),
                $message
            );
        }

        return new CheckResult(
            $this->getName(),
            $this->getLabel(),
            HealthStatus::WARNING,
            sprintf(
                '%d error-level entr%s in the application log within the last %d hour(s).',
                $total,
                $total === 1 ? 'y' : 'ies',
                $this->maxAgeHours
            ),
            $details,
        );
    }
}
