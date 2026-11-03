<?php

namespace Tests\Unit;

use Aoaite\SmtpTool\Connection\SMTPCertificate;
use PHPUnit\Framework\TestCase;

class CertificateTest extends TestCase
{
    private static function selfSigned(String $commonName, int $days): String
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']), $pem);
        return $pem;
    }

    public function test_common_name_is_used_without_subject_alt_names(): void
    {
        $certificate = SMTPCertificate::fromPem(self::selfSigned('*.example.com', 30));
        $this->assertSame([], $certificate->getDnsNames());
        $this->assertTrue($certificate->matchesHostname('mx.example.com'));
        $this->assertFalse($certificate->matchesHostname('example.com'));
        $this->assertTrue($certificate->isSelfSigned());
        $this->assertSame('*.example.com', $certificate->getIssuerCommonName());
    }

    public function test_validity(): void
    {
        $certificate = SMTPCertificate::fromPem(self::selfSigned('mx.example.com', 30));
        $this->assertTrue($certificate->isValidAt());
        $this->assertFalse($certificate->isValidAt(new \DateTimeImmutable('+31 days')));
        $this->assertFalse($certificate->isValidAt(new \DateTimeImmutable('-1 day')));
        $this->assertContains($certificate->getDaysUntilExpiry(), [29, 30]);
        $this->assertLessThanOrEqual(time(), $certificate->getValidFrom()->getTimestamp());
    }

    public function test_pem_round_trip(): void
    {
        $pem = self::selfSigned('mx.example.com', 1);
        $this->assertSame(
            SMTPCertificate::fromPem($pem)->getFingerprint(),
            SMTPCertificate::fromPem(SMTPCertificate::fromPem($pem)->toPem())->getFingerprint()
        );
    }

    public function test_invalid_pem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        @SMTPCertificate::fromPem('not a certificate');
    }
}
