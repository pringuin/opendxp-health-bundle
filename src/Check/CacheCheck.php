<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use OpenDxp\Cache;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Throwable;

/**
 * Verifies that the OpenDXP cache actually works by writing, reading back and
 * removing a probe value. A broken cache backend (e.g. an unreachable Redis
 * instance) otherwise only surfaces under load. The write is forced so the
 * round-trip is not deferred to shutdown.
 */
final class CacheCheck implements HealthCheckInterface
{
    public const string NAME = 'cache';

    private const string CACHE_KEY = 'pringuin_health_cache_probe';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Cache';
    }

    public function run(): CheckResult
    {
        if (!Cache::isEnabled()) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                'The OpenDXP cache is disabled - the round-trip test was skipped.',
            );
        }

        $probeValue = 'probe-' . bin2hex(random_bytes(8));

        try {
            $saved = Cache::save($probeValue, self::CACHE_KEY, [], 60, 0, true);
        } catch (Throwable $exception) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::CRITICAL,
                'Writing to the cache threw an exception.',
                [$exception::class . ': ' . $exception->getMessage()],
            );
        }

        if ($saved !== true) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::CRITICAL,
                'The cache backend rejected the write (Cache::save returned false).',
            );
        }

        try {
            $loaded = Cache::load(self::CACHE_KEY);
        } catch (Throwable $exception) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::CRITICAL,
                'Reading from the cache threw an exception.',
                [$exception::class . ': ' . $exception->getMessage()],
            );
        } finally {
            Cache::remove(self::CACHE_KEY);
        }

        if ($loaded !== $probeValue) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::CRITICAL,
                'The value read back from the cache did not match the value written.',
                [sprintf('Expected "%s", got "%s".', $probeValue, is_scalar($loaded) ? (string) $loaded : gettype($loaded))],
            );
        }

        return new CheckResult(
            $this->getName(),
            $this->getLabel(),
            HealthStatus::OK,
            'Cache write/read/remove round-trip succeeded.',
        );
    }
}
