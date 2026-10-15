<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\DependencyInjection;

use Pringuin\OpenDxpHealthBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    public function testEmailLogDefaultsDisableTheSendVolumeCheck(): void
    {
        $config = $this->process([]);

        self::assertSame(
            ['enabled' => true, 'max_age_hours' => 24, 'max_emails_per_window' => 0, 'window_minutes' => 60],
            $config['checks']['email_log']
        );
    }

    public function testEmailLogAcceptsASendVolumeThreshold(): void
    {
        $config = $this->process([
            'checks' => ['email_log' => ['max_emails_per_window' => 20, 'window_minutes' => 60]],
        ]);

        self::assertSame(20, $config['checks']['email_log']['max_emails_per_window']);
        self::assertSame(60, $config['checks']['email_log']['window_minutes']);
    }

    public function testEmailLogRejectsAWindowLongerThanTheObservedPeriod(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/send volume window longer than max_age_hours/');

        $this->process([
            'checks' => ['email_log' => ['max_age_hours' => 1, 'max_emails_per_window' => 20, 'window_minutes' => 120]],
        ]);
    }

    public function testEmailLogIgnoresTheWindowLengthWhenTheVolumeCheckIsDisabled(): void
    {
        $config = $this->process([
            'checks' => ['email_log' => ['max_age_hours' => 1, 'max_emails_per_window' => 0, 'window_minutes' => 120]],
        ]);

        self::assertSame(120, $config['checks']['email_log']['window_minutes']);
    }

    public function testEmailLogRejectsANegativeSendVolumeThreshold(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['checks' => ['email_log' => ['max_emails_per_window' => -1]]]);
    }

    public function testEmailLogRejectsAZeroLengthWindow(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['checks' => ['email_log' => ['window_minutes' => 0]]]);
    }

    public function testMailIsSentFromWarningUpwardsByDefault(): void
    {
        $config = $this->process([]);

        self::assertSame('warning', $config['mail']['min_status']);
    }

    public function testMailAcceptsAStricterThreshold(): void
    {
        $config = $this->process(['mail' => ['min_status' => 'critical']]);

        self::assertSame('critical', $config['mail']['min_status']);
    }

    public function testMailRejectsAnUnknownThreshold(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['mail' => ['min_status' => 'urgent']]);
    }

    public function testStatusEndpointIsDisabledByDefault(): void
    {
        $config = $this->process([]);

        self::assertFalse($config['status_endpoint']['enabled']);
        self::assertNull($config['status_endpoint']['token']);
    }

    public function testHttpSelfCheckFollowsRedirectsByDefault(): void
    {
        self::assertSame(20, $this->process([])['checks']['http_self_check']['max_redirects']);
    }

    public function testHttpSelfCheckRejectsANegativeRedirectLimit(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['checks' => ['http_self_check' => ['max_redirects' => -1]]]);
    }

    public function testStatusEndpointHidesTheReportDetailsByDefault(): void
    {
        $config = $this->process(['status_endpoint' => ['enabled' => true, 'token' => str_repeat('a', 32)]]);

        self::assertFalse($config['status_endpoint']['expose_details']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
