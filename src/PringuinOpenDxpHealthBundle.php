<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle;

use OpenDxp\Extension\Bundle\AbstractOpenDxpBundle;
use OpenDxp\Extension\Bundle\Installer\InstallerInterface;
use OpenDxp\Extension\Bundle\OpenDxpBundleAdminClassicInterface;
use OpenDxp\Extension\Bundle\Traits\BundleAdminClassicTrait;
use OpenDxp\Extension\Bundle\Traits\PackageVersionTrait;
use Pringuin\OpenDxpHealthBundle\DependencyInjection\PringuinOpenDxpHealthExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

class PringuinOpenDxpHealthBundle extends AbstractOpenDxpBundle implements OpenDxpBundleAdminClassicInterface
{
    use BundleAdminClassicTrait;
    use PackageVersionTrait;

    public function getComposerPackageName(): string
    {
        return 'pringuin/opendxp-health-bundle';
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new PringuinOpenDxpHealthExtension();
        }

        return $this->extension;
    }

    public function getJsPaths(): array
    {
        return [
            '/bundles/pringuinopendxphealth/js/startup.js',
            '/bundles/pringuinopendxphealth/js/portlet.js',
        ];
    }

    public function getCssPaths(): array
    {
        return [
            '/bundles/pringuinopendxphealth/css/admin.css',
        ];
    }

    public function getInstaller(): ?InstallerInterface
    {
        return $this->container->get(Installer::class);
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
