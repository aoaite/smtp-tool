<?php

namespace Examples;

use Aoaite\SmtpTool\Auth\AuthPlain;
use Aoaite\SmtpTool\Clients\BasicSMTPClient;
use Aoaite\SmtpTool\Connection\SMTPConnectionContext;
use Aoaite\SmtpTool\Connection\SMTPEncryption;
use Aoaite\SmtpTool\Connection\SMTPReply;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the example tests: reads the server settings from the SMTP_* variables
 * (phpunit.xml / environment), skips when the server can't be reached and has helpers to
 * connect in the usual ways.
 */
abstract class SmtpServerTestCase extends TestCase
{
    protected BasicSMTPClient $client;

    protected function setUp(): void
    {
        $socket = @fsockopen($this->env('SMTP_HOSTNAME'), (int)$this->env('SMTP_PORT'), $errno, $errstr, 2);
        if (!$socket) {
            $this->markTestSkipped("No SMTP server at {$this->env('SMTP_HOSTNAME')}:{$this->env('SMTP_PORT')} ($errstr)");
        }
        fclose($socket);
        $this->client = new BasicSMTPClient();
    }

    protected function tearDown(): void
    {
        if (isset($this->client) && $this->client->isConnected()) {
            $this->client->disconnect();
        }
    }

    protected function env(String $name, ?String $default = null): String
    {
        $value = $_ENV[$name] ?? getenv($name);
        if ($value === false || $value === null) {
            if ($default === null) {
                $this->fail("Environment variable $name is not set");
            }
            return $default;
        }
        return $value;
    }

    /**
     * With SMTP_VERIFY_TLS=true the certificate must be trusted (by the system CAs, or
     * SMTP_CA_FILE if set) and valid for SMTP_EXPECTED_HOSTNAME (or SMTP_HOSTNAME if not set);
     * otherwise any certificate is accepted.
     */
    protected function context(SMTPEncryption $encryption, ?bool $verify = null): SMTPConnectionContext
    {
        $verify ??= filter_var($this->env('SMTP_VERIFY_TLS', 'false'), FILTER_VALIDATE_BOOLEAN);
        $context = new SMTPConnectionContext($encryption, $verify, $verify, !$verify);
        if ($verify) {
            if ($this->env('SMTP_EXPECTED_HOSTNAME', '') !== '') {
                $context->setPeerName($this->env('SMTP_EXPECTED_HOSTNAME'));
            }
            if ($this->env('SMTP_CA_FILE', '') !== '') {
                $context->setCAFile($this->env('SMTP_CA_FILE'));
            }
        }
        return $context;
    }

    /** Port 25 (SMTP_PORT), plaintext */
    protected function connectSmtp(): void
    {
        $this->connect((int)$this->env('SMTP_PORT'), SMTPEncryption::NONE);
    }

    /** Port 25 (SMTP_PORT) upgraded with STARTTLS */
    protected function connectSmtpWithStartTls(): void
    {
        $this->connect((int)$this->env('SMTP_PORT'), SMTPEncryption::STARTTLS);
    }

    /** Port 587 (SMTP_SUBMISSION_PORT) with STARTTLS */
    protected function connectSubmission(): void
    {
        $this->connect((int)$this->env('SMTP_SUBMISSION_PORT'), SMTPEncryption::STARTTLS);
    }

    /** Port 465 (SMTP_SSL_PORT), implicit TLS */
    protected function connectSmtps(): void
    {
        $this->connect((int)$this->env('SMTP_SSL_PORT'), SMTPEncryption::TLS);
    }

    protected function connect(int $port, SMTPEncryption $encryption, ?SMTPConnectionContext $context = null): void
    {
        $connected = $this->client->connect($this->env('SMTP_HOSTNAME'), $port, $context ?? $this->context($encryption));
        $this->assertTrue($connected, "Could not connect to port $port:\n" . $this->transcript());
    }

    protected function mailbox(): String
    {
        return '<' . $this->env('SMTP_EXISTING_EMAIL') . '>';
    }

    protected function login(): void
    {
        $auth = new AuthPlain($this->env('SMTP_USERNAME'), $this->env('SMTP_PASSWORD'), $this->client);
        $this->assertTrue($this->client->authenticate($auth), "Login failed:\n" . $this->transcript());
    }

    protected function assertReplyCode(int $expected, SMTPReply $reply): void
    {
        $this->assertSame($expected, $reply->getCode(), "Unexpected reply:\n" . $this->transcript());
    }

    protected function assertRefused(SMTPReply $reply): void
    {
        $this->assertTrue($reply->isFailure(), "Expected a 4xx/5xx reply:\n" . $this->transcript());
    }

    /**
     * The conversation so far, for assertion messages.
     */
    protected function transcript(): String
    {
        $lines = array_map(
            fn ($entry) => sprintf('%6sms %s %s', $entry[0], $entry[1], rtrim($entry[2], "\r\n")),
            $this->client->getLogger()->getLogs()
        );
        return implode("\n", $lines);
    }
}
