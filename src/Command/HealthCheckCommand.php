<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Command;

use OpenDxp\Console\AbstractCommand;
use Pringuin\OpenDxpHealthBundle\Report\HealthReport;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Service\HealthCheckAlreadyRunningException;
use Pringuin\OpenDxpHealthBundle\Service\HealthCheckRunner;
use Pringuin\OpenDxpHealthBundle\Service\HealthReportMailer;
use Pringuin\OpenDxpHealthBundle\Service\HealthReportStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'pringuin:health:check',
    description: 'Runs all health checks, stores the report for the admin UI and optionally sends it via email'
)]
final class HealthCheckCommand extends AbstractCommand
{
    private const int EXIT_HEALTHY = 0;

    private const int EXIT_ISSUES_FOUND = 1;

    private const int EXIT_OPERATIONAL_ERROR = 2;

    public function __construct(
        private readonly HealthCheckRunner $runner,
        private readonly HealthReportStorage $storage,
        private readonly HealthReportMailer $mailer,
        private readonly bool $mailEnabled,
        private readonly string $mailMinStatus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mail', null, InputOption::VALUE_NONE, 'Send the report via email regardless of the configured threshold')
            ->addOption('no-mail', null, InputOption::VALUE_NONE, 'Never send the report via email, overriding the configuration')
            ->addOption(
                'mail-min-status',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Only send the report when the overall status is at least this severe (%s), overriding the configured threshold',
                    implode('|', self::mailStatusNames())
                )
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $mailThreshold = $this->resolveMailThreshold($input);
        } catch (InvalidOptionException $exception) {
            $this->io->error($exception->getMessage());

            return self::EXIT_OPERATIONAL_ERROR;
        }

        try {
            $report = $this->runner->run();
        } catch (HealthCheckAlreadyRunningException $exception) {
            $this->io->error($exception->getMessage());

            return self::EXIT_OPERATIONAL_ERROR;
        }
        $this->storage->save($report);

        $this->renderReport($report);

        $exitCode = match (true) {
            $this->hasExecutionErrors($report) => self::EXIT_OPERATIONAL_ERROR,
            $report->hasIssues() => self::EXIT_ISSUES_FOUND,
            default => self::EXIT_HEALTHY,
        };

        if ($mailThreshold !== null && $report->getOverallStatus()->isAtLeast($mailThreshold)) {
            try {
                $this->mailer->send($report);
                $this->io->success(sprintf('Report sent to %s.', $this->mailer->resolveRecipient()));
            } catch (Throwable $exception) {
                $this->io->error('Sending the report email failed: ' . $exception->getMessage());

                return self::EXIT_OPERATIONAL_ERROR;
            }
        }

        return $exitCode;
    }

    /**
     * @return HealthStatus|null the lowest overall status that triggers a mail, or null when no mail is sent at all
     *
     * @throws InvalidOptionException when the mail options contradict each other or carry an unknown status
     */
    private function resolveMailThreshold(InputInterface $input): ?HealthStatus
    {
        $givenOptions = array_values(array_filter(
            ['no-mail', 'mail', 'mail-min-status'],
            static fn (string $option): bool => $input->getOption($option) !== false && $input->getOption($option) !== null
        ));
        if (count($givenOptions) > 1) {
            throw new InvalidOptionException(sprintf(
                'The options --%s cannot be combined, they all decide whether the report is mailed.',
                implode(' and --', $givenOptions)
            ));
        }

        if ($input->getOption('no-mail')) {
            return null;
        }
        if ($input->getOption('mail')) {
            return HealthStatus::OK;
        }

        $minStatus = $input->getOption('mail-min-status');
        if ($minStatus !== null) {
            return HealthStatus::tryFrom((string) $minStatus) ?? throw new InvalidOptionException(sprintf(
                'Unknown status "%s" for --mail-min-status, expected one of: %s.',
                (string) $minStatus,
                implode(', ', self::mailStatusNames())
            ));
        }

        return $this->mailEnabled ? HealthStatus::from($this->mailMinStatus) : null;
    }

    /**
     * @return list<string>
     */
    private static function mailStatusNames(): array
    {
        return array_map(static fn (HealthStatus $status): string => $status->value, HealthStatus::cases());
    }

    private function hasExecutionErrors(HealthReport $report): bool
    {
        foreach ($report->results as $result) {
            if ($result->status === HealthStatus::ERROR) {
                return true;
            }
        }

        return false;
    }

    private function renderReport(HealthReport $report): void
    {
        $this->io->title(sprintf(
            'OpenDXP Health Report - %s (%s)',
            strtoupper($report->getOverallStatus()->value),
            $report->createdAt->format('Y-m-d H:i:s')
        ));

        $this->io->table(
            ['Status', 'Check', 'Summary'],
            array_map(
                static fn ($result): array => [strtoupper($result->status->value), $result->checkLabel, $result->summary],
                $report->results
            )
        );

        foreach ($report->results as $result) {
            if ($result->details === []) {
                continue;
            }
            $this->io->section($result->checkLabel);
            $this->io->listing($result->details);
        }
    }
}
