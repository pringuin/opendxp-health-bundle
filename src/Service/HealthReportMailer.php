<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Service;

use OpenDxp\Config;
use OpenDxp\Mail;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthReport;
use Pringuin\OpenDxpHealthBundle\Util\InstanceIdentity;
use Pringuin\OpenDxpHealthBundle\Util\InstanceIdentityInterface;
use RuntimeException;

final class HealthReportMailer
{
    public function __construct(
        private readonly ?string $configuredRecipient,
        private readonly InstanceIdentityInterface $instanceIdentity = new InstanceIdentity(),
    ) {
    }

    /**
     * @throws RuntimeException when no recipient can be resolved
     */
    public function send(HealthReport $report): void
    {
        $recipient = $this->resolveRecipient();

        $mail = new Mail();
        $mail->to($recipient);
        $mail->subject($this->buildSubject($report));
        $mail->text($this->buildBody($report));
        $mail->send();
    }

    public function resolveRecipient(): string
    {
        if ($this->configuredRecipient !== null && $this->configuredRecipient !== '') {
            return $this->configuredRecipient;
        }

        $emailConfig = Config::getSystemConfiguration('email');
        $senderEmail = $emailConfig['sender']['email'] ?? '';
        if (is_string($senderEmail) && $senderEmail !== '') {
            return $senderEmail;
        }

        throw new RuntimeException(
            'No recipient for the health report: neither pringuin_opendxp_health.mail.recipient '
            . 'nor the OpenDXP system email sender address (opendxp.email.sender.email) is configured.'
        );
    }

    private function buildSubject(HealthReport $report): string
    {
        $instanceName = $this->instanceIdentity->getDisplayName();

        if (!$report->hasIssues()) {
            return sprintf('[OpenDXP Health] OK on %s', $instanceName);
        }

        return sprintf(
            '[OpenDXP Health] %s - %d issue(s) on %s',
            strtoupper($report->getOverallStatus()->value),
            $report->countIssues(),
            $instanceName
        );
    }

    private function buildBody(HealthReport $report): string
    {
        $lines = [];
        $lines[] = 'OpenDXP Health Report';
        $lines[] = str_repeat('=', 60);
        $mainDomain = $this->instanceIdentity->getMainDomain();
        if ($mainDomain !== null) {
            $lines[] = 'Domain:  ' . $mainDomain;
        }
        $lines[] = 'Host:    ' . $this->instanceIdentity->getHostname();
        $lines[] = 'Date:    ' . $report->createdAt->format('Y-m-d H:i:s T');
        $lines[] = 'Status:  ' . strtoupper($report->getOverallStatus()->value);
        $lines[] = '';

        foreach ($report->results as $result) {
            $lines = [...$lines, ...$this->buildResultSection($result)];
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function buildResultSection(CheckResult $result): array
    {
        $lines = [];
        $lines[] = sprintf('[%s] %s', strtoupper($result->status->value), $result->checkLabel);
        $lines[] = $result->summary;
        foreach ($result->details as $detail) {
            $lines[] = '  - ' . $detail;
        }
        $lines[] = str_repeat('-', 60);

        return $lines;
    }
}
