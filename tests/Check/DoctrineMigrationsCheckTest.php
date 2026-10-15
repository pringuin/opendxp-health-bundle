<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Version\MigrationStatusCalculator;
use Doctrine\Migrations\Version\Version;
use Pringuin\OpenDxpHealthBundle\Check\DoctrineMigrationsCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineMigrationsCheck::class)]
final class DoctrineMigrationsCheckTest extends TestCase
{
    public function testReportsOkWhenEverythingIsMigrated(): void
    {
        $check = new DoctrineMigrationsCheck($this->createDependencyFactory([], []));

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('All migrations have been executed.', $result->summary);
    }

    public function testReportsCriticalForPendingMigrations(): void
    {
        $check = new DoctrineMigrationsCheck($this->createDependencyFactory(
            ['Project\Migrations\Version20260801120000', 'Project\Migrations\Version20260802120000'],
            []
        ));

        $result = $check->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertStringContainsString('2 pending migration(s)', $result->summary);
        self::assertStringContainsString('doctrine:migrations:migrate', $result->summary);
        self::assertSame(
            ['Project\Migrations\Version20260801120000', 'Project\Migrations\Version20260802120000'],
            $result->details
        );
    }

    public function testCapsTheListOfPendingMigrations(): void
    {
        $versions = [];
        for ($index = 0; $index < 25; $index++) {
            $versions[] = sprintf('Version%026d', $index);
        }
        $check = new DoctrineMigrationsCheck($this->createDependencyFactory($versions, []));

        $result = $check->run();

        self::assertCount(21, $result->details);
        self::assertSame('... and 5 more migration(s).', $result->details[20]);
    }

    public function testWarnsAboutExecutedMigrationsMissingFromTheCodebase(): void
    {
        $check = new DoctrineMigrationsCheck($this->createDependencyFactory([], ['Version20260101000000']));

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('1 executed migration(s) are no longer available', $result->summary);
    }

    public function testPendingMigrationsTakePrecedenceOverUnavailableOnes(): void
    {
        $check = new DoctrineMigrationsCheck($this->createDependencyFactory(['VersionNew'], ['VersionGone']));

        self::assertSame(HealthStatus::CRITICAL, $check->run()->status);
    }

    /**
     * @param list<string> $newVersions
     * @param list<string> $executedUnavailableVersions
     */
    private function createDependencyFactory(array $newVersions, array $executedUnavailableVersions): DependencyFactory
    {
        $migration = $this->createMock(AbstractMigration::class);

        $statusCalculator = $this->createMock(MigrationStatusCalculator::class);
        $statusCalculator->method('getNewMigrations')->willReturn(new AvailableMigrationsList(
            array_map(
                static fn (string $version): AvailableMigration => new AvailableMigration(new Version($version), $migration),
                $newVersions
            )
        ));
        $statusCalculator->method('getExecutedUnavailableMigrations')->willReturn(new ExecutedMigrationsList(
            array_map(
                static fn (string $version): ExecutedMigration => new ExecutedMigration(new Version($version)),
                $executedUnavailableVersions
            )
        ));

        $dependencyFactory = $this->createMock(DependencyFactory::class);
        $dependencyFactory->method('getMigrationStatusCalculator')->willReturn($statusCalculator);

        return $dependencyFactory;
    }
}
