<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use OpenDxp\Model\User;
use OpenDxp\Model\User\Listing;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;

/**
 * Reports active admin users that have not enabled two-factor authentication.
 * Admin accounts are the most valuable target on any OpenDXP installation.
 */
final class AdminTwoFactorCheck implements HealthCheckInterface
{
    public const string NAME = 'admin_two_factor';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Admin Two-Factor Authentication';
    }

    public function run(): CheckResult
    {
        $listing = new Listing();
        $listing->setCondition("type = 'user' AND admin = 1 AND active = 1");

        $adminsWithoutTwoFactor = [];
        $adminCount = 0;
        foreach ($listing as $user) {
            if (!$user instanceof User) {
                continue;
            }
            $adminCount++;
            if (!$user->getTwoFactorAuthentication('enabled')) {
                $adminsWithoutTwoFactor[] = $user->getName();
            }
        }

        if ($adminsWithoutTwoFactor !== []) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                sprintf(
                    '%d of %d active admin user(s) have not enabled two-factor authentication.',
                    count($adminsWithoutTwoFactor),
                    $adminCount
                ),
                array_map(
                    static fn (?string $name): string => 'Admin without 2FA: ' . ($name ?? '(unnamed)'),
                    $adminsWithoutTwoFactor
                ),
            );
        }

        return new CheckResult(
            $this->getName(),
            $this->getLabel(),
            HealthStatus::OK,
            sprintf('All %d active admin user(s) have two-factor authentication enabled.', $adminCount),
        );
    }
}
