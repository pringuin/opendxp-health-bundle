<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Command;

use Pringuin\OpenDxpHealthBundle\Command\HealthCheckCommand;
use Pringuin\OpenDxpHealthBundle\Service\HealthCheckRunner;
use Pringuin\OpenDxpHealthBundle\Service\HealthReportMailer;
use Pringuin\OpenDxpHealthBundle\Service\HealthReportStorage;
use Pringuin\OpenDxpHealthBundle\Tests\Double\InMemorySettingsStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(HealthCheckCommand::class)]
final class HealthCheckCommandTest extends TestCase
{
    public function testFailsWithoutOverwritingTheReportWhileAnotherRunIsInProgress(): void
    {
        $lockFactory = new LockFactory(new InMemoryStore());
        $runningLock = $lockFactory->createLock('pringuin_opendxp_health_check_run');
        $runningLock->acquire();
        $settingsStorage = new InMemorySettingsStorage();
        $command = new HealthCheckCommand(
            new HealthCheckRunner([], [], $lockFactory),
            new HealthReportStorage($settingsStorage),
            new HealthReportMailer('ops@example.com'),
            false,
            'warning',
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('already running', $tester->getDisplay());
        self::assertNull($settingsStorage->get('last_report'));
    }

    public function testRunsAndStoresTheReportWhenNoOtherRunIsInProgress(): void
    {
        $settingsStorage = new InMemorySettingsStorage();
        $command = new HealthCheckCommand(
            new HealthCheckRunner([], [], new LockFactory(new InMemoryStore())),
            new HealthReportStorage($settingsStorage),
            new HealthReportMailer('ops@example.com'),
            false,
            'warning',
        );

        $exitCode = (new CommandTester($command))->execute([]);

        self::assertSame(0, $exitCode);
        self::assertNotNull($settingsStorage->get('last_report'));
    }
}
