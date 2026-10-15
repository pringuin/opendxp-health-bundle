<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Util;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Resolves the composer binary by probing the usual locations with "--version".
 */
final class ComposerRunner implements ComposerRunnerInterface
{
    public const string FALLBACK_HOME_DIRECTORY = 'var' . DIRECTORY_SEPARATOR . 'pringuin-health-composer-home';

    private readonly PhpExecutableFinder $phpExecutableFinder;

    private bool $isCommandResolved = false;

    /** @var list<string>|null */
    private ?array $resolvedCommand = null;

    public function __construct(
        private readonly string $projectDir,
        ?PhpExecutableFinder $phpExecutableFinder = null,
    ) {
        $this->phpExecutableFinder = $phpExecutableFinder ?? new PhpExecutableFinder();
    }

    /**
     * The result is kept for the lifetime of the service, so the checks of one run share a single
     * probe instead of starting several "composer --version" processes each.
     *
     * @return list<string>|null argv prefix of a working composer command, or null if none was found
     */
    public function resolveCommand(): ?array
    {
        if (!$this->isCommandResolved) {
            $this->resolvedCommand = $this->probeCommand();
            $this->isCommandResolved = true;
        }

        return $this->resolvedCommand;
    }

    /**
     * @return list<string>|null
     */
    private function probeCommand(): ?array
    {
        $candidates = [];
        // not PHP_BINARY: under php-fpm it points to the fpm binary, which cannot run a phar
        $phpBinary = $this->phpExecutableFinder->find(false);

        $binary = $this->readEnvironmentVariable('COMPOSER_BINARY');
        if ($binary !== null) {
            $isPhar = str_contains($binary, '.phar') && is_file($binary);
            if (!$isPhar) {
                $candidates[] = [$binary];
            } elseif ($phpBinary !== false) {
                $candidates[] = [$phpBinary, $binary];
            }
        }

        $candidates[] = ['composer'];

        if ($phpBinary !== false && is_file($this->projectDir . '/composer.phar')) {
            $candidates[] = [$phpBinary, $this->projectDir . '/composer.phar'];
        }

        foreach (['/usr/local/bin/composer', '/usr/bin/composer', '/opt/composer/composer'] as $path) {
            if (is_file($path)) {
                $candidates[] = [$path];
            }
        }

        foreach ($candidates as $candidate) {
            $process = $this->createProcess([...$candidate, '--version', '--no-interaction', '--no-plugins'], 30);
            $process->run();
            if ($process->isSuccessful() && stripos($process->getOutput(), 'composer') !== false) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<string> $command
     */
    public function createProcess(array $command, int $timeout): Process
    {
        return new Process($command, $this->projectDir, $this->buildEnvironment(), null, (float) $timeout);
    }

    /**
     * @return array<string, string>
     */
    private function buildEnvironment(): array
    {
        $env = [
            'COMPOSER_NO_INTERACTION' => '1',
            // the Symfony kernel exports SHELL_VERBOSITY=3 in debug mode; composer is a Symfony
            // console application and would inherit it as -vvv, flooding the error output
            'SHELL_VERBOSITY' => '0',
            // without a terminal, composer wraps error messages at 80 columns, splitting URLs across lines
            'COLUMNS' => '1000',
        ];

        // composer derives its home from COMPOSER_HOME, otherwise from HOME (APPDATA on Windows). That home
        // holds the global auth.json with the credentials for private repositories, so it must not be replaced.
        $composerHome = $this->readEnvironmentVariable('COMPOSER_HOME');
        if ($composerHome !== null) {
            $env['COMPOSER_HOME'] = $composerHome;

            return $env;
        }

        // bare php-fpm (clear_env) or cron environments may lack HOME; the passwd entry of the process
        // owner still points to the home directory composer uses when run by that user from a shell
        $homeVariable = PHP_OS_FAMILY === 'Windows' ? 'APPDATA' : 'HOME';
        $home = $this->readEnvironmentVariable($homeVariable);
        if ($home === null || !is_dir($home)) {
            $home = $this->resolveProcessOwnerHome();
        }
        if ($home !== null) {
            $env[$homeVariable] = $home;

            return $env;
        }

        // not below the shared temp directory: another local user could create it first and place
        // global composer plugins there, which composer would execute as the PHP process user
        $fallbackHome = $this->projectDir . DIRECTORY_SEPARATOR . self::FALLBACK_HOME_DIRECTORY;
        if (!is_dir($fallbackHome)) {
            @mkdir($fallbackHome, 0700, true);
        }
        $env['COMPOSER_HOME'] = $fallbackHome;
        $env['HOME'] = $fallbackHome;

        return $env;
    }

    private function resolveProcessOwnerHome(): ?string
    {
        if (!function_exists('posix_getpwuid') || !function_exists('posix_geteuid')) {
            return null;
        }

        $passwdEntry = posix_getpwuid(posix_geteuid());
        $home = is_array($passwdEntry) ? ($passwdEntry['dir'] ?? null) : null;

        return is_string($home) && $home !== '' && is_dir($home) ? $home : null;
    }

    /**
     * Same lookup order as Symfony's %env()% processor: Symfony Dotenv only populates $_ENV and
     * $_SERVER, so variables defined in .env.local are invisible to getenv().
     */
    private function readEnvironmentVariable(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
