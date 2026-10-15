<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Util\ComposerCommandException;
use Pringuin\OpenDxpHealthBundle\Util\ComposerJsonRunner;

/**
 * Runs "composer outdated --direct" and reports how far the directly required
 * packages lag behind. Complements the composer_audit check, which only flags
 * known security advisories: this one surfaces update lag, with special
 * attention to packages watched via the "watch_packages" config (e.g.
 * open-dxp/opendxp itself).
 */
final class ComposerOutdatedCheck implements HealthCheckInterface
{
    public const string NAME = 'composer_outdated';

    /**
     * @param list<string> $watchPackages
     */
    public function __construct(
        private readonly ComposerJsonRunner $composer,
        private readonly array $watchPackages = ['open-dxp/opendxp'],
        private readonly int $processTimeout = 300,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Composer Outdated';
    }

    public function run(): CheckResult
    {
        try {
            $data = $this->composer->run(['outdated', '--direct'], 'installed', $this->processTimeout);
        } catch (ComposerCommandException $exception) {
            if ($exception->isCausedByNetworkFailure) {
                return new CheckResult(
                    $this->getName(),
                    $this->getLabel(),
                    HealthStatus::WARNING,
                    'The package repositories could not be reached, the dependencies were not compared.',
                    $exception->details,
                );
            }

            return new CheckResult($this->getName(), $this->getLabel(), HealthStatus::ERROR, $exception->getMessage(), $exception->details);
        }

        return $this->buildResult($data['installed']);
    }

    /**
     * @param list<mixed> $installed
     */
    private function buildResult(array $installed): CheckResult
    {
        $majorBehind = [];
        $minorOrPatchBehind = [];
        $watchedOutdated = [];

        foreach ($installed as $package) {
            if (!is_array($package) || !isset($package['name'])) {
                continue;
            }
            $name = (string) $package['name'];
            $current = (string) ($package['version'] ?? '');
            $latest = (string) ($package['latest'] ?? '');
            if ($latest === '' || $current === $latest) {
                continue;
            }

            $line = sprintf('%s: %s -> %s', $name, $current, $latest);
            $isMajor = $this->isMajorUpgrade($current, $latest);
            if ($isMajor) {
                $majorBehind[] = $line . ' (major)';
            } else {
                $minorOrPatchBehind[] = $line;
            }

            if (in_array($name, $this->watchPackages, true)) {
                $watchedOutdated[] = $line . ($isMajor ? ' (major)' : '');
            }
        }

        $details = [...$watchedOutdated !== [] ? ['Watched packages:'] : [], ...$watchedOutdated];
        $details = [...$details, ...$majorBehind, ...$minorOrPatchBehind];

        $totalOutdated = count($majorBehind) + count($minorOrPatchBehind);
        if ($totalOutdated === 0) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::OK,
                'All directly required packages are up to date.',
            );
        }

        // a watched package (e.g. OpenDXP core) or any major upgrade behind is a warning
        $status = ($watchedOutdated !== [] || $majorBehind !== [])
            ? HealthStatus::WARNING
            : HealthStatus::OK;

        $summary = sprintf(
            '%d direct dependenc%s outdated (%d major upgrade(s) behind%s).',
            $totalOutdated,
            $totalOutdated === 1 ? 'y' : 'ies',
            count($majorBehind),
            $watchedOutdated !== [] ? ', watched package(s) affected' : ''
        );

        return new CheckResult($this->getName(), $this->getLabel(), $status, $summary, $details);
    }

    private function isMajorUpgrade(string $current, string $latest): bool
    {
        $currentMajor = $this->majorComponent($current);
        $latestMajor = $this->majorComponent($latest);
        if ($currentMajor === null || $latestMajor === null) {
            return false;
        }

        return $latestMajor > $currentMajor;
    }

    private function majorComponent(string $version): ?int
    {
        if (preg_match('/(\d+)/', ltrim($version, 'vV'), $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
