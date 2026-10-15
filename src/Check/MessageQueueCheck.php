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
 * Detects messages that are stuck in the Doctrine messenger transport table:
 * messages that became available longer than the configured number of hours
 * ago and still have not been processed (usually a stopped/crashed worker).
 */
final class MessageQueueCheck implements HealthCheckInterface
{
    public const string NAME = 'message_queue';

    /**
     * @param list<string> $queueNames
     * @param list<string> $excludedQueueNames
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly int $maxAgeHours = 4,
        private readonly array $queueNames = [],
        private readonly array $excludedQueueNames = ['failed'],
        private readonly string $tableName = 'messenger_messages',
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Message Queue';
    }

    public function run(): CheckResult
    {
        if (!$this->connection->createSchemaManager()->tablesExist([$this->tableName])) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                sprintf(
                    'Messenger table "%s" does not exist. Is the Doctrine transport in use? Adjust table_name or disable this check.',
                    $this->tableName
                ),
            );
        }

        // The Doctrine transport stores timestamps in UTC
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $threshold = $nowUtc->modify(sprintf('-%d hours', $this->maxAgeHours));

        $query = sprintf(
            'SELECT queue_name, COUNT(*) AS stuck_count, MIN(created_at) AS oldest_created_at
             FROM %s
             WHERE available_at <= :threshold',
            $this->connection->quoteIdentifier($this->tableName)
        );
        $parameters = ['threshold' => $threshold->format('Y-m-d H:i:s')];
        $parameterTypes = [];

        if ($this->queueNames !== []) {
            $query .= ' AND queue_name IN (:queueNames)';
            $parameters['queueNames'] = $this->queueNames;
            $parameterTypes['queueNames'] = ArrayParameterType::STRING;
        } elseif ($this->excludedQueueNames !== []) {
            // the failure transport queue is covered by the FailedMessagesCheck
            $query .= ' AND queue_name NOT IN (:excludedQueueNames)';
            $parameters['excludedQueueNames'] = $this->excludedQueueNames;
            $parameterTypes['excludedQueueNames'] = ArrayParameterType::STRING;
        }

        $query .= ' GROUP BY queue_name ORDER BY queue_name';

        $rows = $this->connection->fetchAllAssociative($query, $parameters, $parameterTypes);

        if ($rows === []) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::OK,
                sprintf('No messages older than %d hour(s) in the queue.', $this->maxAgeHours),
            );
        }

        $totalStuck = 0;
        $details = [];
        foreach ($rows as $row) {
            $totalStuck += (int) $row['stuck_count'];
            $details[] = sprintf(
                'Queue "%s": %d stuck message(s), oldest from %s (UTC)',
                (string) $row['queue_name'],
                (int) $row['stuck_count'],
                (string) $row['oldest_created_at']
            );
        }

        return new CheckResult(
            $this->getName(),
            $this->getLabel(),
            HealthStatus::WARNING,
            sprintf(
                '%d message(s) older than %d hour(s) are stuck in the message queue. Check whether the messenger workers are running.',
                $totalStuck,
                $this->maxAgeHours
            ),
            $details,
        );
    }
}
