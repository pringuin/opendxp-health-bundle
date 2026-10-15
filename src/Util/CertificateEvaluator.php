<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Util;

use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;

/**
 * Turns a parsed X.509 certificate into a health status and a report line:
 * remaining validity against the configured thresholds, plus a hostname match
 * against the subjectAltName entries and the common name.
 *
 * Kept separate from the SslCertificateCheck so that the evaluation - the part
 * that actually carries logic - can be tested without a TLS endpoint.
 */
final class CertificateEvaluator
{
    public function __construct(
        private readonly int $warningDays = 21,
        private readonly int $criticalDays = 7,
    ) {
    }

    /**
     * @param array<string, mixed>|false $parsedCertificate result of openssl_x509_parse()
     *
     * @return array{status: HealthStatus, line: string}
     */
    public function evaluate(string $host, array|false $parsedCertificate): array
    {
        if ($parsedCertificate === false || !isset($parsedCertificate['validTo_time_t'])) {
            return [
                'status' => HealthStatus::WARNING,
                'line' => sprintf('%s: peer certificate could not be read.', $host),
            ];
        }

        $validTo = (int) $parsedCertificate['validTo_time_t'];
        $daysRemaining = intdiv($validTo - time(), 86400);
        $issuer = $parsedCertificate['issuer']['CN'] ?? $parsedCertificate['issuer']['O'] ?? 'unknown issuer';

        $status = match (true) {
            $daysRemaining < 0 => HealthStatus::CRITICAL,
            $daysRemaining <= $this->criticalDays => HealthStatus::CRITICAL,
            $daysRemaining <= $this->warningDays => HealthStatus::WARNING,
            default => HealthStatus::OK,
        };

        $line = $daysRemaining < 0
            ? sprintf('%s: certificate EXPIRED %d day(s) ago (%s)', $host, abs($daysRemaining), date('Y-m-d', $validTo))
            : sprintf('%s: valid until %s (%d day(s) remaining)', $host, date('Y-m-d', $validTo), $daysRemaining);
        $line .= sprintf(', issuer: %s', $issuer);

        if (!$this->hostMatchesCertificate($host, $parsedCertificate)) {
            $line .= ' - certificate does not match the hostname!';
            if ($status->severity() < HealthStatus::WARNING->severity()) {
                $status = HealthStatus::WARNING;
            }
        }

        return ['status' => $status, 'line' => $line];
    }

    /**
     * @param array<string, mixed> $parsedCertificate
     */
    private function hostMatchesCertificate(string $host, array $parsedCertificate): bool
    {
        $names = [];

        $subjectAltName = $parsedCertificate['extensions']['subjectAltName'] ?? '';
        foreach (explode(',', (string) $subjectAltName) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            // entries look like "DNS:example.com" or "IP Address:1.2.3.4"
            $names[] = str_contains($entry, ':') ? trim(substr($entry, strpos($entry, ':') + 1)) : $entry;
        }

        $commonName = $parsedCertificate['subject']['CN'] ?? null;
        if (is_string($commonName) && $commonName !== '') {
            $names[] = $commonName;
        }

        foreach ($names as $name) {
            if (strcasecmp($name, $host) === 0) {
                return true;
            }
            // wildcard certificates match exactly one additional label
            if (str_starts_with($name, '*.')) {
                $hostWithoutFirstLabel = strstr($host, '.');
                if ($hostWithoutFirstLabel !== false && strcasecmp($hostWithoutFirstLabel, substr($name, 1)) === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
