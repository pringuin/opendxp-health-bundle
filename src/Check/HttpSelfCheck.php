<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches configured URLs (e.g. the homepage and the login page) from the
 * server itself and checks the HTTP status code and response time. This catches
 * broken deployments that look healthy from the CLI but return errors to
 * visitors. Without configured URLs the check does nothing.
 *
 * The server must be able to reach the URLs itself; when the frontend is only
 * reachable through an external load balancer, point the URLs at the public
 * address or disable this check.
 */
final class HttpSelfCheck implements HealthCheckInterface
{
    public const string NAME = 'http_self_check';

    /**
     * @param list<string> $urls
     * @param list<int> $expectedStatusCodes
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly array $urls = [],
        private readonly array $expectedStatusCodes = [200],
        private readonly int $timeout = 10,
        private readonly int $warningResponseTimeMs = 2000,
        private readonly int $criticalResponseTimeMs = 5000,
        private readonly bool $verifyTls = true,
        private readonly int $maxRedirects = 20,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'HTTP Self-Check';
    }

    public function run(): CheckResult
    {
        if ($this->urls === []) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::OK,
                'No URLs configured - nothing to check.',
            );
        }

        $status = HealthStatus::OK;
        $issueCount = 0;
        $details = [];

        foreach ($this->urls as $url) {
            ['status' => $urlStatus, 'line' => $line] = $this->inspectUrl($url);

            if ($urlStatus->severity() > $status->severity()) {
                $status = $urlStatus;
            }
            if ($urlStatus->isIssue()) {
                $issueCount++;
            }
            $details[] = $line;
        }

        $summary = $issueCount > 0
            ? sprintf('%d of %d checked URL(s) returned problems.', $issueCount, count($this->urls))
            : sprintf('All %d checked URL(s) responded as expected.', count($this->urls));

        return new CheckResult($this->getName(), $this->getLabel(), $status, $summary, $details);
    }

    /**
     * @return array{status: HealthStatus, line: string}
     */
    private function inspectUrl(string $url): array
    {
        $startTime = microtime(true);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => (float) $this->timeout,
                'verify_peer' => $this->verifyTls,
                'verify_host' => $this->verifyTls,
                'max_redirects' => $this->maxRedirects,
                'headers' => ['User-Agent' => 'OpenDXP-Health-Bundle/HttpSelfCheck'],
            ]);
            // getStatusCode() does not throw on 4xx/5xx; it only resolves the request
            $statusCode = $response->getStatusCode();
        } catch (HttpClientExceptionInterface $exception) {
            return [
                'status' => HealthStatus::CRITICAL,
                'line' => sprintf('%s: request failed (%s).', $url, $exception->getMessage()),
            ];
        }

        $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);

        $statusMatches = in_array($statusCode, $this->expectedStatusCodes, true);
        $status = HealthStatus::OK;
        $note = '';

        if (!$statusMatches) {
            $status = HealthStatus::CRITICAL;
            $note = sprintf(' - unexpected status code (expected %s)', implode('/', $this->expectedStatusCodes));
        } elseif ($responseTimeMs >= $this->criticalResponseTimeMs) {
            $status = HealthStatus::CRITICAL;
            $note = sprintf(' - above critical response time of %d ms', $this->criticalResponseTimeMs);
        } elseif ($responseTimeMs >= $this->warningResponseTimeMs) {
            $status = HealthStatus::WARNING;
            $note = sprintf(' - above warning response time of %d ms', $this->warningResponseTimeMs);
        }

        return [
            'status' => $status,
            'line' => sprintf('%s: HTTP %d in %d ms%s%s', $url, $statusCode, $responseTimeMs, $this->describeRedirects($response), $note),
        ];
    }

    /**
     * Followed redirects are named in the report, otherwise a redirect to an error-free page
     * (e.g. the login form) would be indistinguishable from the requested URL answering itself.
     */
    private function describeRedirects(ResponseInterface $response): string
    {
        $redirectCount = (int) $response->getInfo('redirect_count');
        if ($redirectCount === 0) {
            return '';
        }

        return sprintf(' after %d redirect(s) to %s', $redirectCount, (string) $response->getInfo('url'));
    }
}
