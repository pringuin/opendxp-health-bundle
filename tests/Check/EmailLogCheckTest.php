<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Pringuin\OpenDxpHealthBundle\Check\EmailLogCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailLogCheck::class)]
final class EmailLogCheckTest extends TestCase
{
    /** Number of peak window queries the check issued, to verify the short-circuit. */
    private int $peakWindowQueryCount = 0;

    protected function setUp(): void
    {
        $this->peakWindowQueryCount = 0;
    }

    public function testWarnsWhenTheEmailLogTableIsMissing(): void
    {
        $check = new EmailLogCheck($this->createConnection(tableExists: false));

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('does not exist', $result->summary);
    }

    public function testReportsOkWithoutFailedDeliveries(): void
    {
        $check = new EmailLogCheck($this->createConnection());

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('No failed email deliveries within the last 24 hour(s).', $result->summary);
        self::assertSame([], $result->details);
    }

    public function testReportsFailedDeliveriesWithDetails(): void
    {
        $connection = $this->createConnection(
            failedCount: 2,
            failureRows: [
                ['sentDate' => 1_700_000_000, 'to' => 'a@example.com', 'subject' => 'Order', 'error' => 'Connection refused'],
                ['sentDate' => 1_699_999_000, 'to' => 'b@example.com', 'subject' => 'Invoice', 'error' => 'Mailbox full'],
            ],
        );
        $check = new EmailLogCheck($connection);

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('2 email delivery/deliveries failed', $result->summary);
        self::assertCount(2, $result->details);
        self::assertStringContainsString('a@example.com', $result->details[0]);
        self::assertStringContainsString('Connection refused', $result->details[0]);
    }

    public function testTruncatesOverlyLongErrorMessages(): void
    {
        $connection = $this->createConnection(
            failedCount: 1,
            failureRows: [
                ['sentDate' => 1_700_000_000, 'to' => 'a@example.com', 'subject' => 'Order', 'error' => str_repeat('x', 500)],
            ],
        );
        $check = new EmailLogCheck($connection);

        $result = $check->run();

        self::assertStringContainsString(str_repeat('x', 160) . '...', $result->details[0]);
        self::assertStringNotContainsString(str_repeat('x', 161), $result->details[0]);
    }

    public function testCountsFailedDeliveriesBeyondTheListedOnes(): void
    {
        $failureRows = [];
        for ($index = 0; $index < 5; $index++) {
            $failureRows[] = ['sentDate' => 1_700_000_000, 'to' => 'a@example.com', 'subject' => 'Order', 'error' => 'boom'];
        }
        $check = new EmailLogCheck($this->createConnection(failedCount: 8, failureRows: $failureRows));

        $result = $check->run();

        self::assertCount(6, $result->details);
        self::assertSame('... and 3 more failed delivery/deliveries.', $result->details[5]);
    }

    public function testSkipsTheSendVolumeCheckWhenNoMaximumIsConfigured(): void
    {
        $connection = $this->createConnection(totalCount: 5_000, peakWindowRow: ['windowStart' => 1_700_000_000, 'emailCount' => 999]);
        $check = new EmailLogCheck($connection, maxEmailsPerWindow: 0);

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringNotContainsString('Send volume', $result->summary);
        self::assertSame(0, $this->peakWindowQueryCount);
    }

    public function testDoesNotScanWindowsWhenTheTotalStaysBelowTheMaximum(): void
    {
        $connection = $this->createConnection(totalCount: 20, peakWindowRow: ['windowStart' => 1_700_000_000, 'emailCount' => 999]);
        $check = new EmailLogCheck($connection, maxEmailsPerWindow: 20, windowMinutes: 60);

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('Send volume is within the limit of 20 email(s) per 60 minute(s)', $result->summary);
        self::assertSame(0, $this->peakWindowQueryCount, 'The window scan must be skipped when the total cannot exceed the maximum.');
    }

    public function testReportsOkWhenNoSingleWindowExceedsTheMaximum(): void
    {
        $connection = $this->createConnection(
            totalCount: 100,
            peakWindowRow: ['windowStart' => 1_700_000_000, 'emailCount' => 20],
        );
        $check = new EmailLogCheck($connection, maxEmailsPerWindow: 20, windowMinutes: 60);

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('Send volume is within the limit', $result->summary);
        self::assertSame(1, $this->peakWindowQueryCount);
        self::assertSame([], $result->details);
    }

