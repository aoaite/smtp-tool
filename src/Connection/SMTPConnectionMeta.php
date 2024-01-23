<?php

namespace Aoaite\SmtpTool\Connection;

class SMTPConnectionMeta
{
    private array $meta;

    function __construct(SMTPConnection $connection)
    {
        $this->meta = stream_get_meta_data($connection->getConnection());
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
