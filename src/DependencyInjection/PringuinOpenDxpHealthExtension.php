<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\DependencyInjection;

use Pringuin\OpenDxpHealthBundle\Check\HealthCheckInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

final class PringuinOpenDxpHealthExtension extends ConfigurableExtension
{
    public function getAlias(): string
    {
        return 'pringuin_opendxp_health';
    }

    protected function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(HealthCheckInterface::class)
            ->addTag('pringuin_opendxp_health.check');

        $container->setParameter('pringuin_opendxp_health.mail.enabled', $mergedConfig['mail']['enabled']);
        $container->setParameter('pringuin_opendxp_health.mail.recipient', $mergedConfig['mail']['recipient']);
        $container->setParameter('pringuin_opendxp_health.mail.min_status', $mergedConfig['mail']['min_status']);
        $container->setParameter('pringuin_opendxp_health.status_endpoint.enabled', $mergedConfig['status_endpoint']['enabled']);
        $container->setParameter('pringuin_opendxp_health.status_endpoint.token', $mergedConfig['status_endpoint']['token']);
        $container->setParameter('pringuin_opendxp_health.status_endpoint.expose_details', $mergedConfig['status_endpoint']['expose_details']);
        $container->setParameter('pringuin_opendxp_health.checks', $mergedConfig['checks']);

        foreach ($mergedConfig['checks'] as $checkName => $checkConfig) {
            foreach ($checkConfig as $key => $value) {
                if ($key === 'enabled') {
                    continue;
                }
                $container->setParameter(
                    sprintf('pringuin_opendxp_health.check.%s.%s', $checkName, $key),
                    $value
                );
            }
        }

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../config')
        );
        $loader->load('services.yaml');
    }
}
