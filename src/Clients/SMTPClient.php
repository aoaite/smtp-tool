<?php

namespace Aoaite\SmtpTool\Clients;

use Aoaite\SmtpTool\Connection\SMTPConnection;
use Aoaite\SmtpTool\Connection\SMTPConnectionContext;
use Aoaite\SmtpTool\Connection\SMTPConnectionMeta;
use Aoaite\SmtpTool\Helpers\Logger;

abstract class SMTPClient
{
    protected SMTPConnection $connection;

    protected Logger $logger;

    function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
    }

    abstract public function connect(String $host, int $port, SMTPConnectionContext $context): bool;
    abstract public function isConnected(): bool;
    abstract public function disconnect(): bool;

    /**
     * Sends a single command line and returns the server's (possibly multi-line) reply.
     * The command must not contain CR or LF characters; see sendRaw() for that.
     */
    public function sendCommand(String $command): String
    {
        $this->connection->write($command);
        return $this->connection->read() ?? '';
    }

    /**
     * Sends the data exactly as given (no line ending is appended, CR/LF are allowed)
     * and, unless $readReply is false, returns the server's reply.
     * Intended for sending malformed input to test how a server handles it.
     */
    public function sendRaw(String $data, bool $readReply = true): ?String
    {
        $this->connection->writeRaw($data);
        return $readReply ? ($this->connection->read() ?? '') : null;
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    public function getConnection(): SMTPConnection
    {
        return $this->connection;
    }

    public function getConnectionMeta(): SMTPConnectionMeta
    {
        return new SMTPConnectionMeta($this->connection);
    }
}
