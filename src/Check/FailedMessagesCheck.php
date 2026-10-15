<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Throwable;

/**
 * Reports messages that ended up in the messenger failure transport, i.e.
 * messages that exhausted all retries. Also warns when no failure transport
 * is configured at all, because failed messages are silently discarded then.
 */
final class FailedMessagesCheck implements HealthCheckInterface
{
    public const string NAME = 'failed_messages';

    public function __construct(
        private readonly ContainerInterface $receiverLocator,
        private readonly string $transportName = 'failed',
        private readonly int $listLimit = 10,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'Failed Messages';
    }

    public function run(): CheckResult
    {
        if (!$this->receiverLocator->has($this->transportName)) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                sprintf(
                    'No messenger transport "%s" is configured. Without a failure_transport, messages that exhaust '
                    . 'their retries are discarded silently. Configure framework.messenger.failure_transport '
                    . '(or adjust transport_name / disable this check).',
                    $this->transportName
                ),
            );
        }

        $transport = $this->receiverLocator->get($this->transportName);

        if (!$transport instanceof MessageCountAwareInterface) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::WARNING,
                sprintf(
                    'Transport "%s" (%s) does not support message counting, the failed messages cannot be inspected.',
                    $this->transportName,
                    $transport::class
                ),
            );
        }

        $count = $transport->getMessageCount();
        if ($count === 0) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::OK,
                sprintf('No messages in the failure transport "%s".', $this->transportName),
            );
        }

        return new CheckResult(
            $this->getName(),
            $this->getLabel(),
            HealthStatus::CRITICAL,
            sprintf(
                '%d message(s) in the failure transport "%s". Inspect them with "bin/console messenger:failed:show" '
                . 'and retry or remove them.',
                $count,
                $this->transportName
            ),
            $this->buildDetails($transport, $count),
        );
    }

    /**
     * @return list<string>
     */
    private function buildDetails(object $transport, int $count): array
    {
        if (!$transport instanceof ListableReceiverInterface) {
            return [];
        }

        $details = [];

        try {
            foreach ($transport->all($this->listLimit) as $envelope) {
                $details[] = $this->describeEnvelope($envelope);
            }
        } catch (Throwable $exception) {
            // e.g. a message class that no longer exists cannot be deserialized
            $details[] = 'Failed messages could not be listed: ' . $exception->getMessage();

            return $details;
        }

        if ($count > $this->listLimit) {
            $details[] = sprintf('... and %d more message(s).', $count - $this->listLimit);
        }

        return $details;
    }

    private function describeEnvelope(Envelope $envelope): string
    {
        $parts = [$envelope->getMessage()::class];

        $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
        if ($redeliveryStamp instanceof RedeliveryStamp) {
            $parts[] = 'failed at ' . $redeliveryStamp->getRedeliveredAt()->format('Y-m-d H:i:s T');
        }

        $errorStamp = $envelope->last(ErrorDetailsStamp::class);
        if ($errorStamp instanceof ErrorDetailsStamp) {
            $parts[] = $errorStamp->getExceptionClass() . ': ' . $errorStamp->getExceptionMessage();
        }

        return implode(' | ', $parts);
    }
}
