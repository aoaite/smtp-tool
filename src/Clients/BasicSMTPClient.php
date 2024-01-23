<?php

namespace Aoaite\SmtpTool\Clients;

use Aoaite\SmtpTool\Auth\AuthMechanism;
use Aoaite\SmtpTool\Connection\SMTPConnection;
use Aoaite\SmtpTool\Connection\SMTPConnectionContext;
use Aoaite\SmtpTool\Connection\SMTPEncryption;
use Aoaite\SmtpTool\Exceptions\CommunicationException;
use Aoaite\SmtpTool\Handlers\HeloHandler;
use Aoaite\SmtpTool\Handlers\QuitHandler;
use Aoaite\SmtpTool\Handlers\StarttlsHandler;
use Aoaite\SmtpTool\Handlers\VerifyHandler;
use Aoaite\SmtpTool\Helpers\Logger;

class BasicSMTPClient extends SMTPClient
{
    private bool $esmtp = true;
    private bool $esmtp_connect = true;

    private String $connection_host = 'localhost';
    private String $connection_host_remote = '';

    private array $server_capabilities = [];

    public function connect(String $host, int $port, SMTPConnectionContext $context): bool
    {
        $this->connection = new SMTPConnection($host, $port, $context, $this->logger);
        $this->logger->setTimer(microtime(true));
        $this->connection->connect();

        $this->connection->read(); // Read greeting

        $this->greetServer(); // Greet server

        if ($context->getEncryption() == SMTPEncryption::STARTTLS) {
            if ($this->esmtp && !in_array(StarttlsHandler::capability(), $this->server_capabilities)) {
                $this->logger->log("STARTTLS was not advertised by the server. Aborting connection", 5);
                return false;
            }
            if (!$this->starttls()) {
                $this->logger->log("STARTTLS connection was not established. Aborting connection", 5);
                return false;
            }
        }
        // TODO: Fix returns true for no reason
        return true;
    }

    public function isConnected(): bool
    {
        return $this->connection->isConnected();
    }

    public function greetServer(): bool
    {
        // TODO: Check if connection is already established
        $helo = new HeloHandler($this->connection_host, $this, $this->esmtp_connect);
        $this->esmtp = $this->esmtp_connect;
        $result = $helo->handle($this->connection, $this);
        if (!$result) {
            $this->esmtp = false;
            $helo = new HeloHandler($this->connection_host, $this, $this->esmtp);
            $result = $helo->handle($this->connection, $this);
            if (!$result) {
                $this->logger->log("No greeting! Aborting connection", 5);
                throw new CommunicationException("Server did not greet back.");
            }
        }
        $this->server_capabilities = $helo->getCapabilities();
        $this->connection_host_remote = array_shift($this->server_capabilities);

        return $result;
    }

    private function starttls(): bool
    {
        // TODO: Check if connection is already established
        $starttls = new StarttlsHandler($this);
        return $starttls->handle($this->connection);
    }

    public function disconnect(): bool
    {
        // TODO: Check if connection is already established
        $quit = new QuitHandler($this);
        return $quit->handle($this->connection);
    }

    public function verify(String $mailbox, &$result): bool
    {
        // TODO: Check if connection is already established
        $verify = new VerifyHandler($mailbox, $this);
        if ($verify->handle($this->connection)) {
            $result = $verify->getResults();
            return true;
        }
        return false;
    }

    public function authenticate(AuthMechanism $mechanism, bool $force = false): ?bool
    {
        // TODO: Check if connection is already established
        if ($this->esmtp && !in_array($mechanism->smtpAuthMechanism(), $this->getServerLoginCapabilities())) {
            $this->logger->log("Auth mechanism '" . $mechanism->smtpAuthMechanism() . "' was not advertised by the server. Aborting unless forced", 4);
            if (!$force)
                return null;
        }
        return $mechanism->handle($this->connection);
    }

    public function getConnectionHost(): String
    {
        return $this->connection_host;
    }

    public function setConnectionHost(String $host): void
    {
        $this->connection_host = $host;
    }

    public function getRemoteConnectionHost(): String
    {
        return $this->connection_host_remote;
    }

    public function getServerCapabilities(): array
    {
        return $this->server_capabilities;
    }

    public function getServerLoginCapabilities(): array
    {
        $result = [];
        foreach ($this->server_capabilities as &$capability) {
            if (strtoupper(substr($capability, 0, 4)) == 'AUTH') {
                $list = strtoupper(substr($capability, 5));
                $token = strtok($list, ' ');
                while ($token !== false) {
                    $result[] = strtoupper($token);
                    $token = strtok(' ');
                }
            }
        }
        return $result;
    }

    public function supportsESMTP(): bool
    {
        return $this->esmtp;
    }

    public function disableESMTP(): void
    {
        // TODO: Check if connection is already established
        $this->esmtp_connect = false;
    }

    public function enableESMTP(): void
    {
        // TODO: Check if connection is already established
        $this->esmtp_connect = true;
    }

    public function getESMTP(): bool
    {
        return $this->esmtp_connect;
    }
}
