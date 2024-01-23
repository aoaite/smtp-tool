<?php

namespace Aoaite\SmtpTool\Connection;

use Aoaite\SmtpTool\Connection\SMTPEncryption;

class SMTPConnectionContext
{
    private bool $sslVerifyPeer = true;
    private bool $sslVerifyPeerName = true;
    private bool $sslAllowSelfSigned = false;
    private $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
    private SMTPEncryption $encryption = SMTPEncryption::NONE;

    function __construct(SMTPEncryption $encryption = SMTPEncryption::NONE, bool $sslVerifyPeer = true, bool $sslVerifyPeerName = true, bool $sslAllowSelfSigned = false, int $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT)
    {
        $this->encryption = $encryption;
        $this->sslVerifyPeer = $sslVerifyPeer;
        $this->sslVerifyPeerName = $sslVerifyPeerName;
        $this->sslAllowSelfSigned = $sslAllowSelfSigned;
        $this->crypto_method = $crypto_method;
    }

    private function getConnectionContextSSLOptions()
    {
        return [
            'ssl' => [
                'verify_peer' => $this->sslVerifyPeer,
                'verify_peer_name' => $this->sslVerifyPeerName,
                'allow_self_signed' => $this->sslAllowSelfSigned,
                'crypto_method' => $this->crypto_method
            ]
        ];
    }

    public function getConnectionContext()
    {
        $options = [];
        switch ($this->encryption) {
            case SMTPEncryption::SSL:
            case SMTPEncryption::TLS:
            case SMTPEncryption::STARTTLS:
                $options = $this->getConnectionContextSSLOptions(); // Works for both tls:// and ssl:// transports
        }
        return stream_context_create($options);
    }

    // Public setters and getters

    public function setSSLVerifyPeer(bool $verify): void
    {
        $this->sslVerifyPeer = $verify;
    }

    public function getSSLVerifyPeer(): bool
    {
        return $this->sslVerifyPeer;
    }

    public function setSSLVerifyPeerName(bool $verify): void
    {
        $this->sslVerifyPeerName = $verify;
    }

    public function getSSLVerifyPeerName(): bool
    {
        return $this->sslVerifyPeerName;
    }

    public function setSSLAllowSelfSigned(bool $verify): void
    {
        $this->sslAllowSelfSigned = $verify;
    }

    public function getSSLAllowSelfSigned(): bool
    {
        return $this->sslAllowSelfSigned;
    }

    public function setCryptoMethod(int $method): void
    {
        $this->crypto_method = $method;
    }

    public function getCryptoMethod(): int
    {
        return $this->crypto_method;
    }

    public function setEncryption(SMTPEncryption $encryption): void
    {
        $this->encryption = $encryption;
    }

    public function getEncryption(): SMTPEncryption
    {
        return $this->encryption;
    }
}
