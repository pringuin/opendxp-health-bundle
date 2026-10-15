<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use Pringuin\OpenDxpHealthBundle\Check\EnvironmentCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnvironmentCheck::class)]
final class EnvironmentCheckTest extends TestCase
{
    public function testReportsOkForTheExpectedEnvironmentWithoutDebug(): void
    {
        $result = (new EnvironmentCheck('prod', false))->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('Running in environment "prod" with debug mode disabled.', $result->summary);
        self::assertSame([], $result->details);
    }

    public function testReportsCriticalForAnUnexpectedEnvironment(): void
    {
        $result = (new EnvironmentCheck('dev', false))->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertCount(1, $result->details);
        self::assertStringContainsString('Kernel environment is "dev", expected "prod"', $result->details[0]);
    }

    public function testReportsCriticalForEnabledDebugMode(): void
    {
        $result = (new EnvironmentCheck('prod', true))->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertCount(1, $result->details);
        self::assertStringContainsString('Debug mode is enabled', $result->details[0]);
    }

    public function testListsBothProblemsSeparately(): void
    {
        $result = (new EnvironmentCheck('dev', true))->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertCount(2, $result->details);
        self::assertSame('The system does not run with the expected production settings.', $result->summary);
    }

    public function testHonoursACustomExpectedEnvironment(): void
    {
        $result = (new EnvironmentCheck('staging', false, 'staging'))->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('"staging"', $result->summary);
    }
}
