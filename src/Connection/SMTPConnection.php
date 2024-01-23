<?php

namespace Aoaite\SmtpTool\Connection;

use Aoaite\SmtpTool\Exceptions\ConnectionException;
use Aoaite\SmtpTool\Helpers\Logger;
use Aoaite\SmtpTool\Connection\SMTPEncryption;

class SMTPConnection
{
    private ?int $error_code = null;
    private ?String $error_message = null;
    private bool $error = false;

    private String $CRLF = "\r\n";

    private String $host;
    private int $port;
    private float $timeout;
    private SMTPConnectionContext $context;

    private $connection;
    private Logger $logger;

    function __construct(String $host, int $port, SMTPConnectionContext $context, Logger $logger, int $timeout = 16)
    {
        $this->host = $host;
        $this->port = $port;
        $this->context = $context;
        $this->logger = $logger;
        $this->timeout = $timeout;
    }

    private function getTransport(): String
    {
        switch ($this->getContext()->getEncryption()) {
            case SMTPEncryption::STARTTLS:
            case SMTPEncryption::NONE:
                return 'tcp://';
            case SMTPEncryption::SSL:
                return 'ssl://';
            case SMTPEncryption::TLS:
                return 'tls://';
        }
    }

    private function getConnectionAddress(): String
    {
        return $this->getTransport() . $this->host . ':' . $this->port;
    }

    public function connect(): void
    {
        try {
            $this->connection = stream_socket_client(
                $this->getConnectionAddress(),
                $this->error_code,
                $this->error_message,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
                $this->context->getConnectionContext()
            );
        } catch (\Throwable $e) {
            $this->logger->log("ERROR: " . $e->getMessage(), 5);
            throw new ConnectionException($e->getMessage(), 1, $e);
        }
        if ($this->connection === false) {
            $this->error = true;
            $this->logger->log("ERROR: " . $this->error_message, 5);
            throw new ConnectionException($this->error_message, $this->error_code);
        }
        $this->logger->log("Connection established");
    }

    public function isConnected(): bool
    {
        return !feof($this->connection);
    }

    public function read(): ?String
    {
        if (!$this->connection)
            return null;
        $data = "";
        // TODO: Wrap in try / catch
        while (($part = stream_get_line($this->connection, 1024, $this->CRLF)) !== false) {
            $this->logger->logReceived($part);
            $data .= $part . $this->CRLF;
            if (substr($part, 3, 1) == ' ') {
                break;
            }
        }
        return $data;
    }

    public function write($data): int|false
    {
        $this->logger->logSent($data);
        try {
            $result = fputs($this->connection, $data . $this->CRLF);
        } catch (\Throwable $e) {
            $this->logger->log("ERROR: " . $e->getMessage(), 5);
            throw new ConnectionException($e->getMessage(), 1, $e);
        }

        if ($this->connection === false) {
            $this->error = true;
            $this->logger->log("ERROR: " . $this->error_message, 5);
            throw new ConnectionException($this->error_code, $this->error_message);
        }

        return $result;
    }

    // Public setters and getters

    public function getSeparators(): String
    {
        return $this->CRLF;
    }

    public function setSeparators(String $separator): void
    {
        $this->CRLF = $separator;
    }

    public function getErrorCode(): ?int
    {
        return $this->error_code;
    }

    public function getErrorMessage(): ?String
    {
        return $this->error_message;
    }

    public function hasError(): bool
    {
        return $this->error;
    }

    public function setHost(String $host): void
    {
        $this->host = $host;
    }

    public function getHost(): String
    {
        return $this->host;
    }

    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setTimeout(float $timeout): void
    {
        $this->timeout = $timeout;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function setContext(SMTPConnectionContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): SMTPConnectionContext
    {
        return $this->context;
    }

    public function getConnection()
    {
        return $this->connection;
    }
}
