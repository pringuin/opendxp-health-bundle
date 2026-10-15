<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Check;

use Pringuin\OpenDxpHealthBundle\Check\HttpSelfCheck;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(HttpSelfCheck::class)]
final class HttpSelfCheckTest extends TestCase
{
    public function testDoesNothingWithoutConfiguredUrls(): void
    {
        $result = (new HttpSelfCheck(new MockHttpClient(), []))->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('No URLs configured - nothing to check.', $result->summary);
    }

    public function testReportsOkForAnExpectedStatusCode(): void
    {
        $check = new HttpSelfCheck(
            new MockHttpClient(new MockResponse('', ['http_code' => 200])),
            ['https://example.com/']
        );

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('All 1 checked URL(s) responded as expected.', $result->summary);
        self::assertStringContainsString('https://example.com/: HTTP 200 in', $result->details[0]);
    }

    public function testReportsCriticalForAnUnexpectedStatusCode(): void
    {
        $check = new HttpSelfCheck(
            new MockHttpClient(new MockResponse('', ['http_code' => 500])),
            ['https://example.com/']
        );

        $result = $check->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertSame('1 of 1 checked URL(s) returned problems.', $result->summary);
        self::assertStringContainsString('unexpected status code (expected 200)', $result->details[0]);
    }

    public function testAcceptsEveryConfiguredStatusCode(): void
    {
        $check = new HttpSelfCheck(
            new MockHttpClient(new MockResponse('', ['http_code' => 302])),
            ['https://example.com/'],
            expectedStatusCodes: [200, 302]
        );

        self::assertSame(HealthStatus::OK, $check->run()->status);
    }

    public function testReportsCriticalWhenTheRequestFails(): void
    {
        $check = new HttpSelfCheck(
            new MockHttpClient(static fn (): never => throw new TransportException('Connection refused')),
            ['https://example.com/']
        );

        $result = $check->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertStringContainsString('request failed (Connection refused)', $result->details[0]);
    }

    public function testWarnsAboutASlowResponse(): void
    {
        $check = new HttpSelfCheck(
            $this->createDelayedClient(5),
            ['https://example.com/'],
            warningResponseTimeMs: 1,
            criticalResponseTimeMs: 10_000
        );

        $result = $check->run();

        self::assertSame(HealthStatus::WARNING, $result->status);
        self::assertStringContainsString('above warning response time of 1 ms', $result->details[0]);
    }

    public function testReportsCriticalForAVerySlowResponse(): void
    {
        $check = new HttpSelfCheck(
            $this->createDelayedClient(5),
            ['https://example.com/'],
            warningResponseTimeMs: 1,
            criticalResponseTimeMs: 2
        );

        $result = $check->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertStringContainsString('above critical response time of 2 ms', $result->details[0]);
    }

    public function testAnUnexpectedStatusCodeOutweighsTheResponseTime(): void
    {
        $check = new HttpSelfCheck(
            new MockHttpClient(new MockResponse('', ['http_code' => 404])),
            ['https://example.com/'],
            warningResponseTimeMs: 1
        );

        $result = $check->run();

        self::assertStringContainsString('unexpected status code', $result->details[0]);
        self::assertStringNotContainsString('response time', $result->details[0]);
    }

    public function testChecksEveryUrlAndReportsTheMostSevereStatus(): void
    {
        $check = new HttpSelfCheck(
            new MockHttpClient([
                new MockResponse('', ['http_code' => 200]),
                new MockResponse('', ['http_code' => 503]),
                new MockResponse('', ['http_code' => 200]),
            ]),
            ['https://example.com/', 'https://example.com/broken', 'https://example.com/admin']
        );

        $result = $check->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertSame('1 of 3 checked URL(s) returned problems.', $result->summary);
        self::assertCount(3, $result->details);
    }

    public function testPassesTheTimeoutAndTlsSettingsToTheClient(): void
    {
        $capturedOptions = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse('', ['http_code' => 200]);
        });
        $check = new HttpSelfCheck($client, ['https://example.com/'], timeout: 7, verifyTls: false);

        $check->run();

        self::assertSame(7.0, $capturedOptions['timeout']);
        self::assertFalse($capturedOptions['verify_peer']);
        self::assertFalse($capturedOptions['verify_host']);
    }

    public function testPassesTheRedirectLimitToTheClient(): void
    {
        $capturedMaxRedirects = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedMaxRedirects): MockResponse {
            $capturedMaxRedirects[] = $options['max_redirects'];

            return new MockResponse('', ['http_code' => 200]);
        });

        (new HttpSelfCheck($client, ['https://example.com/']))->run();
        (new HttpSelfCheck($client, ['https://example.com/'], maxRedirects: 0))->run();

        self::assertSame([20, 0], $capturedMaxRedirects);
    }

    public function testNamesTheFinalUrlOfAFollowedRedirect(): void
    {
        $check = new HttpSelfCheck(
            $this->createClientReturning($this->createResponse(200, 2, 'https://example.com/login')),
            ['https://example.com/admin']
        );

        $result = $check->run();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertStringContainsString('https://example.com/admin: HTTP 200 in', $result->details[0]);
        self::assertStringContainsString('after 2 redirect(s) to https://example.com/login', $result->details[0]);
    }

    public function testDoesNotMentionRedirectsWhenTheUrlAnsweredItself(): void
    {
        $check = new HttpSelfCheck(
            $this->createClientReturning($this->createResponse(200, 0, 'https://example.com/')),
            ['https://example.com/']
        );

        self::assertStringNotContainsString('redirect', $check->run()->details[0]);
    }

    public function testReportsAnUnfollowedRedirectAsUnexpectedStatusCode(): void
    {
        $check = new HttpSelfCheck(
            $this->createClientReturning($this->createResponse(301, 0, 'https://example.com/')),
            ['https://example.com/'],
            maxRedirects: 0
        );

        $result = $check->run();

        self::assertSame(HealthStatus::CRITICAL, $result->status);
        self::assertStringContainsString('HTTP 301', $result->details[0]);
    }

    private function createClientReturning(ResponseInterface $response): HttpClientInterface
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        return $client;
    }

    /**
     * MockResponse always reports the requested URL and no redirects, so the redirect
     * info has to come from a response double.
     */
    private function createResponse(int $statusCode, int $redirectCount, string $finalUrl): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getInfo')->willReturnCallback(static fn (?string $type = null): mixed => match ($type) {
            'redirect_count' => $redirectCount,
            'url' => $finalUrl,
            default => null,
        });

        return $response;
    }

    private function createDelayedClient(int $delayMilliseconds): HttpClientInterface
    {
        return new MockHttpClient(static function () use ($delayMilliseconds): MockResponse {
            usleep($delayMilliseconds * 1000);

            return new MockResponse('', ['http_code' => 200]);
        });
    }
}
