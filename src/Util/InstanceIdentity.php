<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Util;

use OpenDxp\Config;
use Throwable;

final class InstanceIdentity implements InstanceIdentityInterface
{
    public function getMainDomain(): ?string
    {
        try {
            $mainDomain = Config::getSystemConfiguration('general')['domain'] ?? '';
        } catch (Throwable) {
            return null;
        }

        return is_string($mainDomain) && $mainDomain !== '' ? $mainDomain : null;
    }

    public function getHostname(): string
    {
        return gethostname() ?: 'unknown-host';
    }

    public function getDisplayName(): string
    {
        return $this->getMainDomain() ?? $this->getHostname();
    }
}
