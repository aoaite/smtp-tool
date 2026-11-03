<?php

namespace Tests\Integration;

use Aoaite\SmtpTool\Auth\AuthLogin;
use Aoaite\SmtpTool\Auth\AuthPlain;
use Aoaite\SmtpTool\Clients\BasicSMTPClient;
use Aoaite\SmtpTool\Connection\SMTPConnectionContext;
use Aoaite\SmtpTool\Connection\SMTPEncryption;
use Aoaite\SmtpTool\Exceptions\ConnectionException;
use Aoaite\SmtpTool\Mail\BasicMessage;
use Aoaite\SmtpTool\Mail\Contact;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real SMTP server, configured through the SMTP_* variables in
 * phpunit.xml(.dist) or the environment. `docker compose up -d --wait` starts one.
 * Skipped when the server cannot be reached.
 */
class ClientTest extends TestCase
{
    private BasicSMTPClient $client;
    private SMTPConnectionContext $context;

    private String $host;
    private int $port;
    private int $sslPort;
    private int $submissionPort;

    protected function setUp(): void
    {
        $this->host = $_ENV['SMTP_HOSTNAME'];
        $this->port = (int)$_ENV['SMTP_PORT'];
        $this->sslPort = (int)$_ENV['SMTP_SSL_PORT'];
        $this->submissionPort = (int)$_ENV['SMTP_SUBMISSION_PORT'];

        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 2);
        if (!$socket) {
            $this->markTestSkipped("No SMTP server at {$this->host}:{$this->port} ($errstr)");
        }
        fclose($socket);

        $this->client = new BasicSMTPClient();
        $this->context = new SMTPConnectionContext();
    }

    private function disableVerification(): void
    {
        $this->context->setSSLAllowSelfSigned(true);
        $this->context->setSSLVerifyPeer(false);
        $this->context->setSSLVerifyPeerName(false);
    }

    public function test_connection_non_encrypted(): void
    {
        $this->assertTrue($this->client->connect($this->host, $this->port, $this->context));
        $this->assertTrue($this->client->isConnected());
        $this->assertStringStartsWith('220 ', $this->client->getGreeting());
        $meta = $this->client->getConnectionMeta();
        $this->assertFalse($meta->isEncrypted());
        $this->assertNotEmpty($this->client->getServerCapabilities());
        $this->assertEmpty($this->client->getServerLoginCapabilities());
        $this->assertTrue($this->client->disconnect());
        $this->assertNotEmpty($this->client->getLogger()->getLogs());
    }

    public function test_connection_encrypted_bad_cert(): void
    {
        $this->context->setEncryption(SMTPEncryption::SSL);
        $this->expectException(ConnectionException::class);
        $this->client->connect($this->host, $this->sslPort, $this->context);
    }

    public function test_connection_starttls_bad_cert(): void
    {
        $this->context->setEncryption(SMTPEncryption::STARTTLS);
        $this->expectException(ConnectionException::class);
        $this->client->connect($this->host, $this->submissionPort, $this->context);
    }

    public function test_connection_encrypted_disable_verification(): void
    {
        $this->context->setEncryption(SMTPEncryption::SSL);
        $this->disableVerification();
        $this->assertTrue($this->client->connect($this->host, $this->sslPort, $this->context));
        $this->assertTrue($this->client->isConnected());
        $this->assertTrue($this->client->getConnectionMeta()->isEncrypted());
        $this->assertTrue($this->client->disconnect());
    }

    public function test_connection_encrypted_starttls_disable_verification(): void
    {
        $this->context->setEncryption(SMTPEncryption::STARTTLS);
        $this->disableVerification();
        $this->assertTrue($this->client->connect($this->host, $this->submissionPort, $this->context));
        $this->assertTrue($this->client->isConnected());
        $this->assertTrue($this->client->getConnectionMeta()->isEncrypted());
        // Capabilities are refreshed after STARTTLS, so AUTH is visible without greeting again
        $this->assertNotEmpty($this->client->getServerLoginCapabilities());
        $this->assertTrue($this->client->greetServer());
        $this->assertTrue($this->client->disconnect());
    }

    public function test_connection_authentication(): void
    {
        $this->context->setEncryption(SMTPEncryption::SSL);
        $this->disableVerification();
        $this->client->connect($this->host, $this->sslPort, $this->context);
        $this->assertNotEmpty($this->client->getServerLoginCapabilities());
        $plain = new AuthPlain($_ENV['SMTP_USERNAME'], $_ENV['SMTP_PASSWORD'] . "WRONG", $this->client);
        $this->assertFalse($this->client->authenticate($plain));
        $login = new AuthLogin($_ENV['SMTP_USERNAME'], $_ENV['SMTP_PASSWORD'], $this->client);
        $this->assertTrue($this->client->authenticate($login));
        $this->assertTrue($this->client->greetServer());
        $this->assertTrue($this->client->disconnect());
    }

    public function test_connection_verify(): void
    {
        $this->context->setEncryption(SMTPEncryption::SSL);
        $this->disableVerification();
        $this->client->connect($this->host, $this->sslPort, $this->context);
        $result = "WRONG";
        $this->assertFalse($this->client->verify('example@example.com', $result));
        $this->assertEquals("WRONG", $result);
        $result = null;
        $this->assertTrue($this->client->verify($_ENV['SMTP_EXISTING_EMAIL'], $result));
        $this->assertNotNull($result);
        $this->assertTrue($this->client->disconnect());
    }

    public function test_send_mail(): void
    {
        $this->client->connect($this->host, $this->port, $this->context);
        $mailbox = new Contact($_ENV['SMTP_EXISTING_EMAIL'], 'Test Mailbox');
        $message = new BasicMessage($mailbox, $mailbox, "Hello from the smtp-tool tests.\n.\nStill the body.", 'smtp-tool test');
        $this->assertTrue($this->client->sendMail($message, ['SUCCESS', 'FAILURE'], $results), json_encode($results));
        $this->assertSame(250, end($results)['code']);
        $this->assertTrue($this->client->disconnect());
    }

    public function test_send_mail_unknown_recipient(): void
    {
        $this->client->connect($this->host, $this->port, $this->context);
        $message = new BasicMessage(new Contact($_ENV['SMTP_EXISTING_EMAIL']), new Contact('example@example.com'), 'body');
        $this->assertFalse($this->client->sendMail($message, [], $results));
        $this->assertSame(550, $results[1]['code']);
        $this->assertSame('RSET', end($results)['command']);
        $this->assertTrue($this->client->disconnect());
    }

    public function test_send_mail_authenticated(): void
    {
        $this->context->setEncryption(SMTPEncryption::STARTTLS);
        $this->disableVerification();
        $this->client->connect($this->host, $this->submissionPort, $this->context);
        $this->assertTrue($this->client->authenticate(new AuthPlain($_ENV['SMTP_USERNAME'], $_ENV['SMTP_PASSWORD'], $this->client)));
        $mailbox = new Contact($_ENV['SMTP_EXISTING_EMAIL']);
        $this->assertTrue($this->client->sendMail(new BasicMessage($mailbox, $mailbox, 'Sent after AUTH'), [], $results), json_encode($results));
        $this->assertTrue($this->client->disconnect());
    }

    public function test_send_raw_command(): void
    {
        $this->client->connect($this->host, $this->port, $this->context);
        $this->assertStringStartsWith('250', $this->client->sendCommand('NOOP'));
        $this->assertStringStartsWith('5', $this->client->sendRaw("BOGUS\r\n"));
        $this->assertTrue($this->client->disconnect());
    }
}
