<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Pringuin\OpenDxpHealthBundle\Check\ApplicationLogCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApplicationLogCheck::class)]
final class ApplicationLogCheckTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $executedParameters = [];

    public function testWarnsWhenTheApplicationLogTableIsMissing(): void
    {
        $check = new ApplicationLogCheck($this->createConnection(tableExists: false));

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('Table "application_logs" does not exist', $result->summary);
    }

    public function testReportsOkWithoutMatchingEntries(): void
    {
        $check = new ApplicationLogCheck($this->createConnection(), maxAgeHours: 12, priorities: ['critical', 'error']);

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('No critical/error entries in the application log within the last 12 hour(s).', $result->summary);
    }

    public function testWarnsAndSumsTheCountsPerPriority(): void
    {
        $check = new ApplicationLogCheck(
            $this->createConnection(countsPerPriority: ['error' => 7, 'critical' => 2]),
            maxAgeHours: 24
        );

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertSame('9 error-level entries in the application log within the last 24 hour(s).', $result->summary);
        self::assertSame('error: 7 entries', $result->details[0]);
        self::assertSame('critical: 2 entries', $result->details[1]);
    }

    public function testUsesTheSingularForASingleEntry(): void
    {
        $check = new ApplicationLogCheck($this->createConnection(countsPerPriority: ['error' => 1]));

        $result = $check->run();

        self::assertStringContainsString('1 error-level entry in the application log', $result->summary);
        self::assertSame('error: 1 entry', $result->details[0]);
    }

    public function testAppendsTheMostRecentEntriesToTheDetails(): void
    {
        $check = new ApplicationLogCheck($this->createConnection(
            countsPerPriority: ['error' => 2],
            recentEntries: [
                ['timestamp' => '2026-08-07 09:00:00', 'priority' => 'error', 'component' => 'import', 'message' => 'Import failed'],
                ['timestamp' => '2026-08-07 08:00:00', 'priority' => 'error', 'component' => null, 'message' => 'Something else'],
            ]
        ));

        $result = $check->run();

        self::assertCount(3, $result->details);
        self::assertSame('[2026-08-07 09:00:00 UTC] error (import): Import failed', $result->details[1]);
        self::assertSame('[2026-08-07 08:00:00 UTC] error (-): Something else', $result->details[2]);
    }

    public function testTruncatesOverlyLongMessages(): void
    {
        $check = new ApplicationLogCheck($this->createConnection(
            countsPerPriority: ['error' => 1],
            recentEntries: [
                ['timestamp' => '2026-08-07 09:00:00', 'priority' => 'error', 'component' => 'x', 'message' => str_repeat('y', 400)],
            ]
        ));

        $result = $check->run();

        self::assertStringContainsString(str_repeat('y', 160) . '...', $result->details[1]);
        self::assertStringNotContainsString(str_repeat('y', 161), $result->details[1]);
    }

    public function testPassesTheConfiguredPrioritiesToTheQuery(): void
    {
        $check = new ApplicationLogCheck($this->createConnection(), priorities: ['emergency', 'alert']);

        $check->run();

        self::assertSame(['emergency', 'alert'], $this->executedParameters['priorities']);
    }

    public function testCalculatesTheThresholdInUtc(): void
    {
        $check = new ApplicationLogCheck($this->createConnection(), maxAgeHours: 6);

        $check->run();

        $expected = (new \DateTimeImmutable('-6 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i');
        self::assertStringStartsWith($expected, (string) $this->executedParameters['threshold']);
    }

    /**
     * @param array<string, int> $countsPerPriority
     * @param list<array<string, mixed>> $recentEntries
     */
    private function createConnection(
        bool $tableExists = true,
        array $countsPerPriority = [],
        array $recentEntries = [],
    ): Connection {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn($tableExists);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAllKeyValue')->willReturnCallback(
            /** @param array<string, mixed> $parameters */
            function (string $sql, array $parameters) use ($countsPerPriority): array {
                $this->executedParameters = $parameters;

                return $countsPerPriority;
            }
        );
        $connection->method('fetchAllAssociative')->willReturn($recentEntries);

        return $connection;
    }
}
