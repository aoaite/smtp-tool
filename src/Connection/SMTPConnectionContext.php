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
    private ?String $peerName = null;
    private ?String $caFile = null;

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
        $options = [
            'verify_peer' => $this->sslVerifyPeer,
            'verify_peer_name' => $this->sslVerifyPeerName,
            'allow_self_signed' => $this->sslAllowSelfSigned,
            'crypto_method' => $this->crypto_method,
            // Keep the server's certificate for SMTPConnectionMeta::getPeerCertificate()
            'capture_peer_cert' => true,
        ];
        if ($this->peerName !== null) {
            $options['peer_name'] = $this->peerName;
        }
        if ($this->caFile !== null) {
            $options['cafile'] = $this->caFile;
        }
        return ['ssl' => $options];
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

    /**
     * The name the certificate must be valid for (with SSL verify peer name enabled).
     * Defaults to the host connected to; set it when connecting by IP address or through a
     * different name, e.g. connect to "203.0.113.5" but require a certificate for "mx.example.com".
     */
    public function setPeerName(?String $peerName): void
    {
        $this->peerName = $peerName;
    }

    public function getPeerName(): ?String
    {
        return $this->peerName;
    }

    /**
     * CA certificate(s) (PEM file) to verify the server certificate against, instead of the
     * system's trusted CAs. Useful for servers with a private CA.
     */
    public function setCAFile(?String $caFile): void
    {
        $this->caFile = $caFile;
    }

    public function getCAFile(): ?String
    {
        return $this->caFile;
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
