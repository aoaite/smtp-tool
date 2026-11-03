<?php

namespace Aoaite\SmtpTool\Connection;

class SMTPConnectionMeta
{
    private array $meta;
    private array $sslOptions;

    function __construct(SMTPConnection $connection)
    {
        $stream = $connection->getConnection();
        if (!is_resource($stream)) {
            throw new \Aoaite\SmtpTool\Exceptions\ConnectionException("Not connected");
        }
        $this->meta = stream_get_meta_data($stream);
        $this->sslOptions = stream_context_get_options($stream)['ssl'] ?? [];
    }

    /**
     * The server's certificate, if the connection is encrypted.
     */
    public function getPeerCertificate(): ?SMTPCertificate
    {
        $certificate = $this->sslOptions['peer_certificate'] ?? null;
        return $certificate instanceof \OpenSSLCertificate ? SMTPCertificate::fromOpenSSL($certificate) : null;
    }

    public function getRawCrypto(): ?array
    {
        if (!isset($this->meta['crypto'])) {
            return null;
        }
        return $this->meta['crypto'];
    }

    public function isEncrypted(): bool
    {
        return $this->getRawCrypto() != null;
    }

    public function getEncryptionProtocol(): ?String
    {
        $crypto = $this->getRawCrypto();
        if ($crypto && isset($crypto['protocol'])) {
            return $crypto['protocol'];
        }
        return null;
    }

    public function getEncryptionCipherName(): ?String
    {
        $crypto = $this->getRawCrypto();
        if ($crypto && isset($crypto['cipher_name'])) {
            return $crypto['cipher_name'];
        }
        return null;
    }

    public function getEncryptionCipherBits(): ?int
    {
        $crypto = $this->getRawCrypto();
        if ($crypto && isset($crypto['cipher_bits'])) {
            return $crypto['cipher_bits'];
        }
        return null;
    }

    public function getEncryptionCipherVersion(): ?String
    {
        $crypto = $this->getRawCrypto();
        if ($crypto && isset($crypto['cipher_version'])) {
            return $crypto['cipher_version'];
        }
        return null;
    }
}
