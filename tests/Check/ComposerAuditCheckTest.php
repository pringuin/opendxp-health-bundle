<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use Pringuin\OpenDxpHealthBundle\Check\ComposerAuditCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Tests\Double\ComposerRunnerDoubles;
use Pringuin\OpenDxpHealthBundle\Util\ComposerJsonRunner;
use Pringuin\OpenDxpHealthBundle\Util\ComposerRunnerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(ComposerAuditCheck::class)]
final class ComposerAuditCheckTest extends TestCase
{
    use ComposerRunnerDoubles;

    public function testReportsAnErrorWhenNoComposerBinaryIsFound(): void
    {
        $check = new ComposerAuditCheck($this->createComposerJsonRunner(null));

        $result = $check->run();

        self::assertSame(HealthStatus::ERROR, $result->status);
        self::assertStringContainsString('No executable composer binary found', $result->summary);
    }

    public function testReportsOkWithoutAdvisories(): void
    {
        $check = new ComposerAuditCheck($this->createComposerJsonRunner(['composer'], '{"advisories":{},"abandoned":{}}'));

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('No security advisories found.', $result->summary);
        self::assertSame([], $result->details);
    }

    public function testReportsCriticalForSecurityAdvisories(): void
    {
        $output = json_encode([
            'advisories' => [
                'vendor/package' => [
                    [
                        'packageName' => 'vendor/package',
                        'severity' => 'high',
                        'cve' => 'CVE-2026-1234',
                        'affectedVersions' => '<1.2.3',
                        'title' => 'Remote code execution',
                        'link' => 'https://example.com/advisory',
                    ],
                ],
            ],
            'abandoned' => [],
        ], JSON_THROW_ON_ERROR);
        $check = new ComposerAuditCheck($this->createComposerJsonRunner(['composer'], $output));

        $result = $check->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertSame('1 security advisory found across 1 package.', $result->summary);
        self::assertSame(
            'vendor/package | severity: high | CVE-2026-1234 | affected: <1.2.3 | Remote code execution | https://example.com/advisory',
            $result->details[0]
        );
    }

    public function testCountsAdvisoriesAcrossPackages(): void
    {
        $output = json_encode([
            'advisories' => [
                'vendor/one' => [['packageName' => 'vendor/one'], ['packageName' => 'vendor/one']],
                'vendor/two' => [['packageName' => 'vendor/two']],
            ],
        ], JSON_THROW_ON_ERROR);
        $check = new ComposerAuditCheck($this->createComposerJsonRunner(['composer'], $output));

        $result = $check->run();

        self::assertSame('3 security advisories found across 2 packages.', $result->summary);
    }

    public function testOmitsEmptyAdvisoryFields(): void
    {
        $output = json_encode([
            'advisories' => ['vendor/package' => [['packageName' => 'vendor/package', 'severity' => '', 'cve' => null]]],
        ], JSON_THROW_ON_ERROR);
        $check = new ComposerAuditCheck($this->createComposerJsonRunner(['composer'], $output));

        self::assertSame('vendor/package', $check->run()->details[0]);
    }

    public function testWarnsAboutAbandonedPackagesWithoutAdvisories(): void
    {
        $output = json_encode([
            'advisories' => [],
            'abandoned' => ['vendor/old' => 'vendor/new', 'vendor/dead' => null],
        ], JSON_THROW_ON_ERROR);
        $check = new ComposerAuditCheck($this->createComposerJsonRunner(['composer'], $output));

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertSame('No security advisories, but 2 abandoned package(s) in use.', $result->summary);
        self::assertSame('Abandoned package: vendor/old (suggested replacement: vendor/new)', $result->details[0]);
        self::assertSame('Abandoned package: vendor/dead', $result->details[1]);
    }

    public function testReportsAnErrorForUnparseableOutput(): void
    {
        $check = new ComposerAuditCheck($this->createComposerJsonRunner(['composer'], 'not json at all', 'PHP Fatal error', 255));

        $result = $check->run();

        self::assertSame(HealthStatus::ERROR, $result->status);
        self::assertStringContainsString('did not return parseable JSON (exit code 255)', $result->summary);
        self::assertSame(['PHP Fatal error'], $result->details);
    }

    public function testWarnsWhenTheAdvisoryDatabaseIsUnreachable(): void
    {
        $errorOutput = 'curl error 35 while downloading https://packagist.org/api/security-advisories/: Recv failure: Connection reset by peer';
        $check = new ComposerAuditCheck($this->createComposerJsonRunner(['composer'], '', $errorOutput, 1));

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertSame('The security advisory database could not be reached, the dependencies were not audited.', $result->summary);
        self::assertSame([$errorOutput], $result->details);
    }

    public function testReportsAnErrorForJsonWithoutAnAdvisoriesKey(): void
    {
        $check = new ComposerAuditCheck($this->createComposerJsonRunner(['composer'], '{"something":"else"}'));

        self::assertSame(HealthStatus::ERROR, $check->run()->status);
    }

    public function testPassesTheAuditArgumentsAndTimeoutToTheRunner(): void
    {
        $capturedCommand = [];
        $capturedTimeout = 0;
        $runner = $this->createMock(ComposerRunnerInterface::class);
        $runner->method('resolveCommand')->willReturn(['php', 'composer.phar']);
        $runner->method('createProcess')->willReturnCallback(
            function (array $command, int $timeout) use (&$capturedCommand, &$capturedTimeout): Process {
                $capturedCommand = $command;
                $capturedTimeout = $timeout;

                return $this->createFinishedProcess('{"advisories":{}}');
            }
        );
        $check = new ComposerAuditCheck(new ComposerJsonRunner($runner), processTimeout: 120);

        $check->run();

        self::assertSame(['php', 'composer.phar', 'audit', '--format=json', '--no-interaction', '--no-plugins'], $capturedCommand);
        self::assertSame(120, $capturedTimeout);
    }
}
