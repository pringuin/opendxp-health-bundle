<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;

/**
 * Guards against running a production system with debug mode enabled or in an
 * unexpected Symfony environment (e.g. APP_ENV=dev after a botched deployment).
 * On dev/staging systems, override expected_environment or disable this check
 * in the environment-specific configuration.
 */
final class EnvironmentCheck implements HealthCheckInterface
{
    public const string NAME = 'environment';

    public function __construct(
        private readonly string $environment,
        private readonly bool $debug,
        private readonly string $expectedEnvironment = 'prod',
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Environment & Debug Mode';
    }

    public function run(): CheckResult
    {
        $issues = [];

        if ($this->environment !== $this->expectedEnvironment) {
            $issues[] = sprintf(
                'Kernel environment is "%s", expected "%s" (APP_ENV). Debug tools and verbose errors may be exposed.',
                $this->environment,
                $this->expectedEnvironment
            );
        }

        if ($this->debug) {
            $issues[] = 'Debug mode is enabled (APP_DEBUG). Stack traces and configuration details may be exposed to visitors.';
        }

        if ($issues !== []) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::CRITICAL,
                'The system does not run with the expected production settings.',
                $issues,
            );
        }

        return new CheckResult(
            $this->getName(),
            $this->getLabel(),
            HealthStatus::OK,
            sprintf('Running in environment "%s" with debug mode disabled.', $this->environment),
        );
    }
}
