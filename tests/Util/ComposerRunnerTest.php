<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Util;

use Pringuin\OpenDxpHealthBundle\Util\ComposerRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;

#[CoversClass(ComposerRunner::class)]
final class ComposerRunnerTest extends TestCase
{
    private const string HOME_VARIABLE = PHP_OS_FAMILY === 'Windows' ? 'APPDATA' : 'HOME';

    /**
     * @var array<string, array{env: mixed, server: mixed, process: string|false}>
     */
    private array $originalEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $original) {
            $this->applyEnvironmentVariable($name, $original['env'], $original['server'], $original['process']);
        }
        $this->originalEnvironment = [];
    }

    public function testCreatesTheProcessInTheProjectDirectory(): void
    {
        $runner = new ComposerRunner(__DIR__);

        $process = $runner->createProcess(['composer', '--version'], 42);

        self::assertSame(__DIR__, $process->getWorkingDirectory());
        self::assertSame(42.0, $process->getTimeout());
        self::assertStringContainsString('--version', $process->getCommandLine());
    }

    public function testRunsComposerNonInteractively(): void
    {
        $process = (new ComposerRunner(__DIR__))->createProcess(['composer'], 30);

        self::assertSame('1', $process->getEnv()['COMPOSER_NO_INTERACTION']);
    }

    public function testDoesNotPassTheDebugKernelVerbosityOnToComposer(): void
    {
        $this->defineEnvironmentVariable('SHELL_VERBOSITY', '3');

        $process = (new ComposerRunner(__DIR__))->createProcess(['composer'], 30);

        self::assertSame('0', $process->getEnv()['SHELL_VERBOSITY']);
    }

    public function testWidensTheTerminalSoThatComposerDoesNotWrapErrorMessages(): void
    {
        $process = (new ComposerRunner(__DIR__))->createProcess(['composer'], 30);

        self::assertSame('1000', $process->getEnv()['COLUMNS']);
    }

    public function testKeepsAnExplicitlyConfiguredComposerHome(): void
    {
        $this->defineEnvironmentVariable('COMPOSER_HOME', __DIR__);

        $process = (new ComposerRunner(__DIR__))->createProcess(['composer'], 30);

        self::assertSame(__DIR__, $process->getEnv()['COMPOSER_HOME']);
    }

    public function testKeepsAComposerHomeDefinedInDotenvFiles(): void
    {
        $this->defineDotenvVariable('COMPOSER_HOME', __DIR__);

        $process = (new ComposerRunner(__DIR__))->createProcess(['composer'], 30);

        self::assertSame(__DIR__, $process->getEnv()['COMPOSER_HOME']);
    }

    public function testKeepsTheUserHomeSoComposerFindsTheGlobalAuthJson(): void
    {
        $this->defineEnvironmentVariable('COMPOSER_HOME', null);
        $this->defineEnvironmentVariable(self::HOME_VARIABLE, __DIR__);

        $env = (new ComposerRunner(__DIR__))->createProcess(['composer'], 30)->getEnv();

        self::assertArrayNotHasKey('COMPOSER_HOME', $env);
        self::assertSame(__DIR__, $env[self::HOME_VARIABLE]);
    }

    public function testProvidesAnExistingHomeWhenTheEnvironmentHasNone(): void
    {
        $this->defineEnvironmentVariable('COMPOSER_HOME', null);
        $this->defineEnvironmentVariable(self::HOME_VARIABLE, null);

        $projectDir = $this->createTemporaryProjectDirectory();

        try {
            $env = (new ComposerRunner($projectDir))->createProcess(['composer'], 30)->getEnv();

            // the process owner's home from passwd where available, a directory in the project otherwise
            $home = $env['COMPOSER_HOME'] ?? $env[self::HOME_VARIABLE] ?? null;
            self::assertIsString($home);
            self::assertDirectoryExists($home);
        } finally {
            $this->removeTemporaryProjectDirectory($projectDir);
        }
    }

    public function testKeepsTheFallbackHomeOutOfTheSharedTemporaryDirectory(): void
    {
        $this->defineEnvironmentVariable('COMPOSER_HOME', null);
        $this->defineEnvironmentVariable(self::HOME_VARIABLE, null);
        $projectDir = $this->createTemporaryProjectDirectory();

        try {
            $env = (new ComposerRunner($projectDir))->createProcess(['composer'], 30)->getEnv();

            if (!isset($env['COMPOSER_HOME'])) {
                self::markTestSkipped('The home directory of the process owner was resolved via passwd.');
            }

            $expectedHome = $projectDir . DIRECTORY_SEPARATOR . ComposerRunner::FALLBACK_HOME_DIRECTORY;
            self::assertSame($expectedHome, $env['COMPOSER_HOME']);
            self::assertSame($expectedHome, $env['HOME']);
            self::assertDirectoryExists($expectedHome);
        } finally {
            $this->removeTemporaryProjectDirectory($projectDir);
        }
    }

    public function testDisablesComposerPluginsWhileProbingForTheBinary(): void
    {
        $fakeComposerPhar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pringuin-health-fake-composer-' . bin2hex(random_bytes(4)) . '.phar';
        file_put_contents($fakeComposerPhar, '<?php echo in_array("--no-plugins", $argv, true) ? "Composer version 9.9.9\n" : "plugins enabled\n";');
        $this->defineDotenvVariable('COMPOSER_BINARY', $fakeComposerPhar);

        try {
            self::assertSame([PHP_BINARY, $fakeComposerPhar], (new ComposerRunner(__DIR__))->resolveCommand());
        } finally {
            unlink($fakeComposerPhar);
        }
    }

    public function testPrefersAComposerBinaryDefinedInDotenvFiles(): void
    {
        $fakeComposerPhar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pringuin-health-fake-composer-' . bin2hex(random_bytes(4)) . '.phar';
        file_put_contents($fakeComposerPhar, '<?php echo "Composer version 9.9.9\n";');
        $this->defineDotenvVariable('COMPOSER_BINARY', $fakeComposerPhar);

        try {
            self::assertSame([PHP_BINARY, $fakeComposerPhar], (new ComposerRunner(__DIR__))->resolveCommand());
        } finally {
            unlink($fakeComposerPhar);
        }
    }

    public function testRunsAComposerPharWithThePhpCliFromTheExecutableFinder(): void
    {
        // same executable as PHP_BINARY, spelled differently to prove where the path comes from
        $phpCli = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . '.' . DIRECTORY_SEPARATOR . basename(PHP_BINARY);
        $fakeComposerPhar = $this->createFakeComposerPhar();
        $this->defineDotenvVariable('COMPOSER_BINARY', $fakeComposerPhar);

        try {
            $command = (new ComposerRunner(__DIR__, $this->createPhpExecutableFinder($phpCli)))->resolveCommand();

            self::assertSame([$phpCli, $fakeComposerPhar], $command);
        } finally {
            unlink($fakeComposerPhar);
        }
    }

    public function testSkipsComposerPharCandidatesWhenNoPhpCliIsFound(): void
    {
        $fakeComposerPhar = $this->createFakeComposerPhar();
        $this->defineDotenvVariable('COMPOSER_BINARY', $fakeComposerPhar);

        try {
            $command = (new ComposerRunner(__DIR__, $this->createPhpExecutableFinder(false)))->resolveCommand();

            self::assertNotContains($fakeComposerPhar, $command ?? []);
        } finally {
            unlink($fakeComposerPhar);
        }
    }

    public function testProbesForTheComposerBinaryOnlyOnce(): void
    {
        $probeLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pringuin-health-probe-log-' . bin2hex(random_bytes(4));
        $countingComposerPhar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pringuin-health-counting-composer-' . bin2hex(random_bytes(4)) . '.phar';
        file_put_contents(
            $countingComposerPhar,
            '<?php file_put_contents(' . var_export($probeLog, true) . ', "probe\n", FILE_APPEND); echo "Composer version 9.9.9\n";'
        );
        $this->defineDotenvVariable('COMPOSER_BINARY', $countingComposerPhar);

        try {
            $runner = new ComposerRunner(__DIR__);
            $firstCommand = $runner->resolveCommand();
            $secondCommand = $runner->resolveCommand();

            self::assertSame([PHP_BINARY, $countingComposerPhar], $firstCommand);
            self::assertSame($firstCommand, $secondCommand);
            self::assertSame("probe\n", file_get_contents($probeLog));
        } finally {
            unlink($countingComposerPhar);
            if (is_file($probeLog)) {
                unlink($probeLog);
            }
        }
    }

    public function testResolvesTheComposerBinaryOfThisEnvironment(): void
    {
        $command = (new ComposerRunner(__DIR__))->resolveCommand();

        if ($command === null) {
            self::markTestSkipped('No composer binary available in this environment.');
        }

        self::assertNotSame([], $command);
        self::assertStringContainsString('composer', implode(' ', $command));
    }

    private function createFakeComposerPhar(): string
    {
        $fakeComposerPhar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pringuin-health-fake-composer-' . bin2hex(random_bytes(4)) . '.phar';
        file_put_contents($fakeComposerPhar, '<?php echo "Composer version 9.9.9\n";');

        return $fakeComposerPhar;
    }

    private function createPhpExecutableFinder(string|false $phpCli): PhpExecutableFinder
    {
        return new class ($phpCli) extends PhpExecutableFinder {
            public function __construct(
                private readonly string|false $phpCli,
            ) {
                parent::__construct();
            }

            public function find(bool $includeArgs = true): string|false
            {
                return $this->phpCli;
            }
        };
    }

    private function createTemporaryProjectDirectory(): string
    {
        $projectDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pringuin-health-project-' . bin2hex(random_bytes(4));
        mkdir($projectDir);

        return $projectDir;
    }

    private function removeTemporaryProjectDirectory(string $projectDir): void
    {
        $fallbackHome = $projectDir . DIRECTORY_SEPARATOR . ComposerRunner::FALLBACK_HOME_DIRECTORY;
        if (is_dir($fallbackHome)) {
            rmdir($fallbackHome);
            rmdir(dirname($fallbackHome));
        }
        rmdir($projectDir);
    }

    /**
     * Sets the variable in the process environment and the superglobals; null removes it everywhere.
     */
    private function defineEnvironmentVariable(string $name, ?string $value): void
    {
        $this->rememberEnvironmentVariable($name);
        $this->applyEnvironmentVariable($name, $value, $value, $value ?? false);
    }

    /**
     * Mimics Symfony Dotenv, which populates the superglobals but not the process environment.
     */
    private function defineDotenvVariable(string $name, string $value): void
    {
        $this->rememberEnvironmentVariable($name);
        $this->applyEnvironmentVariable($name, $value, $value, false);
    }

    private function rememberEnvironmentVariable(string $name): void
    {
        $this->originalEnvironment[$name] ??= [
            'env' => $_ENV[$name] ?? null,
            'server' => $_SERVER[$name] ?? null,
            'process' => getenv($name),
        ];
    }

    private function applyEnvironmentVariable(string $name, mixed $envValue, mixed $serverValue, string|false $processValue): void
    {
        if ($envValue === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $envValue;
        }

        if ($serverValue === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $serverValue;
        }

        putenv($processValue === false ? $name : $name . '=' . $processValue);
    }
}
