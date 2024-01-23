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

    function __construct(Logger $logger = null)
    {
        if (!$logger) {
            $this->logger = new Logger();
        }
    }

    abstract public function connect(String $host, int $port, SMTPConnectionContext $context): bool;
    abstract public function isConnected(): bool;
    abstract public function disconnect(): bool;

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
