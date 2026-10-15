<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Report;

enum HealthStatus: string
{
    case OK = 'ok';
    case WARNING = 'warning';
    case CRITICAL = 'critical';

    /** The check itself could not be executed (e.g. composer binary missing). */
    case ERROR = 'error';

    public function severity(): int
    {
        return match ($this) {
            self::OK => 0,
            self::WARNING => 1,
            self::CRITICAL => 2,
            self::ERROR => 3,
        };
    }

    public function isIssue(): bool
    {
        return $this !== self::OK;
    }

    /** Whether this status is at least as severe as the given threshold. */
    public function isAtLeast(self $threshold): bool
    {
        return $this->severity() >= $threshold->severity();
    }
}
