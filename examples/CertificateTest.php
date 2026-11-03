<?php

namespace Examples;

use Aoaite\SmtpTool\Connection\SMTPEncryption;
use Aoaite\SmtpTool\Exceptions\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Is the certificate trusted, issued to this server and not about to expire, and does the
 * server announce the expected hostname? (No DNS lookups: the expected name is configured.)
 *
 * SMTP_EXPECTED_HOSTNAME is the name the server should present, SMTP_CA_FILE optionally a
 * private CA to trust instead of the system CAs, SMTP_CERT_MIN_DAYS the warning period.
 */
class CertificateTest extends SmtpServerTestCase
{
    public static function tlsPorts(): array
    {
        return [
            'smtp + STARTTLS' => ['SMTP_PORT', SMTPEncryption::STARTTLS],
            'submission' => ['SMTP_SUBMISSION_PORT', SMTPEncryption::STARTTLS],
            'smtps' => ['SMTP_SSL_PORT', SMTPEncryption::TLS],
        ];
    }

    private function expectedHostname(): String
    {
        $hostname = $this->env('SMTP_EXPECTED_HOSTNAME', '');
        if ($hostname === '') {
            $this->markTestSkipped('Set SMTP_EXPECTED_HOSTNAME to the name the server should present');
        }
        return $hostname;
    }

    /**
     * Connects with full verification: trusted chain and a certificate valid for $peerName.
     */
    private function connectVerified(String $portVariable, SMTPEncryption $encryption, String $peerName): void
    {
        $context = $this->context($encryption, true);
        $context->setPeerName($peerName);
        $this->connect((int)$this->env($portVariable), $encryption, $context);
    }

    #[DataProvider('tlsPorts')]
    public function test_certificate_is_trusted_and_issued_for_server(String $port, SMTPEncryption $encryption): void
    {
        $hostname = $this->expectedHostname();
        try {
            $this->connectVerified($port, $encryption, $hostname);
        } catch (ConnectionException $e) {
            $this->fail("Certificate verification failed for $hostname: " . $e->getMessage());
        }
        $certificate = $this->client->getConnectionMeta()->getPeerCertificate();
        $this->assertTrue($certificate->matchesHostname($hostname));
        $this->assertTrue($certificate->isValidAt());
    }

    #[DataProvider('tlsPorts')]
    public function test_certificate_does_not_expire_soon(String $port, SMTPEncryption $encryption): void
    {
        $this->connectVerified($port, $encryption, $this->expectedHostname());
        $certificate = $this->client->getConnectionMeta()->getPeerCertificate();
        $minDays = (int)$this->env('SMTP_CERT_MIN_DAYS', '14');
        $this->assertGreaterThanOrEqual(
            $minDays,
            $certificate->getDaysUntilExpiry(),
            'Certificate expires on ' . $certificate->getValidTo()->format('Y-m-d')
        );
    }

    #[DataProvider('tlsPorts')]
    public function test_server_announces_expected_hostname(String $port, SMTPEncryption $encryption): void
    {
        $hostname = $this->expectedHostname();
        $this->connectVerified($port, $encryption, $hostname);
        $this->assertSame(strtolower($hostname), strtolower((string)$this->client->getGreetingDomain()), 'Greeting banner');
        $this->assertSame(strtolower($hostname), strtolower((string)$this->client->getEhloDomain()), 'EHLO reply');
    }

    /**
     * Makes sure the checks above actually check something: a certificate is never valid
     * for an unrelated name.
     */
    public function test_certificate_is_rejected_for_other_hostname(): void
    {
        $this->expectedHostname();
        $this->expectException(ConnectionException::class);
        $this->connectVerified('SMTP_PORT', SMTPEncryption::STARTTLS, 'unrelated-name.invalid');
    }
}
