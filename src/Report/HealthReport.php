<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Report;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;

final readonly class HealthReport
{
    /**
     * @param list<CheckResult> $results
     */
    public function __construct(
        public DateTimeImmutable $createdAt,
        public array $results,
    ) {
    }

    public function getOverallStatus(): HealthStatus
    {
        $overall = HealthStatus::OK;
        foreach ($this->results as $result) {
            if ($result->status->severity() > $overall->severity()) {
                $overall = $result->status;
            }
        }

        return $overall;
    }

    public function hasIssues(): bool
    {
        return $this->getOverallStatus()->isIssue();
    }

    public function countIssues(): int
    {
        return count(array_filter(
            $this->results,
            static fn (CheckResult $result): bool => $result->status->isIssue()
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'createdAt' => $this->createdAt->format(DateTimeInterface::ATOM),
            'overallStatus' => $this->getOverallStatus()->value,
            'results' => array_map(
                static fn (CheckResult $result): array => $result->toArray(),
                $this->results
            ),
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidReportDataException when the data does not describe a report
     */
    public static function fromArray(array $data): self
    {
        if (!is_string($data['createdAt'] ?? null)) {
            throw new InvalidReportDataException('The report has no "createdAt" string.');
        }
        try {
            $createdAt = new DateTimeImmutable($data['createdAt']);
        } catch (Exception $exception) {
            throw new InvalidReportDataException(sprintf('The report date "%s" is invalid.', $data['createdAt']), 0, $exception);
        }

        $results = $data['results'] ?? [];
        if (!is_array($results)) {
            throw new InvalidReportDataException('The report results are not a list.');
        }

        return new self(
            $createdAt,
            array_values(array_map(
                static fn (mixed $result): CheckResult => is_array($result)
                    ? CheckResult::fromArray($result)
                    : throw new InvalidReportDataException('A report result is not an object.'),
                $results
            )),
        );
    }
}