    public function testWarnsWhenAWindowExceedsTheMaximum(): void
    {
        $connection = $this->createConnection(
            totalCount: 300,
            peakWindowRow: ['windowStart' => 1_700_000_000, 'emailCount' => 214],
            subjectRows: [
                ['subject' => 'Your order has shipped', 'emailCount' => 200],
                ['subject' => 'Newsletter', 'emailCount' => 14],
            ],
        );
        $check = new EmailLogCheck($connection, maxEmailsPerWindow: 20, windowMinutes: 60);

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('214 email(s) were sent within 60 minute(s)', $result->summary);
        self::assertStringContainsString('more than the configured maximum of 20', $result->summary);
        self::assertCount(2, $result->details);
        self::assertStringContainsString('214 email(s) sent', $result->details[0]);
        self::assertStringContainsString('300 in the last 24 hour(s) in total', $result->details[0]);
        self::assertStringContainsString('200x "Your order has shipped"', $result->details[1]);
    }

    public function testReportsFailedDeliveriesAndVolumePeakTogether(): void
    {
        $connection = $this->createConnection(
            failedCount: 1,
            failureRows: [
                ['sentDate' => 1_700_000_000, 'to' => 'a@example.com', 'subject' => 'Order', 'error' => 'boom'],
            ],
            totalCount: 300,
            peakWindowRow: ['windowStart' => 1_700_000_000, 'emailCount' => 214],
            subjectRows: [['subject' => 'Order', 'emailCount' => 214]],
        );
        $check = new EmailLogCheck($connection, maxEmailsPerWindow: 20, windowMinutes: 60);

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('1 email delivery/deliveries failed', $result->summary);
        self::assertStringContainsString('214 email(s) were sent within 60 minute(s)', $result->summary);
        self::assertCount(3, $result->details);
    }

    public function testUsesTheConfiguredWindowLengthAsTheFrameOfThePeakQuery(): void
    {
        $executedQueries = [];
        $connection = $this->createConnection(
            totalCount: 100,
            peakWindowRow: ['windowStart' => 1_700_000_000, 'emailCount' => 20],
            executedQueries: $executedQueries,
        );
        $check = new EmailLogCheck($connection, maxEmailsPerWindow: 5, windowMinutes: 15);

        $check->run();

        self::assertStringContainsString('RANGE BETWEEN CURRENT ROW AND 900 FOLLOWING', implode("\n", $executedQueries));
    }

    public function testHandlesAnEmptyEmailLogWithinTheObservedPeriod(): void
    {
        $connection = $this->createConnection(totalCount: 0, peakWindowRow: false);
        $check = new EmailLogCheck($connection, maxEmailsPerWindow: 20);

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('0 sent in the last 24 hour(s)', $result->summary);
    }

    /**
     * @param list<array<string, mixed>> $failureRows
     * @param array{windowStart: int, emailCount: int}|false $peakWindowRow
     * @param list<array<string, mixed>> $subjectRows
     * @param list<string> $executedQueries
     */
    private function createConnection(
        bool $tableExists = true,
        int $failedCount = 0,
        array $failureRows = [],
        int $totalCount = 0,
        array|false $peakWindowRow = false,
        array $subjectRows = [],
        array &$executedQueries = [],
    ): Connection {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn($tableExists);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $connection->method('fetchOne')->willReturnCallback(
            static function (string $sql) use ($failedCount, $totalCount, &$executedQueries): int {
                $executedQueries[] = $sql;

                return str_contains($sql, 'error IS NOT NULL') ? $failedCount : $totalCount;
            }
        );

        $connection->method('fetchAssociative')->willReturnCallback(
            function (string $sql) use ($peakWindowRow, &$executedQueries): array|false {
                $executedQueries[] = $sql;
                $this->peakWindowQueryCount++;

                return $peakWindowRow;
            }
        );

        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use ($failureRows, $subjectRows, &$executedQueries): array {
                $executedQueries[] = $sql;

                return str_contains($sql, 'GROUP BY subject') ? $subjectRows : $failureRows;
            }
        );

        return $connection;
    }
}
