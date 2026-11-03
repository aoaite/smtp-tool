<?php

namespace Aoaite\SmtpTool\Clients;

use Aoaite\SmtpTool\Auth\AuthMechanism;
use Aoaite\SmtpTool\Connection\SMTPConnection;
use Aoaite\SmtpTool\Connection\SMTPConnectionContext;
use Aoaite\SmtpTool\Connection\SMTPEncryption;
use Aoaite\SmtpTool\Exceptions\CommunicationException;
use Aoaite\SmtpTool\Exceptions\ConnectionException;
use Aoaite\SmtpTool\Handlers\HeloHandler;
use Aoaite\SmtpTool\Handlers\QuitHandler;
use Aoaite\SmtpTool\Handlers\SendMailHandler;
use Aoaite\SmtpTool\Handlers\StarttlsHandler;
use Aoaite\SmtpTool\Handlers\VerifyHandler;
use Aoaite\SmtpTool\Mail\Message;

class BasicSMTPClient extends SMTPClient
{
    private bool $esmtp = true;
    private bool $esmtp_connect = true;

    private String $connection_host = 'localhost';
    private String $connection_host_remote = '';

    private array $server_capabilities = [];

    private String $greeting = '';

    /**
     * Connects, reads the greeting and greets the server (EHLO, falling back to HELO).
     * With STARTTLS encryption the TLS handshake is done and the server is greeted again.
     *
     * Returns false if the server did not send a 220 greeting or STARTTLS could not be
     * established; the connection is left open in that case so it can still be inspected.
     */
    public function connect(String $host, int $port, SMTPConnectionContext $context): bool
    {
        $this->connection = new SMTPConnection($host, $port, $context, $this->logger);
        $this->logger->setTimer(microtime(true));
        $this->connection->connect();

        $this->server_capabilities = [];
        $this->connection_host_remote = '';
        $this->greeting = $this->connection->read() ?? '';
        if (intval(substr($this->greeting, 0, 3)) != 220) {
            $this->logger->log("Server did not send a 220 greeting. Aborting connection", 5);
            return false;
        }

        $this->greetServer(); // Greet server

        if ($context->getEncryption() == SMTPEncryption::STARTTLS) {
            if ($this->esmtp && !$this->hasCapability(StarttlsHandler::capability())) {
                $this->logger->log("STARTTLS was not advertised by the server. Aborting connection", 5);
                return false;
            }
            if (!$this->starttls()) {
                $this->logger->log("STARTTLS connection was not established. Aborting connection", 5);
                return false;
            }
            // RFC 3207: capabilities learned before STARTTLS must be discarded, so greet again
            $this->greetServer();
        }
        return true;
    }

    /**
     * The server's greeting banner as received on connect, e.g. "220 mail.example.com ESMTP\r\n".
     */
    public function getGreeting(): String
    {
        return $this->greeting;
    }

    public function isConnected(): bool
    {
        return isset($this->connection) && $this->connection->isConnected();
    }

    public function greetServer(): bool
    {
        $this->requireConnection();
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
        $this->requireConnection();
        $starttls = new StarttlsHandler($this);
        return $starttls->handle($this->connection);
    }

    /**
     * Sends QUIT and closes the socket. Returns false if there was no open connection.
     */
    public function disconnect(): bool
    {
        if (!$this->isConnected()) {
            if (isset($this->connection)) {
                $this->connection->close();
            }
            return false;
        }
        $quit = new QuitHandler($this);
        $result = $quit->handle($this->connection);
        $this->connection->close();
        return $result;
    }

    public function verify(String $mailbox, &$result): bool
    {
        $this->requireConnection();
        $verify = new VerifyHandler($mailbox, $this);
        if ($verify->handle($this->connection)) {
            $result = $verify->getResults();
            return true;
        }
        return false;
    }

    /**
     * Runs a mail transaction. $results receives every command sent with the server's reply.
     *
     * @param String[] $dsnNotify DSN NOTIFY values, e.g. ['SUCCESS', 'FAILURE']. Only sent if the
     *                           server advertises DSN, otherwise a warning is logged and it is omitted.
     */
    public function sendMail(Message $message, array $dsnNotify = [], &$results = null): bool
    {
        $this->requireConnection();
        if ($dsnNotify && !($this->esmtp && $this->hasCapability('DSN'))) {
            $this->logger->log("DSN was not advertised by the server. Sending without NOTIFY", 4);
            $dsnNotify = [];
        }
        $handler = new SendMailHandler($message, $this, $dsnNotify);
        $result = $handler->handle($this->connection);
        $results = $handler->getResults();
        return $result;
    }

    public function authenticate(AuthMechanism $mechanism, bool $force = false): ?bool
    {
        $this->requireConnection();
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

    /**
     * Whether the server advertised an EHLO keyword (case-insensitive), e.g. "STARTTLS", "DSN", "SIZE".
     */
    public function hasCapability(String $keyword): bool
    {
        foreach ($this->server_capabilities as $capability) {
            if (strcasecmp(strtok($capability, ' ='), $keyword) === 0) {
                return true;
            }
        }
        return false;
    }

    private function requireConnection(): void
    {
        if (!$this->isConnected()) {
            throw new ConnectionException("Not connected");
        }
    }

    public function getServerLoginCapabilities(): array
    {
        $result = [];
        foreach ($this->server_capabilities as $capability) {
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

    /**
     * Use HELO instead of EHLO on the next greeting (takes effect on connect() / greetServer()).
     */
    public function disableESMTP(): void
    {
        $this->esmtp_connect = false;
    }

    public function enableESMTP(): void
    {
        $this->esmtp_connect = true;
    }

    public function getESMTP(): bool
    {
        return $this->esmtp_connect;
    }
}
