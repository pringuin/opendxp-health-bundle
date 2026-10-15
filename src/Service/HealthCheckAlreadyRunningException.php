<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Service;

use RuntimeException;

final class HealthCheckAlreadyRunningException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The health checks are already running (triggered from the admin UI or the command). Try again once that run has finished.');
    }
}
