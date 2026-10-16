<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Double;

use Pringuin\OpenDxpHealthBundle\Util\InstanceIdentityInterface;

final class FixedInstanceIdentity implements InstanceIdentityInterface
{
    public function __construct(
        private readonly ?string $mainDomain = null,
        private readonly string $hostname = 'test-host',
    ) {
    }

    public function getMainDomain(): ?string
    {
        return $this->mainDomain;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function getDisplayName(): string
    {
        return $this->mainDomain ?? $this->hostname;
    }
}
