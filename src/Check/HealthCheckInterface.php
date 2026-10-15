<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use Pringuin\OpenDxpHealthBundle\Report\CheckResult;

/**
 * Implementations are auto-tagged with "pringuin_opendxp_health.check" and picked up
 * by the HealthCheckRunner. The name must match the config key under
 * pringuin_opendxp_health.checks to allow enabling/disabling via configuration.
 */
interface HealthCheckInterface
{
    public function getName(): string;

    public function getLabel(): string;

    public function run(): CheckResult;
}
