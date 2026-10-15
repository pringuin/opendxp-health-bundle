<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Util;

use Pringuin\OpenDxpHealthBundle\Tests\Double\ComposerRunnerDoubles;
use Pringuin\OpenDxpHealthBundle\Util\ComposerCommandException;
use Pringuin\OpenDxpHealthBundle\Util\ComposerJsonRunner;
use Pringuin\OpenDxpHealthBundle\Util\ComposerRunnerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(ComposerJsonRunner::class)]
#[CoversClass(ComposerCommandException::class)]
final class ComposerJsonRunnerTest extends TestCase
{
    use ComposerRunnerDoubles;

    public function testFailsWhenNoComposerBinaryIsFound(): void
    {
        $this->expectException(ComposerCommandException::class);
        $this->expectExceptionMessage('No executable composer binary found');

        $this->createComposerJsonRunner(null)->run(['audit'], 'advisories', 30);
    }

    public function testAppendsTheJsonOutputOptionsAndPassesTheTimeout(): void
    {
        $capturedCommand = [];
        $capturedTimeout = 0;
        $runner = $this->createMock(ComposerRunnerInterface::class);
        $runner->method('resolveCommand')->willReturn(['php', 'composer.phar']);
        $runner->method('createProcess')->willReturnCallback(
            function (array $command, int $timeout) use (&$capturedCommand, &$capturedTimeout): Process {
                $capturedCommand = $command;
                $capturedTimeout = $timeout;

                return $this->createFinishedProcess('{"installed":[]}');
            }
        );

        (new ComposerJsonRunner($runner))->run(['outdated', '--direct'], 'installed', 120);

        self::assertSame(['php', 'composer.phar', 'outdated', '--direct', '--format=json', '--no-interaction', '--no-plugins'], $capturedCommand);
        self::assertSame(120, $capturedTimeout);
    }

    public function testReturnsTheDecodedOutput(): void
    {
        $runner = $this->createComposerJsonRunner(['composer'], '{"advisories":{"vendor/package":[]},"abandoned":{}}');

        self::assertSame(
            ['advisories' => ['vendor/package' => []], 'abandoned' => []],
            $runner->run(['audit'], 'advisories', 30)
        );
    }

    public function testIgnoresANonZeroExitCodeWhenTheOutputIsValid(): void
    {
        // composer audit exits with 1 as soon as advisories are found
        $runner = $this->createComposerJsonRunner(['composer'], '{"advisories":{"vendor/package":[]}}', exitCode: 1);

        self::assertArrayHasKey('vendor/package', $runner->run(['audit'], 'advisories', 30)['advisories']);
    }

    public function testToleratesOutputAroundTheJsonObject(): void
    {
        $noise = "Warning: something on stdout\n" . '{"advisories":{},"abandoned":{}}' . "\nDone.";

        $data = $this->createComposerJsonRunner(['composer'], $noise)->run(['audit'], 'advisories', 30);

        self::assertSame([], $data['advisories']);
    }

    public function testReportsTheExitCodeAndTheErrorOutputForUnparseableOutput(): void
    {
        $runner = $this->createComposerJsonRunner(['composer'], 'not json at all', "  HTTP 403  \n", 255);

        $exception = $this->captureFailure(fn () => $runner->run(['audit'], 'advisories', 30));

        self::assertSame('composer audit did not return parseable JSON (exit code 255).', $exception->getMessage());
        self::assertSame(['HTTP 403'], $exception->details);
    }

    public function testOmitsEmptyErrorOutputFromTheDetails(): void
    {
        $runner = $this->createComposerJsonRunner(['composer'], '', " \n", 1);

        self::assertSame([], $this->captureFailure(fn () => $runner->run(['audit'], 'advisories', 30))->details);
    }

    public function testRejectsOutputWithoutTheRequiredKey(): void
    {
        $runner = $this->createComposerJsonRunner(['composer'], '{"something":"else"}');

        $exception = $this->captureFailure(fn () => $runner->run(['outdated', '--direct'], 'installed', 30));

        self::assertSame('composer outdated did not return parseable JSON (exit code 0).', $exception->getMessage());
    }

    public function testRejectsARequiredKeyThatDoesNotHoldAnArray(): void
    {
        $runner = $this->createComposerJsonRunner(['composer'], '{"installed":"nothing"}');

        $this->expectException(ComposerCommandException::class);

        $runner->run(['outdated', '--direct'], 'installed', 30);
    }

    public function testRetriesOnceAfterUnparseableOutput(): void
    {
        $runner = $this->createMock(ComposerRunnerInterface::class);
        $runner->method('resolveCommand')->willReturn(['composer']);
        $runner->expects(self::exactly(2))->method('createProcess')->willReturnOnConsecutiveCalls(
            $this->createFinishedProcess('', 'curl error 35', 1),
            $this->createFinishedProcess('{"advisories":{"vendor/package":[]}}', exitCode: 1),
        );

        $data = (new ComposerJsonRunner($runner, retryDelaySeconds: 0))->run(['audit'], 'advisories', 30);

        self::assertArrayHasKey('vendor/package', $data['advisories']);
    }

    public function testFailsWhenTheRetryReturnsUnparseableOutputToo(): void
    {
        $runner = $this->createMock(ComposerRunnerInterface::class);
        $runner->method('resolveCommand')->willReturn(['composer']);
        $runner->expects(self::exactly(2))->method('createProcess')->willReturnOnConsecutiveCalls(
            $this->createFinishedProcess('', 'first failure', 1),
            $this->createFinishedProcess('', 'second failure', 1),
        );
        $jsonRunner = new ComposerJsonRunner($runner, retryDelaySeconds: 0);

        $exception = $this->captureFailure(fn () => $jsonRunner->run(['audit'], 'advisories', 30));

        self::assertSame(['second failure'], $exception->details);
    }

    public function testFlagsANetworkFailureAndReducesTheErrorOutputToTheCause(): void
    {
        $deprecation = 'Deprecation Notice: Constant E_STRICT is deprecated in /usr/share/php/Composer/Util/Silencer.php:36';
        $errorOutput = implode("
", [
            $deprecation,
            $deprecation,
            '',
            'In CurlDownloader.php line 365:',
            '  curl error 35 while downloading https://packagist.org/api/security-advisories/: Recv failure: Connection reset by peer  ',
            '',
            'audit [--no-dev] [-f|--format FORMAT] [--locked]',
            'PHP Deprecated:  Something else is deprecated',
        ]);
        $runner = $this->createComposerJsonRunner(['composer'], '', $errorOutput, 1);

        $exception = $this->captureFailure(fn () => $runner->run(['audit'], 'advisories', 30));

        self::assertTrue($exception->isCausedByNetworkFailure);
        self::assertSame(
            [
                'In CurlDownloader.php line 365:',
                'curl error 35 while downloading https://packagist.org/api/security-advisories/: Recv failure: Connection reset by peer',
            ],
            $exception->details
        );
    }

    public function testDoesNotFlagOtherFailuresAsNetworkFailures(): void
    {
        $runner = $this->createComposerJsonRunner(['composer'], '', 'PHP Fatal error: Allowed memory size exhausted', 255);

        self::assertFalse($this->captureFailure(fn () => $runner->run(['audit'], 'advisories', 30))->isCausedByNetworkFailure);
    }

    private function captureFailure(callable $run): ComposerCommandException
    {
        try {
            $run();
        } catch (ComposerCommandException $exception) {
            return $exception;
        }

        self::fail('Expected a ' . ComposerCommandException::class);
    }
}
