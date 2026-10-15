<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use Pringuin\OpenDxpHealthBundle\Check\ComposerOutdatedCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Tests\Double\ComposerRunnerDoubles;
use Pringuin\OpenDxpHealthBundle\Util\ComposerJsonRunner;
use Pringuin\OpenDxpHealthBundle\Util\ComposerRunnerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(ComposerOutdatedCheck::class)]
final class ComposerOutdatedCheckTest extends TestCase
{
    use ComposerRunnerDoubles;

    public function testReportsAnErrorWhenNoComposerBinaryIsFound(): void
    {
        $result = (new ComposerOutdatedCheck($this->createComposerJsonRunner(null)))->run();

        self::assertSame(HealthStatus::ERROR, $result->status);
        self::assertStringContainsString('No executable composer binary found', $result->summary);
    }

    public function testReportsOkWhenNothingIsOutdated(): void
    {
        $check = new ComposerOutdatedCheck($this->createRunnerForPackages([]));

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('All directly required packages are up to date.', $result->summary);
    }

    public function testIgnoresPackagesAlreadyOnTheLatestVersion(): void
    {
        $check = new ComposerOutdatedCheck($this->createRunnerForPackages([
            ['name' => 'vendor/current', 'version' => 'v2.1.0', 'latest' => 'v2.1.0'],
        ]));

        self::assertSame(HealthStatus::OK, $check->run()->status);
    }

    public function testReportsOkForPurePatchLagWithoutWatchedPackages(): void
    {
        $check = new ComposerOutdatedCheck(
            $this->createRunnerForPackages([
                ['name' => 'vendor/small', 'version' => 'v2.1.0', 'latest' => 'v2.1.4'],
            ]),
            watchPackages: []
        );

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('1 direct dependency outdated (0 major upgrade(s) behind).', $result->summary);
        self::assertSame(['vendor/small: v2.1.0 -> v2.1.4'], $result->details);
    }

    public function testWarnsAboutMajorUpgradesBehind(): void
    {
        $check = new ComposerOutdatedCheck(
            $this->createRunnerForPackages([
                ['name' => 'vendor/big', 'version' => 'v2.9.0', 'latest' => 'v3.0.1'],
                ['name' => 'vendor/small', 'version' => 'v2.1.0', 'latest' => 'v2.1.4'],
            ]),
            watchPackages: []
        );

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertSame('2 direct dependencies outdated (1 major upgrade(s) behind).', $result->summary);
        self::assertSame('vendor/big: v2.9.0 -> v3.0.1 (major)', $result->details[0]);
        self::assertSame('vendor/small: v2.1.0 -> v2.1.4', $result->details[1]);
    }

    public function testWarnsAboutAnOutdatedWatchedPackageEvenForAPatchRelease(): void
    {
        $check = new ComposerOutdatedCheck(
            $this->createRunnerForPackages([
                ['name' => 'open-dxp/opendxp', 'version' => 'v1.3.1', 'latest' => 'v1.3.3'],
            ]),
            watchPackages: ['open-dxp/opendxp']
        );

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('watched package(s) affected', $result->summary);
        self::assertSame('Watched packages:', $result->details[0]);
        self::assertSame('open-dxp/opendxp: v1.3.1 -> v1.3.3', $result->details[1]);
    }

    public function testMarksAWatchedPackageWithAMajorUpgrade(): void
    {
        $check = new ComposerOutdatedCheck(
            $this->createRunnerForPackages([
                ['name' => 'open-dxp/opendxp', 'version' => 'v1.3.1', 'latest' => 'v2.0.0'],
            ]),
            watchPackages: ['open-dxp/opendxp']
        );

        $result = $check->run();

        self::assertSame('open-dxp/opendxp: v1.3.1 -> v2.0.0 (major)', $result->details[1]);
    }

    public function testOmitsTheWatchedSectionWhenNoWatchedPackageIsOutdated(): void
    {
        $check = new ComposerOutdatedCheck(
            $this->createRunnerForPackages([
                ['name' => 'vendor/small', 'version' => 'v2.1.0', 'latest' => 'v2.1.4'],
            ]),
            watchPackages: ['open-dxp/opendxp']
        );

        self::assertSame(['vendor/small: v2.1.0 -> v2.1.4'], $check->run()->details);
    }

    public function testSkipsEntriesWithoutAName(): void
    {
        $check = new ComposerOutdatedCheck($this->createRunnerForPackages([
            ['version' => 'v1.0.0', 'latest' => 'v2.0.0'],
            'not an array',
        ]));

        self::assertSame(HealthStatus::OK, $check->run()->status);
    }

    public function testTreatsAnUnparseableVersionAsANonMajorUpgrade(): void
    {
        $check = new ComposerOutdatedCheck(
            $this->createRunnerForPackages([
                ['name' => 'vendor/dev', 'version' => 'dev-main', 'latest' => 'dev-master'],
            ]),
            watchPackages: []
        );

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame(['vendor/dev: dev-main -> dev-master'], $result->details);
    }

    public function testReportsAnErrorForUnparseableOutput(): void
    {
        $check = new ComposerOutdatedCheck($this->createComposerJsonRunner(['composer'], 'garbage', 'composer failed', 1));

        $result = $check->run();

        self::assertSame(HealthStatus::ERROR, $result->status);
        self::assertStringContainsString('did not return parseable JSON (exit code 1)', $result->summary);
        self::assertSame(['composer failed'], $result->details);
    }

    public function testWarnsWhenThePackageRepositoriesAreUnreachable(): void
    {
        $errorOutput = 'curl error 6 while downloading https://repo.packagist.org/packages.json: Could not resolve host: repo.packagist.org';
        $check = new ComposerOutdatedCheck($this->createComposerJsonRunner(['composer'], '', $errorOutput, 1));

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertSame('The package repositories could not be reached, the dependencies were not compared.', $result->summary);
        self::assertSame([$errorOutput], $result->details);
    }

    public function testReportsAnErrorForJsonWithoutAnInstalledKey(): void
    {
        $check = new ComposerOutdatedCheck($this->createComposerJsonRunner(['composer'], '{"something":"else"}'));

        self::assertSame(HealthStatus::ERROR, $check->run()->status);
    }

    public function testPassesTheOutdatedArgumentsToTheRunner(): void
    {
        $capturedCommand = [];
        $runner = $this->createMock(ComposerRunnerInterface::class);
        $runner->method('resolveCommand')->willReturn(['composer']);
        $runner->method('createProcess')->willReturnCallback(
            function (array $command) use (&$capturedCommand): Process {
                $capturedCommand = $command;

                return $this->createFinishedProcess('{"installed":[]}');
            }
        );

        (new ComposerOutdatedCheck(new ComposerJsonRunner($runner)))->run();

        self::assertSame(
            ['composer', 'outdated', '--direct', '--format=json', '--no-interaction', '--no-plugins'],
            $capturedCommand
        );
    }

    /**
     * @param list<mixed> $packages
     */
    private function createRunnerForPackages(array $packages): ComposerJsonRunner
    {
        return $this->createComposerJsonRunner(['composer'], json_encode(['installed' => $packages], JSON_THROW_ON_ERROR));
    }
}
