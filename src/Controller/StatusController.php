<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Controller;

use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthReport;
use Pringuin\OpenDxpHealthBundle\Service\HealthReportStorage;
use Pringuin\OpenDxpHealthBundle\Util\InstanceIdentity;
use Pringuin\OpenDxpHealthBundle\Util\InstanceIdentityInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, token-protected status endpoint for external uptime monitoring
 * (Uptime Kuma, Icinga, UptimeRobot, ...). It returns the last stored report -
 * it never runs the checks itself, so it cannot be used to trigger expensive
 * work. The HTTP status code mirrors the health: 200 when healthy, 503 when
 * issues were found or no report exists yet.
 *
 * By default only the status of each check is returned. The check details can
 * contain user names, email addresses, log excerpts and vulnerable package
 * versions, so they are only included when explicitly enabled.
 *
 * Disabled by default. When enabled without a sufficiently long token the
 * endpoint stays hidden (404) so it is never exposed with a guessable secret.
 *
 * @internal
 */
final class StatusController
{
    public const int MINIMUM_TOKEN_LENGTH = 32;

    public const string TOKEN_HEADER = 'X-Health-Token';

    public function __construct(
        private readonly HealthReportStorage $storage,
        private readonly bool $enabled,
        private readonly ?string $token,
        private readonly bool $exposeDetails = false,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly InstanceIdentityInterface $instanceIdentity = new InstanceIdentity(),
    ) {
    }

    #[Route('/status', name: 'pringuin_opendxp_health_status', methods: ['GET'])]
    public function statusAction(Request $request): JsonResponse
    {
        if (!$this->enabled || $this->token === null || $this->token === '') {
            throw new NotFoundHttpException();
        }

        // checked at runtime because the token usually comes from an environment variable,
        // which is not resolved yet when the bundle configuration is validated
        if (strlen($this->token) < self::MINIMUM_TOKEN_LENGTH) {
            $this->logger->error(
                'The health status endpoint is disabled because its token is shorter than {minimumLength} characters.',
                ['minimumLength' => self::MINIMUM_TOKEN_LENGTH]
            );

            throw new NotFoundHttpException();
        }

        $providedToken = $this->extractProvidedToken($request);
        if ($providedToken === '' || !hash_equals($this->token, $providedToken)) {
            $this->logger->warning(
                'Rejected health status request with a {tokenState} token from {clientIp}.',
                ['tokenState' => $providedToken === '' ? 'missing' : 'wrong', 'clientIp' => $request->getClientIp()]
            );

            return $this->createResponse(['status' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $report = $this->storage->getLastReport();
        if ($report === null) {
            return $this->createResponse(
                ['status' => 'unknown', 'message' => 'No health report available yet.'],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $healthy = !$report->hasIssues();

        return $this->createResponse(
            $this->buildReportPayload($report, $healthy),
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE
        );
    }

    /**
     * Headers are preferred because query strings end up in access logs, proxy logs and cache keys;
     * the query parameter is kept for monitors that cannot send custom headers.
     */
    private function extractProvidedToken(Request $request): string
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) === 1) {
            return $matches[1];
        }

        $headerToken = (string) $request->headers->get(self::TOKEN_HEADER, '');
        if ($headerToken !== '') {
            return $headerToken;
        }

        return (string) $request->query->get('token', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReportPayload(HealthReport $report, bool $healthy): array
    {
        $payload = [
            'status' => $report->getOverallStatus()->value,
            'healthy' => $healthy,
            'issueCount' => $report->countIssues(),
            'createdAt' => $report->toArray()['createdAt'],
            'checks' => array_map(
                static fn (CheckResult $result): array => ['name' => $result->checkName, 'status' => $result->status->value],
                $report->results
            ),
        ];

        if ($this->exposeDetails) {
            $payload['domain'] = $this->instanceIdentity->getMainDomain();
            $payload['host'] = $this->instanceIdentity->getHostname();
            $payload['report'] = $report->toArray();
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createResponse(array $data, int $httpStatus): JsonResponse
    {
        $response = new JsonResponse($data, $httpStatus);
        // "no-store" keeps the OpenDXP full page cache, reverse proxies and CDNs from caching the
        // report: a cached "healthy" answer would hide later failures from the monitor
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
