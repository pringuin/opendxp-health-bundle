<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Controller;

use DateTimeImmutable;
use Pringuin\OpenDxpHealthBundle\Controller\StatusController;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthReport;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Service\HealthReportStorage;
use Pringuin\OpenDxpHealthBundle\Tests\Double\InMemorySettingsStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Stringable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(StatusController::class)]
final class StatusControllerTest extends TestCase
{
    private const string VALID_TOKEN = 'a3f1c9e07b2d48e6a5c1f0b9d7e24c68';

    public function testIsHiddenWhenDisabled(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->createController(enabled: false)->statusAction($this->createRequest(self::VALID_TOKEN));
    }

    public function testIsHiddenWithoutAToken(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->createController(token: null)->statusAction($this->createRequest(''));
    }

    public function testIsHiddenAndLogsAnErrorWhenTheTokenIsTooShort(): void
    {
        $logger = $this->createRecordingLogger();
        $controller = $this->createController(token: 'short-secret', logger: $logger);

        try {
            $controller->statusAction($this->createRequest('short-secret'));
            self::fail('Expected the endpoint to stay hidden.');
        } catch (NotFoundHttpException) {
        }

        self::assertSame('error', $logger->records[0]['level']);
        self::assertStringContainsString('shorter than', $logger->records[0]['message']);
    }

    public function testRejectsAWrongTokenAndLogsTheAttempt(): void
    {
        $logger = $this->createRecordingLogger();

        $response = $this->createController(logger: $logger)
            ->statusAction($this->createRequest(str_repeat('x', 32)));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('wrong', $logger->records[0]['context']['tokenState']);
        self::assertStringNotContainsString(str_repeat('x', 32), json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function testRejectsAMissingToken(): void
    {
        $response = $this->createController()->statusAction(Request::create('/pringuin/health/status'));

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function provideAuthenticatedRequests(): iterable
    {
        yield 'bearer header' => [Request::create('/status', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::VALID_TOKEN])];
        yield 'custom header' => [Request::create('/status', server: ['HTTP_X_HEALTH_TOKEN' => self::VALID_TOKEN])];
        yield 'query parameter' => [Request::create('/status', parameters: ['token' => self::VALID_TOKEN])];
    }

    #[DataProvider('provideAuthenticatedRequests')]
    public function testAcceptsTheTokenFromHeadersAndTheQuery(Request $request): void
    {
        $response = $this->createController(report: $this->createReport(HealthStatus::OK))->statusAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testReportsIssuesWithServiceUnavailable(): void
    {
        $response = $this->createController(report: $this->createReport(HealthStatus::CRITICAL))
            ->statusAction($this->createRequest(self::VALID_TOKEN));

        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($this->decode($response)['healthy']);
        self::assertSame(1, $this->decode($response)['issueCount']);
    }

    public function testReportsServiceUnavailableWithoutAStoredReport(): void
    {
        $response = $this->createController()->statusAction($this->createRequest(self::VALID_TOKEN));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('unknown', $this->decode($response)['status']);
    }

    public function testReturnsOnlyTheCheckStatusesByDefault(): void
    {
        $response = $this->createController(report: $this->createReport(HealthStatus::WARNING))
            ->statusAction($this->createRequest(self::VALID_TOKEN));

        $data = $this->decode($response);
        self::assertSame([['name' => 'admin_two_factor', 'status' => 'warning']], $data['checks']);
        self::assertArrayNotHasKey('report', $data);
        self::assertArrayNotHasKey('host', $data);
        self::assertStringNotContainsString('Admin without 2FA', (string) $response->getContent());
    }

    public function testReturnsTheFullReportWhenDetailsAreExposed(): void
    {
        $response = $this->createController(exposeDetails: true, report: $this->createReport(HealthStatus::WARNING))
            ->statusAction($this->createRequest(self::VALID_TOKEN));

        $data = $this->decode($response);
        self::assertSame(['Admin without 2FA: jane'], $data['report']['results'][0]['details']);
        self::assertArrayHasKey('host', $data);
    }

    public function testForbidsCachingOfEveryResponse(): void
    {
        $healthy = $this->createController(report: $this->createReport(HealthStatus::OK))
            ->statusAction($this->createRequest(self::VALID_TOKEN));
        $forbidden = $this->createController()->statusAction($this->createRequest(str_repeat('x', 32)));
        $unknown = $this->createController()->statusAction($this->createRequest(self::VALID_TOKEN));

        foreach ([$healthy, $forbidden, $unknown] as $response) {
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertFalse($response->headers->hasCacheControlDirective('public'));
        }
    }

    private function createController(
        bool $enabled = true,
        ?string $token = self::VALID_TOKEN,
        bool $exposeDetails = false,
        ?HealthReport $report = null,
        ?AbstractLogger $logger = null,
    ): StatusController {
        $storage = new HealthReportStorage(new InMemorySettingsStorage());
        if ($report !== null) {
            $storage->save($report);
        }

        return new StatusController($storage, $enabled, $token, $exposeDetails, $logger ?? new NullLogger());
    }

    private function createRequest(string $token): Request
    {
        return Request::create('/pringuin/health/status', server: ['HTTP_X_HEALTH_TOKEN' => $token]);
    }

    private function createReport(HealthStatus $status): HealthReport
    {
        return new HealthReport(new DateTimeImmutable('2026-09-24 03:17:00'), [
            new CheckResult(
                'admin_two_factor',
                'Admin 2FA',
                $status,
                'summary',
                $status === HealthStatus::OK ? [] : ['Admin without 2FA: jane'],
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function createRecordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
