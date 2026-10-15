<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use Pringuin\OpenDxpHealthBundle\Check\WritablePathsCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WritablePathsCheck::class)]
final class WritablePathsCheckTest extends TestCase
{
    private string $projectDirectory;

    protected function setUp(): void
    {
        $this->projectDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('health-project-', true);
        mkdir($this->projectDirectory . DIRECTORY_SEPARATOR . 'var', recursive: true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectDirectory . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            chmod($path, 0o777);
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->projectDirectory . DIRECTORY_SEPARATOR . 'var');
        rmdir($this->projectDirectory);
    }

    public function testDoesNothingWithoutConfiguredPaths(): void
    {
        $result = (new WritablePathsCheck($this->projectDirectory, []))->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('No paths configured', $result->summary);
    }

    public function testReportsOkForWritablePaths(): void
    {
        mkdir($this->projectDirectory . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache');

        $result = (new WritablePathsCheck($this->projectDirectory, ['var', 'var/cache']))->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('All 2 configured path(s) are writable.', $result->summary);
        self::assertSame(['var: writable.', 'var/cache: writable.'], $result->details);
    }

    public function testWarnsAboutMissingPaths(): void
    {
        $result = (new WritablePathsCheck($this->projectDirectory, ['var', 'var/does-not-exist']))->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertSame('1 configured path(s) do not exist.', $result->summary);
        self::assertContains('var/does-not-exist: does not exist.', $result->details);
    }

    public function testReportsCriticalForPathsThatAreNotWritable(): void
    {
        $readOnlyPath = $this->projectDirectory . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'readonly.txt';
        file_put_contents($readOnlyPath, 'content');
        chmod($readOnlyPath, 0o444);
        clearstatcache(true, $readOnlyPath);

        if (is_writable($readOnlyPath)) {
            self::markTestSkipped('The current user can write to read-only files (e.g. running as root).');
        }

        $result = (new WritablePathsCheck($this->projectDirectory, ['var/readonly.txt', 'var/does-not-exist']))->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertSame('1 configured path(s) are not writable, 1 missing.', $result->summary);
    }

    public function testResolvesAbsolutePathsAsIs(): void
    {
        $absolutePath = $this->projectDirectory . DIRECTORY_SEPARATOR . 'var';

        $result = (new WritablePathsCheck('/nonexistent-project-dir', [$absolutePath]))->run();

        self::assertSame(HealthStatus::OK, $result->status);
    }
}
