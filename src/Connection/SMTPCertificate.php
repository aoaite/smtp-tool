<?php

namespace Aoaite\SmtpTool\Connection;

/**
 * An X.509 certificate presented by the server (see SMTPConnectionMeta::getPeerCertificate()).
 */
final class SMTPCertificate
{
    private \OpenSSLCertificate $certificate;
    private array $parsed;

    private function __construct(\OpenSSLCertificate $certificate)
    {
        $this->certificate = $certificate;
        $parsed = openssl_x509_parse($certificate);
        if ($parsed === false) {
            throw new \InvalidArgumentException("Could not parse certificate");
        }
        $this->parsed = $parsed;
    }

    public static function fromOpenSSL(\OpenSSLCertificate $certificate): self
    {
        return new self($certificate);
    }

    public static function fromPem(String $pem): self
    {
        $certificate = openssl_x509_read($pem);
        if ($certificate === false) {
            throw new \InvalidArgumentException("Not a PEM certificate");
        }
        return new self($certificate);
    }

    /**
     * @return array<string, string|string[]> e.g. ['CN' => 'mail.example.com', 'O' => 'Example']
     */
    public function getSubject(): array
    {
        return $this->parsed['subject'] ?? [];
    }

    public function getSubjectCommonName(): ?String
    {
        return self::first($this->getSubject()['CN'] ?? null);
    }

    /**
     * @return array<string, string|string[]>
     */
    public function getIssuer(): array
    {
        return $this->parsed['issuer'] ?? [];
    }

    public function getIssuerCommonName(): ?String
    {
        return self::first($this->getIssuer()['CN'] ?? null);
    }

    public function getIssuerOrganization(): ?String
    {
        return self::first($this->getIssuer()['O'] ?? null);
    }

    public function isSelfSigned(): bool
    {
        return $this->getSubject() == $this->getIssuer();
    }

    /**
     * DNS names from the subjectAltName extension.
     *
     * @return String[]
     */
    public function getDnsNames(): array
    {
        return $this->altNames('DNS');
    }

    /**
     * IP addresses from the subjectAltName extension.
     *
     * @return String[]
     */
    public function getIpAddresses(): array
    {
        return $this->altNames('IP Address');
    }

    private function altNames(String $type): array
    {
        $names = [];
        foreach (explode(',', $this->parsed['extensions']['subjectAltName'] ?? '') as $entry) {
            $parts = explode(':', trim($entry), 2);
            if (count($parts) === 2 && $parts[0] === $type) {
                $names[] = $parts[1];
            }
        }
        return $names;
    }

    /**
     * Whether the certificate is issued for $hostname: matched against the subjectAltName DNS
     * names (the common name is only used if there are none), case-insensitively, with a
     * wildcard matching exactly one leftmost label ("*.example.com" matches "mx.example.com",
     * not "example.com" or "a.mx.example.com").
     *
     * This only compares names; chain and validity are checked by the TLS handshake
     * when peer verification is enabled.
     */
    public function matchesHostname(String $hostname): bool
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        $names = $this->getDnsNames();
        if (!$names && $this->getSubjectCommonName() !== null) {
            $names = [$this->getSubjectCommonName()];
        }
        foreach ($names as $name) {
            $name = strtolower(rtrim($name, '.'));
            if ($name === $hostname) {
                return true;
            }
            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1); // ".example.com"
                $label = substr($hostname, 0, -strlen($suffix));
                if (str_ends_with($hostname, $suffix) && $label !== '' && !str_contains($label, '.')) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->parsed['validFrom_time_t']));
    }

    public function getValidTo(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->parsed['validTo_time_t']));
    }

    public function isValidAt(?\DateTimeInterface $time = null): bool
    {
        $timestamp = ($time ?? new \DateTimeImmutable())->getTimestamp();
        return $timestamp >= $this->parsed['validFrom_time_t'] && $timestamp <= $this->parsed['validTo_time_t'];
    }

    /**
     * Whole days until the certificate expires (negative once expired).
     */
    public function getDaysUntilExpiry(): int
    {
        return (int)floor(($this->parsed['validTo_time_t'] - time()) / 86400);
    }

    public function getSerialNumber(): String
    {
        return $this->parsed['serialNumberHex'] ?? (string)($this->parsed['serialNumber'] ?? '');
    }

    /**
     * Hex fingerprint of the DER encoded certificate, e.g. for pinning.
     */
    public function getFingerprint(String $algorithm = 'sha256'): String
    {
        return openssl_x509_fingerprint($this->certificate, $algorithm);
    }

    public function toPem(): String
    {
        openssl_x509_export($this->certificate, $pem);
        return $pem;
    }

    /**
     * Everything openssl_x509_parse() returns.
     */
    public function getParsed(): array
    {
        return $this->parsed;
    }

    private static function first(String|array|null $value): ?String
    {
        return is_array($value) ? ($value[0] ?? null) : $value;
    }
}
