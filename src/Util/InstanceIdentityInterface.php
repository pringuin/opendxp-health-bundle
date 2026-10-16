<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Util;

/**
 * Identifies the OpenDXP instance in reports. Several instances can run on
 * the same server, so the main domain from the system settings identifies an
 * instance better than the server host name.
 */
interface InstanceIdentityInterface
{
    /**
     * @return string|null the main domain from the system settings, or null if none is configured
     */
    public function getMainDomain(): ?string;

    public function getHostname(): string;

    /**
     * @return string the main domain if configured, otherwise the server host name
     */
    public function getDisplayName(): string;
}
