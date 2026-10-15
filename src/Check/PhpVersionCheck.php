<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use DateTimeImmutable;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Throwable;

/**
 * Compares the running PHP version against its end-of-life date and warns
 * before, and reports critical after, the version stops receiving security
 * fixes. The built-in EOL dates (security support end, per php.net) can be
 * extended or overridden via configuration for versions released after this
 * bundle.
 */
final class PhpVersionCheck implements HealthCheckInterface
{
    public const string NAME = 'php_version';

    /** Security-support end dates per minor version (Y-m-d), per php.net. */
    private const array DEFAULT_EOL_DATES = [
        '7.4' => '2022-11-28',
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ];

    /**
     * @param array<string, string> $eolDates minor version (e.g. "8.3") => EOL date (Y-m-d); merged over the defaults
     */
    public function __construct(
        private readonly array $eolDates = [],
        private readonly int $warningDays = 90,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'PHP Version';
    }

    public function run(): CheckResult
    {
        $minorVersion = sprintf('%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
        $eolDates = [...self::DEFAULT_EOL_DATES, ...$this->eolDates];

        if (!isset($eolDates[$minorVersion])) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                sprintf(
                    'Running PHP %s. No EOL date is known for this version - add it under php_version.eol_dates.',
                    PHP_VERSION
                ),
            );
        }

        $eolDate = $this->parseDate($eolDates[$minorVersion]);
        if ($eolDate === null) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                sprintf('Configured EOL date "%s" for PHP %s is not a valid Y-m-d date.', $eolDates[$minorVersion], $minorVersion),
            );
        }

        $now = new DateTimeImmutable('today');
        $daysUntilEol = (int) $now->diff($eolDate)->format('%r%a');

        if ($daysUntilEol < 0) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::CRITICAL,
                sprintf(
                    'PHP %s reached end of life on %s (%d day(s) ago) and no longer receives security fixes.',
                    PHP_VERSION,
                    $eolDate->format('Y-m-d'),
                    abs($daysUntilEol)
                ),
            );
        }

        if ($daysUntilEol <= $this->warningDays) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                sprintf(
                    'PHP %s reaches end of life on %s (in %d day(s)). Plan the upgrade.',
                    PHP_VERSION,
                    $eolDate->format('Y-m-d'),
                    $daysUntilEol
                ),
            );
        }

        return new CheckResult(
            $this->getName(),
            $this->getLabel(),
            HealthStatus::OK,
            sprintf('PHP %s is supported until %s (%d day(s) remaining).', PHP_VERSION, $eolDate->format('Y-m-d'), $daysUntilEol),
        );
    }

    private function parseDate(string $date): ?DateTimeImmutable
    {
        try {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        } catch (Throwable) {
            return null;
        }

        return $parsed === false ? null : $parsed;
    }
}
