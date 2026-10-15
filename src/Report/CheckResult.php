<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Report;

final readonly class CheckResult
{
    /**
     * @param list<string> $details
     */
    public function __construct(
        public string $checkName,
        public string $checkLabel,
        public HealthStatus $status,
        public string $summary,
        public array $details = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'checkName' => $this->checkName,
            'checkLabel' => $this->checkLabel,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'details' => $this->details,
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidReportDataException when the data does not describe a check result
     */
    public static function fromArray(array $data): self
    {
        foreach (['checkName', 'checkLabel', 'status', 'summary'] as $requiredKey) {
            if (!is_string($data[$requiredKey] ?? null)) {
                throw new InvalidReportDataException(sprintf('The check result has no "%s" string.', $requiredKey));
            }
        }

        $status = HealthStatus::tryFrom($data['status'])
            ?? throw new InvalidReportDataException(sprintf('Unknown check status "%s".', $data['status']));

        $details = $data['details'] ?? [];
        if (!is_array($details)) {
            throw new InvalidReportDataException('The check result details are not a list.');
        }
        foreach ($details as $detail) {
            if (!is_scalar($detail)) {
                throw new InvalidReportDataException('A check result detail is not a scalar value.');
            }
        }

        return new self(
            $data['checkName'],
            $data['checkLabel'],
            $status,
            $data['summary'],
            array_values(array_map('strval', $details)),
        );
    }
}
