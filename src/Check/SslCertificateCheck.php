<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Check;

use OpenDxp\Config;
use OpenDxp\Model\Site\Listing as SiteListing;
use Pringuin\OpenDxpHealthBundle\Report\CheckResult;
use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Util\CertificateEvaluator;
use Throwable;

/**
 * Checks the TLS certificates of the configured domains for upcoming expiry
 * and for hostname mismatches. Without configured domains, the main domain
 * from the OpenDXP system settings and all site domains are checked.
 *
 * The server must be able to reach the domains on the TLS port itself;
 * internal DNS or firewall rules may require configuring the domains
 * explicitly or disabling this check.
 *
 * Auto-detected domains come from settings that admin users can edit, so the
 * check can be pointed at internal hosts; the connection errors then reveal
 * their reachability. Configuring the domains explicitly avoids that.
 */
final class SslCertificateCheck implements HealthCheckInterface
{
    public const string NAME = 'ssl_certificate';

    private readonly CertificateEvaluator $certificateEvaluator;

    /**
     * @param list<string> $domains
     */
    public function __construct(
        private readonly array $domains = [],
        private readonly int $port = 443,
        private readonly int $connectionTimeout = 10,
        int $warningDays = 21,
        int $criticalDays = 7,
    ) {
        $this->certificateEvaluator = new CertificateEvaluator($warningDays, $criticalDays);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'SSL Certificates';
    }

    public function run(): CheckResult
    {
        if (!function_exists('openssl_x509_parse')) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::ERROR,
                'The openssl PHP extension is not available.',
            );
        }

        $domains = $this->domains !== [] ? $this->domains : $this->detectDomains();
        if ($domains === []) {
            return new CheckResult(
                $this->getName(),
                $this->getLabel(),
                HealthStatus::OK,
                'No domains configured and none found in the system settings or sites - nothing to check.',
            );
        }

        $status = HealthStatus::OK;
        $issueCount = 0;
        $details = [];

        foreach ($domains as $domain) {
            ['status' => $domainStatus, 'line' => $line] = $this->inspectCertificate($domain);

            if ($domainStatus->severity() > $status->severity()) {
                $status = $domainStatus;
            }
            if ($domainStatus->isIssue()) {
                $issueCount++;
            }
            $details[] = $line;
        }

        $summary = $issueCount > 0
            ? sprintf('%d of %d checked domain(s) have certificate problems.', $issueCount, count($domains))
            : sprintf('All %d checked certificate(s) are valid long enough.', count($domains));

        return new CheckResult($this->getName(), $this->getLabel(), $status, $summary, $details);
    }

    /**
     * @return list<string>
     */
    private function detectDomains(): array
    {
        $domains = [];

        $mainDomain = Config::getSystemConfiguration('general')['domain'] ?? '';
        if (is_string($mainDomain) && $mainDomain !== '') {
            $domains[] = $mainDomain;
        }

        try {
            foreach (new SiteListing() as $site) {
                $domains[] = $site->getMainDomain();
                foreach ($site->getDomains() as $siteDomain) {
                    $domains[] = $siteDomain;
                }
            }
        } catch (Throwable) {
            // no sites available - the main domain (if any) is still checked
        }

        $normalized = [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            // strip a port suffix; skip wildcard/pattern entries, they cannot be connected to
            $domain = explode(':', $domain)[0];
            if ($domain === '' || str_contains($domain, '*') || str_contains($domain, '/')) {
                continue;
            }
            $normalized[$domain] = $domain;
        }

        return array_values($normalized);
    }

    /**
     * @return array{status: HealthStatus, line: string}
     */
    private function inspectCertificate(string $host): array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                // do not verify here, otherwise expired/invalid certificates
                // could not be inspected at all
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $errorCode = 0;
        $errorMessage = '';
        $client = @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $this->port),
            $errorCode,
            $errorMessage,
            (float) $this->connectionTimeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($client === false) {
            return [
                'status' => HealthStatus::WARNING,
                'line' => sprintf(
                    '%s: TLS connection on port %d failed (%s).',
                    $host,
                    $this->port,
                    $errorMessage !== '' ? $errorMessage : 'error code ' . $errorCode
                ),
            ];
        }

        $certificate = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);

        return $this->certificateEvaluator->evaluate(
            $host,
            $certificate !== null ? openssl_x509_parse($certificate) : false
        );
    }
}
