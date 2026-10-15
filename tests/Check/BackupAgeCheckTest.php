<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use Pringuin\OpenDxpHealthBundle\Check\BackupAgeCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackupAgeCheck::class)]
final class BackupAgeCheckTest extends TestCase
{
    private string $backupDirectory;

    protected function setUp(): void
    {
        $this->backupDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('health-backup-', true);
        mkdir($this->backupDirectory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->backupDirectory);
    }

    public function testDoesNothingWithoutAConfiguredDirectory(): void
    {
        $result = (new BackupAgeCheck(null))->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('No backup directory configured', $result->summary);
    }

    public function testWarnsWhenTheDirectoryDoesNotExist(): void
    {
        $result = (new BackupAgeCheck($this->backupDirectory . '-missing'))->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('does not exist', $result->summary);
    }

    public function testReportsCriticalWhenNoBackupFileMatches(): void
    {
        $this->createBackupFile('database.sql.gz', ageHours: 1);

        $result = (new BackupAgeCheck($this->backupDirectory, '*.tar'))->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertStringContainsString('No backup files matching "*.tar"', $result->summary);
    }

    public function testReportsOkForARecentBackup(): void
    {
        $this->createBackupFile('database.sql.gz', ageHours: 2);

        $result = (new BackupAgeCheck($this->backupDirectory, '*.gz', warningHours: 26, criticalHours: 50))->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('2 h', $result->summary);
        self::assertStringContainsString('database.sql.gz', $result->details[0]);
    }

    public function testWarnsWhenTheNewestBackupPassesTheWarningThreshold(): void
    {
        $this->createBackupFile('database.sql.gz', ageHours: 30);

        $result = (new BackupAgeCheck($this->backupDirectory, '*.gz', warningHours: 26, criticalHours: 50))->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('threshold: 26 h', $result->summary);
    }

    public function testReportsCriticalWhenTheNewestBackupPassesTheCriticalThreshold(): void
    {
        $this->createBackupFile('database.sql.gz', ageHours: 72);

        $result = (new BackupAgeCheck($this->backupDirectory, '*.gz', warningHours: 26, criticalHours: 50))->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertStringContainsString('3 day(s)', $result->summary);
    }

    public function testUsesTheNewestFileAndIgnoresOlderOnes(): void
    {
        $this->createBackupFile('old.sql.gz', ageHours: 100);
        $this->createBackupFile('new.sql.gz', ageHours: 1);

        $result = (new BackupAgeCheck($this->backupDirectory, '*.gz', warningHours: 26, criticalHours: 50))->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('new.sql.gz', $result->details[0]);
    }

    public function testIgnoresSubdirectoriesMatchingThePattern(): void
    {
        mkdir($this->backupDirectory . DIRECTORY_SEPARATOR . 'archive.gz');
        $this->createBackupFile('database.gz', ageHours: 1);

        $result = (new BackupAgeCheck($this->backupDirectory, '*.gz'))->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('database.gz', $result->details[0]);
    }

    private function createBackupFile(string $name, int $ageHours): void
    {
        $path = $this->backupDirectory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, 'backup');
        touch($path, time() - $ageHours * 3600);
    }
}
