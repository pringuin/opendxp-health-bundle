<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use Doctrine\DBAL\Connection;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;

/**
 * Reports emails whose delivery failed, based on the error column of the
 * OpenDXP email log (email_log table, written by OpenDxp\Mail).
 *
 * Optionally also reports an unusually high send volume: the check looks for
 * the busiest sliding time window within the observed period, which catches
 * mail loops and runaway jobs that a plain "emails since the last run" counter
 * would miss.
 */
final class EmailLogCheck implements HealthCheckInterface
{
    public const string NAME = 'email_log';

    private const string TABLE_NAME = 'email_log';

    private const int RECENT_ENTRIES_LIMIT = 5;

    private const int ERROR_EXCERPT_LENGTH = 160;

    private const string DATE_FORMAT = 'Y-m-d H:i:s T';

    public function __construct(
        private readonly Connection $connection,
        private readonly int $maxAgeHours = 24,
        private readonly int $maxEmailsPerWindow = 0,
        private readonly int $windowMinutes = 60,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Email Delivery';
    }

    public function run(): CheckResult
    {
        if (!$this->connection->createSchemaManager()->tablesExist([self::TABLE_NAME])) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                sprintf('Table "%s" does not exist. Disable this check if the email log is not available.', self::TABLE_NAME),
            );
        }

        // sentDate is a unix timestamp
        $threshold = time() - $this->maxAgeHours * 3600;

        $status = HealthStatus::OK;
        $summaries = [];
        $details = [];

        $failedCount = $this->countFailedDeliveries($threshold);
        if ($failedCount > 0) {
            $status = HealthStatus::WARNING;
            $summaries[] = sprintf(
                '%d email delivery/deliveries failed within the last %d hour(s). '
                . 'Note: if the mailer is broken, this health report may not arrive by email either - check the admin UI.',
                $failedCount,
                $this->maxAgeHours
            );
            $details = [...$details, ...$this->describeFailedDeliveries($threshold, $failedCount)];
        } else {
            $summaries[] = sprintf('No failed email deliveries within the last %d hour(s).', $this->maxAgeHours);
        }

        if ($this->maxEmailsPerWindow > 0) {
            $volumeResult = $this->checkSendVolume($threshold);
            if ($volumeResult['exceeded']) {
                $status = HealthStatus::WARNING;
            }
            $summaries[] = $volumeResult['summary'];
            $details = [...$details, ...$volumeResult['details']];
        }

        return new CheckResult(
            $this->getName(),
            $this->getLabel(),
            $status,
            implode(' ', $summaries),
            $details,
        );
    }

    private function countFailedDeliveries(int $threshold): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE_NAME
            . " WHERE sentDate >= :threshold AND error IS NOT NULL AND error != ''",
            ['threshold' => $threshold]
        );
    }

    /**
     * @return list<string>
     */
    private function describeFailedDeliveries(int $threshold, int $failedCount): array
    {
        $details = [];
        $recentFailures = $this->connection->fetchAllAssociative(
            'SELECT sentDate, `to`, subject, error FROM ' . self::TABLE_NAME
            . " WHERE sentDate >= :threshold AND error IS NOT NULL AND error != ''"
            . ' ORDER BY sentDate DESC LIMIT ' . self::RECENT_ENTRIES_LIMIT,
            ['threshold' => $threshold]
        );
        foreach ($recentFailures as $failure) {
            $error = trim((string) $failure['error']);
            if (mb_strlen($error) > self::ERROR_EXCERPT_LENGTH) {
                $error = mb_substr($error, 0, self::ERROR_EXCERPT_LENGTH) . '...';
            }
            $details[] = sprintf(
                '[%s] to %s | %s | %s',
                date(self::DATE_FORMAT, (int) $failure['sentDate']),
                (string) ($failure['to'] ?? '-'),
                (string) ($failure['subject'] ?? '-'),
                $error
            );
        }

        if ($failedCount > self::RECENT_ENTRIES_LIMIT) {
            $details[] = sprintf('... and %d more failed delivery/deliveries.', $failedCount - self::RECENT_ENTRIES_LIMIT);
        }

        return $details;
    }

    /**
     * @return array{exceeded: bool, summary: string, details: list<string>}
     */
    private function checkSendVolume(int $threshold): array
    {
        $totalCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE_NAME . ' WHERE sentDate >= :threshold',
            ['threshold' => $threshold]
        );

        // The total is an upper bound for every single window, so the window
        // scan is only needed once the total exceeds the threshold at all.
        $peakWindow = $totalCount > $this->maxEmailsPerWindow ? $this->findPeakWindow($threshold) : null;

        if ($peakWindow === null || $peakWindow['emailCount'] <= $this->maxEmailsPerWindow) {
            return [
                'exceeded' => false,
                'summary' => sprintf(
                    'Send volume is within the limit of %d email(s) per %d minute(s) (%d sent in the last %d hour(s)).',
                    $this->maxEmailsPerWindow,
                    $this->windowMinutes,
                    $totalCount,
                    $this->maxAgeHours
                ),
                'details' => [],
            ];
        }

        $windowStart = $peakWindow['windowStart'];
        $windowEnd = $windowStart + $this->windowMinutes * 60;

        $details = [sprintf(
            'Peak window %s - %s: %d email(s) sent (%d in the last %d hour(s) in total).',
            date(self::DATE_FORMAT, $windowStart),
            date(self::DATE_FORMAT, $windowEnd),
            $peakWindow['emailCount'],
            $totalCount,
            $this->maxAgeHours
        )];

        $frequentSubjects = $this->findFrequentSubjects($windowStart, $windowEnd);
        if ($frequentSubjects !== []) {
            $details[] = 'Most frequent subjects in the peak window: ' . implode(', ', $frequentSubjects);
        }

        return [
            'exceeded' => true,
            'summary' => sprintf(
                '%d email(s) were sent within %d minute(s), more than the configured maximum of %d - '
                . 'check for a mail loop or a runaway job.',
                $peakWindow['emailCount'],
                $this->windowMinutes,
                $this->maxEmailsPerWindow
            ),
            'details' => $details,
        ];
    }

    /**
     * Busiest sliding window within the observed period: for every logged email
     * the number of emails sent from that point up to one window length later.
     *
     * @return array{windowStart: int, emailCount: int}|null
     */
    private function findPeakWindow(int $threshold): ?array
    {
        // The window frame offset must be a literal; it is an int, so it cannot be injected.
        $windowSeconds = $this->windowMinutes * 60;

        $peak = $this->connection->fetchAssociative(
            'SELECT windowStart, emailCount FROM ('
            . ' SELECT sentDate AS windowStart,'
            . ' COUNT(*) OVER (ORDER BY sentDate RANGE BETWEEN CURRENT ROW AND ' . $windowSeconds . ' FOLLOWING) AS emailCount'
            . ' FROM ' . self::TABLE_NAME
            . ' WHERE sentDate >= :threshold'
            . ') AS windowCounts ORDER BY emailCount DESC, windowStart ASC LIMIT 1',
            ['threshold' => $threshold]
        );

        if ($peak === false) {
            return null;
        }

        return [
            'windowStart' => (int) $peak['windowStart'],
            'emailCount' => (int) $peak['emailCount'],
        ];
    }

    /**
     * @return list<string>
     */
    private function findFrequentSubjects(int $windowStart, int $windowEnd): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT subject, COUNT(*) AS emailCount FROM ' . self::TABLE_NAME
            . ' WHERE sentDate >= :windowStart AND sentDate <= :windowEnd'
            . ' GROUP BY subject ORDER BY emailCount DESC LIMIT ' . self::RECENT_ENTRIES_LIMIT,
            ['windowStart' => $windowStart, 'windowEnd' => $windowEnd]
        );

        $subjects = [];
        foreach ($rows as $row) {
            $subjects[] = sprintf('%dx "%s"', (int) $row['emailCount'], (string) ($row['subject'] ?? '-'));
        }

        return $subjects;
    }
}
