<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Pringuin\OpenDxpHealthBundle\Check\MessageQueueCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageQueueCheck::class)]
final class MessageQueueCheckTest extends TestCase
{
    private string $executedQuery = '';

    /** @var array<string, mixed> */
    private array $executedParameters = [];

    public function testWarnsWhenTheMessengerTableIsMissing(): void
    {
        $check = new MessageQueueCheck($this->createConnection(tableExists: false));

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('Messenger table "messenger_messages" does not exist', $result->summary);
    }

    public function testReportsOkWithoutStuckMessages(): void
    {
        $check = new MessageQueueCheck($this->createConnection(), maxAgeHours: 4);

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('No messages older than 4 hour(s) in the queue.', $result->summary);
    }

    public function testWarnsAboutStuckMessagesPerQueue(): void
    {
        $check = new MessageQueueCheck(
            $this->createConnection(rows: [
                ['queue_name' => 'default', 'stuck_count' => 12, 'oldest_created_at' => '2026-08-06 22:00:00'],
                ['queue_name' => 'imports', 'stuck_count' => 3, 'oldest_created_at' => '2026-08-07 01:00:00'],
            ]),
            maxAgeHours: 4
        );

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('15 message(s) older than 4 hour(s) are stuck', $result->summary);
        self::assertSame('Queue "default": 12 stuck message(s), oldest from 2026-08-06 22:00:00 (UTC)', $result->details[0]);
        self::assertStringContainsString('Queue "imports": 3 stuck message(s)', $result->details[1]);
    }

    public function testRestrictsTheQueryToTheConfiguredQueueNames(): void
    {
        $check = new MessageQueueCheck($this->createConnection(), queueNames: ['imports', 'exports']);

        $check->run();

        self::assertStringContainsString('AND queue_name IN (:queueNames)', $this->executedQuery);
        self::assertStringNotContainsString('NOT IN', $this->executedQuery);
        self::assertSame(['imports', 'exports'], $this->executedParameters['queueNames']);
    }

    public function testExcludesTheFailureQueueByDefault(): void
    {
        $check = new MessageQueueCheck($this->createConnection());

        $check->run();

        self::assertStringContainsString('AND queue_name NOT IN (:excludedQueueNames)', $this->executedQuery);
        self::assertSame(['failed'], $this->executedParameters['excludedQueueNames']);
    }

    public function testConfiguredQueueNamesTakePrecedenceOverExclusions(): void
    {
        $check = new MessageQueueCheck(
            $this->createConnection(),
            queueNames: ['imports'],
            excludedQueueNames: ['failed']
        );

        $check->run();

        self::assertArrayHasKey('queueNames', $this->executedParameters);
        self::assertArrayNotHasKey('excludedQueueNames', $this->executedParameters);
    }

    public function testAppliesNoQueueFilterWhenBothListsAreEmpty(): void
    {
        $check = new MessageQueueCheck($this->createConnection(), queueNames: [], excludedQueueNames: []);

        $check->run();

        self::assertStringNotContainsString('queue_name IN', $this->executedQuery);
        self::assertStringNotContainsString('queue_name NOT IN', $this->executedQuery);
    }

    public function testUsesTheConfiguredTableName(): void
    {
        $check = new MessageQueueCheck($this->createConnection(), tableName: 'custom_messages');

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('custom_messages', $this->executedQuery);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createConnection(bool $tableExists = true, array $rows = []): Connection
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn($tableExists);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '`' . $name . '`');
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $parameters */
            function (string $sql, array $parameters) use ($rows): array {
                $this->executedQuery = $sql;
                $this->executedParameters = $parameters;

                return $rows;
            }
        );

        return $connection;
    }
}
