<?php

namespace Aoaite\SmtpTool\Connection;

use Aoaite\SmtpTool\Exceptions\ConnectionException;
use Aoaite\SmtpTool\Helpers\Logger;
use Aoaite\SmtpTool\Helpers\PhpErrors;
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
        return match ($this->getContext()->getEncryption()) {
            SMTPEncryption::STARTTLS, SMTPEncryption::NONE => 'tcp://',
            SMTPEncryption::SSL => 'ssl://',
            SMTPEncryption::TLS => 'tls://',
        };
    }

    private function getConnectionAddress(): String
    {
        return $this->getTransport() . $this->host . ':' . $this->port;
    }

    public function connect(): void
    {
        $this->connection = PhpErrors::capture(fn () => stream_socket_client(
            $this->getConnectionAddress(),
            $this->error_code,
            $this->error_message,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $this->context->getConnectionContext()
        ), $warnings);
        if ($this->connection === false) {
            $this->error = true;
            // The socket error is often just "Unknown error" (e.g. for TLS failures); the warnings say why
            if ($warnings) {
                $this->error_message = implode('; ', $warnings);
            }
            $this->logger->log("ERROR: " . $this->error_message, 5);
            throw new ConnectionException($this->error_message, $this->error_code);
        }
        // Apply the timeout to reads/writes too, not only to the initial connect
        stream_set_timeout($this->connection, (int)$this->timeout, (int)(fmod($this->timeout, 1) * 1000000));
        $this->logger->log("Connection established");
    }

    public function isConnected(): bool
    {
        return is_resource($this->connection) && !feof($this->connection);
    }

    public function close(): void
    {
        if (is_resource($this->connection)) {
            fclose($this->connection);
        }
        $this->connection = null;
    }

    public function read(): ?String
    {
        if (!is_resource($this->connection))
            return null;
        $data = "";
        // TODO: Wrap in try / catch
        while (($part = stream_get_line($this->connection, 1024, $this->CRLF)) !== false) {
            $this->logger->logReceived($part);
            $data .= $part . $this->CRLF;
            // A reply line is "NNN-text" (more lines follow) or "NNN[ text]" (last line).
            // The text is optional, so a bare "250" also terminates the reply.
            if (substr($part, 3, 1) !== '-') {
                break;
            }
        }
        return $data;
    }

    /**
     * Sends a single command line, terminated by the configured separator.
     *
     * The line must not contain CR or LF characters, so a value passed into a
     * command (e.g. the mailbox of VRFY) cannot accidentally inject further
     * commands. Use writeRaw() to deliberately send malformed input.
     */
    public function write(String $data): int
    {
        if (strpbrk($data, "\r\n") !== false) {
            throw new \InvalidArgumentException("Command must not contain CR or LF characters, use writeRaw() to send arbitrary data");
        }
        $this->logger->logSent($data);
        return $this->send($data . $this->CRLF);
    }

    /**
     * Sends the data exactly as given: no separator is appended and nothing is
     * validated. Intended for testing how a server handles malformed input.
     */
    public function writeRaw(String $data): int
    {
        $this->logger->logSent($data);
        return $this->send($data);
    }

    private function send(String $data): int
    {
        if (!is_resource($this->connection)) {
            $this->error = true;
            $this->logger->log("ERROR: Not connected", 5);
            throw new ConnectionException("Not connected");
        }
        $total = 0;
        $length = strlen($data);
        while ($total < $length) {
            try {
                $written = fwrite($this->connection, substr($data, $total));
            } catch (\Throwable $e) {
                $this->error = true;
                $this->logger->log("ERROR: " . $e->getMessage(), 5);
                throw new ConnectionException($e->getMessage(), 1, $e);
            }
            if ($written === false || $written === 0) {
                $this->error = true;
                $this->logger->log("ERROR: Failed to write to the connection", 5);
                throw new ConnectionException("Failed to write to the connection");
            }
            $total += $written;
        }
        return $total;
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
