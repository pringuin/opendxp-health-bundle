<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\DependencyInjection;

use Pringuin\OpenDxpHealthBundle\Check\HealthCheckInterface;
use Pringuin\OpenDxpHealthBundle\Check\TableGrowthCheck;
use Pringuin\OpenDxpHealthBundle\DependencyInjection\PringuinOpenDxpHealthExtension;
use Pringuin\OpenDxpHealthBundle\Storage\OpenDxpSettingsStorage;
use Pringuin\OpenDxpHealthBundle\Storage\SettingsStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(PringuinOpenDxpHealthExtension::class)]
final class PringuinOpenDxpHealthExtensionTest extends TestCase
{
    public function testTurnsEveryCheckOptionIntoAContainerParameter(): void
    {
        $container = $this->load();

        self::assertSame(24, $container->getParameter('pringuin_opendxp_health.check.email_log.max_age_hours'));
        self::assertSame(0, $container->getParameter('pringuin_opendxp_health.check.email_log.max_emails_per_window'));
        self::assertSame(60, $container->getParameter('pringuin_opendxp_health.check.email_log.window_minutes'));
    }

    public function testDoesNotCreateParametersForTheEnabledFlag(): void
    {
        $container = $this->load();

        self::assertFalse($container->hasParameter('pringuin_opendxp_health.check.email_log.enabled'));
        self::assertTrue($container->hasParameter('pringuin_opendxp_health.checks'));
    }

    public function testExposesTheMailThresholdAsAParameter(): void
    {
        self::assertSame('warning', $this->load()->getParameter('pringuin_opendxp_health.mail.min_status'));

        $container = $this->load(['mail' => ['min_status' => 'critical']]);

        self::assertSame('critical', $container->getParameter('pringuin_opendxp_health.mail.min_status'));
    }

    public function testPassesTheConfiguredValuesThrough(): void
    {
        $container = $this->load([
            'checks' => ['email_log' => ['max_emails_per_window' => 20, 'window_minutes' => 30]],
        ]);

        self::assertSame(20, $container->getParameter('pringuin_opendxp_health.check.email_log.max_emails_per_window'));
        self::assertSame(30, $container->getParameter('pringuin_opendxp_health.check.email_log.window_minutes'));
    }

    /**
     * Guards against typos in services.yaml: a service argument referencing a
     * parameter the extension never defines only fails when the kernel boots.
     */
    public function testEveryParameterReferencedByAServiceExists(): void
    {
        $container = $this->load();

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            foreach ($definition->getArguments() as $argumentName => $argument) {
                if (!is_string($argument) || preg_match('/^%([^%]+)%$/', $argument, $matches) !== 1) {
                    continue;
                }

                self::assertTrue(
                    $container->hasParameter($matches[1]),
                    sprintf('Service "%s" references the undefined parameter "%s" for %s.', $serviceId, $matches[1], $argumentName)
                );
            }
        }
    }

    public function testAliasesTheSettingsStorageToTheOpenDxpImplementation(): void
    {
        $container = $this->load();

        self::assertTrue($container->hasAlias(SettingsStorageInterface::class));
        self::assertSame(OpenDxpSettingsStorage::class, (string) $container->getAlias(SettingsStorageInterface::class));
        self::assertTrue($container->hasDefinition(OpenDxpSettingsStorage::class));
    }

    public function testTableGrowthCheckReceivesTheConfiguredTables(): void
    {
        $container = $this->load();

        self::assertSame(
            '%pringuin_opendxp_health.check.table_growth.tables%',
            $container->getDefinition(TableGrowthCheck::class)->getArgument('$tables')
        );
    }

    public function testChecksAreAutoconfiguredWithTheCheckTag(): void
    {
        $container = $this->load();
        $autoconfigured = $container->getAutoconfiguredInstanceof();

        self::assertArrayHasKey(HealthCheckInterface::class, $autoconfigured);
        self::assertArrayHasKey('pringuin_opendxp_health.check', $autoconfigured[HealthCheckInterface::class]->getTags());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setResourceTracking(false);
        $container->setParameter('kernel.project_dir', __DIR__);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);

        (new PringuinOpenDxpHealthExtension())->load([$config], $container);

        return $container;
    }
}
