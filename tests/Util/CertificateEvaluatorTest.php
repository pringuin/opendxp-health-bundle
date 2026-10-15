<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Util;

use Pringuin\OpenDxpHealthBundle\Report\HealthStatus;
use Pringuin\OpenDxpHealthBundle\Util\CertificateEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CertificateEvaluator::class)]
final class CertificateEvaluatorTest extends TestCase
{
    private const int DAY_IN_SECONDS = 86400;

    public function testWarnsWhenTheCertificateCouldNotBeParsed(): void
    {
        $result = (new CertificateEvaluator())->evaluate('example.com', false);

        self::assertSame(HealthStatus::WARNING, $result['status']);
        self::assertSame('example.com: peer certificate could not be read.', $result['line']);
    }

    public function testWarnsWhenTheExpiryDateIsMissing(): void
    {
        $result = (new CertificateEvaluator())->evaluate('example.com', ['subject' => ['CN' => 'example.com']]);

        self::assertSame(HealthStatus::WARNING, $result['status']);
        self::assertStringContainsString('could not be read', $result['line']);
    }

    #[DataProvider('remainingValidityProvider')]
    public function testMapsTheRemainingValidityToAStatus(int $daysRemaining, HealthStatus $expected): void
    {
        $result = (new CertificateEvaluator(warningDays: 21, criticalDays: 7))
            ->evaluate('example.com', $this->certificate('example.com', $daysRemaining));

        self::assertSame($expected, $result['status']);
    }

    /**
     * @return iterable<string, array{int, HealthStatus}>
     */
    public static function remainingValidityProvider(): iterable
    {
        yield 'far in the future' => [200, HealthStatus::OK];
        yield 'just above the warning threshold' => [22, HealthStatus::OK];
        yield 'exactly at the warning threshold' => [21, HealthStatus::WARNING];
        yield 'just above the critical threshold' => [8, HealthStatus::WARNING];
        yield 'exactly at the critical threshold' => [7, HealthStatus::CRITICAL];
        yield 'expires today' => [0, HealthStatus::CRITICAL];
        yield 'already expired' => [-3, HealthStatus::CRITICAL];
    }

    public function testDescribesAValidCertificate(): void
    {
        $result = (new CertificateEvaluator())->evaluate('example.com', $this->certificate('example.com', 100));

        self::assertStringContainsString('example.com: valid until ', $result['line']);
        self::assertStringContainsString('(100 day(s) remaining)', $result['line']);
        self::assertStringContainsString('issuer: Test CA', $result['line']);
    }

    public function testDescribesAnExpiredCertificate(): void
    {
        $result = (new CertificateEvaluator())->evaluate('example.com', $this->certificate('example.com', -5));

        self::assertStringContainsString('certificate EXPIRED 5 day(s) ago', $result['line']);
    }

    public function testFallsBackToTheIssuerOrganisation(): void
    {
        $certificate = $this->certificate('example.com', 100);
        $certificate['issuer'] = ['O' => 'Some Authority Ltd'];

        $result = (new CertificateEvaluator())->evaluate('example.com', $certificate);

        self::assertStringContainsString('issuer: Some Authority Ltd', $result['line']);
    }

    public function testReportsAnUnknownIssuer(): void
    {
        $certificate = $this->certificate('example.com', 100);
        $certificate['issuer'] = [];

        $result = (new CertificateEvaluator())->evaluate('example.com', $certificate);

        self::assertStringContainsString('issuer: unknown issuer', $result['line']);
    }

    public function testMatchesTheHostAgainstTheCommonName(): void
    {
        $result = (new CertificateEvaluator())->evaluate('example.com', $this->certificate('example.com', 100));

        self::assertSame(HealthStatus::OK, $result['status']);
        self::assertStringNotContainsString('does not match', $result['line']);
    }

    public function testMatchesTheHostCaseInsensitively(): void
    {
        $result = (new CertificateEvaluator())->evaluate('EXAMPLE.com', $this->certificate('example.com', 100));

        self::assertStringNotContainsString('does not match', $result['line']);
    }

    public function testMatchesASubjectAltNameEntry(): void
    {
        $certificate = $this->certificate('other.example', 100);
        $certificate['extensions']['subjectAltName'] = 'DNS:www.example.com, DNS:example.com';

        $result = (new CertificateEvaluator())->evaluate('example.com', $certificate);

        self::assertSame(HealthStatus::OK, $result['status']);
        self::assertStringNotContainsString('does not match', $result['line']);
    }

    public function testMatchesAWildcardCertificateOnOneAdditionalLabel(): void
    {
        $certificate = $this->certificate('other.example', 100);
        $certificate['extensions']['subjectAltName'] = 'DNS:*.example.com';

        $result = (new CertificateEvaluator())->evaluate('www.example.com', $certificate);

        self::assertStringNotContainsString('does not match', $result['line']);
    }

    public function testAWildcardDoesNotMatchAcrossTwoLabels(): void
    {
        $certificate = $this->certificate('other.example', 100);
        $certificate['extensions']['subjectAltName'] = 'DNS:*.example.com';

        $result = (new CertificateEvaluator())->evaluate('a.b.example.com', $certificate);

        self::assertStringContainsString('does not match the hostname', $result['line']);
    }

    public function testAWildcardDoesNotMatchTheBareDomain(): void
    {
        $certificate = $this->certificate('other.example', 100);
        $certificate['extensions']['subjectAltName'] = 'DNS:*.example.com';

        $result = (new CertificateEvaluator())->evaluate('example.com', $certificate);

        self::assertStringContainsString('does not match the hostname', $result['line']);
    }

    public function testWarnsAboutAHostnameMismatchOnAnOtherwiseValidCertificate(): void
    {
        $result = (new CertificateEvaluator())->evaluate('example.com', $this->certificate('other.example', 100));

        self::assertSame(HealthStatus::WARNING, $result['status']);
        self::assertStringContainsString('does not match the hostname!', $result['line']);
    }

    public function testAHostnameMismatchDoesNotDowngradeACriticalStatus(): void
    {
        $result = (new CertificateEvaluator())->evaluate('example.com', $this->certificate('other.example', -1));

        self::assertSame(HealthStatus::CRITICAL, $result['status']);
        self::assertStringContainsString('does not match the hostname!', $result['line']);
    }

    public function testHandlesAnIpAddressSubjectAltNameEntry(): void
    {
        $certificate = $this->certificate('other.example', 100);
        $certificate['extensions']['subjectAltName'] = 'IP Address:127.0.0.1';

        $result = (new CertificateEvaluator())->evaluate('127.0.0.1', $certificate);

        self::assertStringNotContainsString('does not match', $result['line']);
    }

    /**
     * @return array<string, mixed>
     */
    private function certificate(string $commonName, int $daysRemaining): array
    {
        // intdiv() truncates towards zero, so aim at the middle of the target day
        // in the direction the day count points at
        $halfDay = intdiv(self::DAY_IN_SECONDS, 2);
        $offset = $daysRemaining < 0 ? -$halfDay : $halfDay;

        return [
            'validTo_time_t' => time() + $daysRemaining * self::DAY_IN_SECONDS + $offset,
            'subject' => ['CN' => $commonName],
            'issuer' => ['CN' => 'Test CA'],
        ];
    }
}
