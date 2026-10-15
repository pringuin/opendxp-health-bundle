<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use DateTimeImmutable;
use Pringuin\OpenDxpHealthBundle\Check\FailedMessagesCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

#[CoversClass(FailedMessagesCheck::class)]
final class FailedMessagesCheckTest extends TestCase
{
    public function testWarnsWhenNoFailureTransportIsConfigured(): void
    {
        $check = new FailedMessagesCheck($this->createReceiverLocator(null));

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('No messenger transport "failed" is configured', $result->summary);
        self::assertStringContainsString('framework.messenger.failure_transport', $result->summary);
    }

    public function testWarnsWhenTheTransportCannotCountMessages(): void
    {
        $check = new FailedMessagesCheck($this->createReceiverLocator($this->createMock(ReceiverInterface::class)));

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('does not support message counting', $result->summary);
    }

    public function testReportsOkForAnEmptyFailureTransport(): void
    {
        $check = new FailedMessagesCheck($this->createReceiverLocator($this->createCountingReceiver(0)));

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('No messages in the failure transport "failed".', $result->summary);
    }

    public function testReportsCriticalForFailedMessages(): void
    {
        $check = new FailedMessagesCheck($this->createReceiverLocator($this->createCountingReceiver(3)));

        $result = $check->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertStringContainsString('3 message(s) in the failure transport "failed"', $result->summary);
        self::assertStringContainsString('messenger:failed:show', $result->summary);
        self::assertSame([], $result->details, 'A non-listable transport cannot contribute details.');
    }

    public function testDescribesTheListedEnvelopes(): void
    {
        $envelope = new Envelope(new \stdClass(), [
            new RedeliveryStamp(1, new DateTimeImmutable('2026-08-07 07:30:00')),
            new ErrorDetailsStamp(RuntimeException::class, 0, 'database gone'),
        ]);
        $check = new FailedMessagesCheck($this->createReceiverLocator($this->createListableReceiver(1, [$envelope])));

        $result = $check->run();

        self::assertCount(1, $result->details);
        self::assertStringContainsString(\stdClass::class, $result->details[0]);
        self::assertStringContainsString('failed at 2026-08-07 07:30:00', $result->details[0]);
        self::assertStringContainsString(RuntimeException::class . ': database gone', $result->details[0]);
    }

    public function testCountsTheMessagesBeyondTheListLimit(): void
    {
        $envelopes = array_fill(0, 2, new Envelope(new \stdClass()));
        $check = new FailedMessagesCheck($this->createReceiverLocator($this->createListableReceiver(5, $envelopes)), listLimit: 2);

        $result = $check->run();

        self::assertCount(3, $result->details);
        self::assertSame('... and 3 more message(s).', $result->details[2]);
    }

    public function testReportsWhenTheMessagesCannotBeDeserialized(): void
    {
        $receiver = $this->createMock(ListableAndCountableReceiver::class);
        $receiver->method('getMessageCount')->willReturn(2);
        $receiver->method('all')->willThrowException(new RuntimeException('class MyMessage not found'));
        $check = new FailedMessagesCheck($this->createReceiverLocator($receiver));

        $result = $check->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertSame(['Failed messages could not be listed: class MyMessage not found'], $result->details);
    }

    public function testUsesTheConfiguredTransportName(): void
    {
        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('has')->willReturnCallback(static fn (string $id): bool => $id === 'dead_letter');
        $locator->method('get')->willReturn($this->createCountingReceiver(0));
        $check = new FailedMessagesCheck($locator, transportName: 'dead_letter');

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('"dead_letter"', $result->summary);
    }

    private function createReceiverLocator(?object $transport): ContainerInterface
    {
        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('has')->willReturn($transport !== null);
        if ($transport !== null) {
            $locator->method('get')->willReturn($transport);
        }

        return $locator;
    }

    private function createCountingReceiver(int $count): MessageCountAwareInterface
    {
        $receiver = $this->createMock(MessageCountAwareInterface::class);
        $receiver->method('getMessageCount')->willReturn($count);

        return $receiver;
    }

    /**
     * @param list<Envelope> $envelopes
     */
    private function createListableReceiver(int $count, array $envelopes): ListableAndCountableReceiver
    {
        $receiver = $this->createMock(ListableAndCountableReceiver::class);
        $receiver->method('getMessageCount')->willReturn($count);
        $receiver->method('all')->willReturn($envelopes);

        return $receiver;
    }
}

/**
 * A real failure transport implements both interfaces; PHPUnit needs a single
 * type to generate the double from.
 */
interface ListableAndCountableReceiver extends ListableReceiverInterface, MessageCountAwareInterface
{
}
