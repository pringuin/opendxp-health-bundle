<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Service;

use JsonException;
use Pringuin\OpenDxpHealthBundle\Report\HealthReport;
use Pringuin\OpenDxpHealthBundle\Report\InvalidReportDataException;
use Pringuin\OpenDxpHealthBundle\Storage\SettingsStorageInterface;

/**
 * Persists the last health report so it can be displayed in the admin UI at
 * any time.
 */
final class HealthReportStorage
{
    private const string STORAGE_ID = 'last_report';

    public function __construct(
        private readonly SettingsStorageInterface $settingsStorage,
    ) {
    }

    public function save(HealthReport $report): void
    {
        $this->settingsStorage->set(
            self::STORAGE_ID,
            json_encode($report->toArray(), JSON_THROW_ON_ERROR)
        );
    }

    /**
     * An unreadable stored report counts as no report: the admin UI then asks for a new run and
     * the status endpoint answers 503 instead of failing with a server error.
     */
    public function getLastReport(): ?HealthReport
    {
        $storedReport = $this->settingsStorage->get(self::STORAGE_ID);
        if ($storedReport === null) {
            return null;
        }

        try {
            $data = json_decode($storedReport, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? HealthReport::fromArray($data) : null;
        } catch (JsonException|InvalidReportDataException) {
            return null;
        }
    }
}
