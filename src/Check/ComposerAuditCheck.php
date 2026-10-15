<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Util\ComposerCommandException;
use Pringuin\OpenDxpHealthBundle\Util\ComposerJsonRunner;

/**
 * Runs "composer audit" against the project's composer.lock and reports
 * security advisories (critical) and abandoned packages (warning).
 */
final class ComposerAuditCheck implements HealthCheckInterface
{
    public const string NAME = 'composer_audit';

    public function __construct(
        private readonly ComposerJsonRunner $composer,
        private readonly int $processTimeout = 300,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Composer Security Audit';
    }

    public function run(): CheckResult
    {
        try {
            $data = $this->composer->run(['audit'], 'advisories', $this->processTimeout);
        } catch (ComposerCommandException $exception) {
            if ($exception->isCausedByNetworkFailure) {
                return new CheckResult(
                    $this->getName(),
                    $this->getLabel(),
                    HealthStatus::WARNING,
                    'The security advisory database could not be reached, the dependencies were not audited.',
                    $exception->details,
                );
            }

            return new CheckResult($this->getName(), $this->getLabel(), HealthStatus::ERROR, $exception->getMessage(), $exception->details);
        }

        $advisories = $data['advisories'];
        $abandoned = is_array($data['abandoned'] ?? null) ? $data['abandoned'] : [];

        $details = $this->buildAdvisoryDetails($advisories);
        $advisoryCount = count($details);

        foreach ($abandoned as $package => $replacement) {
            $details[] = sprintf(
                'Abandoned package: %s%s',
                $package,
                is_string($replacement) && $replacement !== '' ? ' (suggested replacement: ' . $replacement . ')' : ''
            );
        }

        if ($advisoryCount > 0) {
            $status = HealthStatus::CRITICAL;
            $summary = sprintf(
                '%d security advisor%s found across %d package%s.',
                $advisoryCount,
                $advisoryCount === 1 ? 'y' : 'ies',
                count($advisories),
                count($advisories) === 1 ? '' : 's'
            );
        } elseif (count($abandoned) > 0) {
            $status = HealthStatus::WARNING;
            $summary = sprintf('No security advisories, but %d abandoned package(s) in use.', count($abandoned));
        } else {
            $status = HealthStatus::OK;
            $summary = 'No security advisories found.';
        }

        return new CheckResult($this->getName(), $this->getLabel(), $status, $summary, $details);
    }

    /**
     * @param array<string, mixed> $advisories
     *
     * @return list<string>
     */
    private function buildAdvisoryDetails(array $advisories): array
    {
        $details = [];
        foreach ($advisories as $package => $packageAdvisories) {
            if (!is_array($packageAdvisories)) {
                continue;
            }
            foreach ($packageAdvisories as $advisory) {
                $advisory = is_array($advisory) ? $advisory : [];
                $parts = [(string) ($advisory['packageName'] ?? $package)];
                if (!empty($advisory['severity'])) {
                    $parts[] = 'severity: ' . $advisory['severity'];
                }
                if (!empty($advisory['cve'])) {
                    $parts[] = $advisory['cve'];
                }
                if (!empty($advisory['affectedVersions'])) {
                    $parts[] = 'affected: ' . $advisory['affectedVersions'];
                }
                if (!empty($advisory['title'])) {
                    $parts[] = (string) $advisory['title'];
                }
                if (!empty($advisory['link'])) {
                    $parts[] = (string) $advisory['link'];
                }
                $details[] = implode(' | ', $parts);
            }
        }

        return $details;
    }
}
