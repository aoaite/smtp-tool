<?php

namespace Tests\Unit;

use Aoaite\SmtpTool\Auth\AuthLogin;
use Aoaite\SmtpTool\Auth\AuthPlain;
use Aoaite\SmtpTool\Clients\BasicSMTPClient;
use Aoaite\SmtpTool\Connection\SMTPConnectionContext;
use Aoaite\SmtpTool\Connection\SMTPEncryption;
use Aoaite\SmtpTool\Exceptions\CommunicationException;
use Aoaite\SmtpTool\Exceptions\ConnectionException;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private BasicSMTPClient $client;
    private SMTPConnectionContext $context;

    protected function setUp(): void
    {
        $this->client = new BasicSMTPClient();
        $this->context = new SMTPConnectionContext();
    }

    public function test_getters_and_setters(): void
    {
        $this->assertTrue($this->client->getESMTP());
        $this->client->disableESMTP();
        $this->assertFalse($this->client->getESMTP());
        $this->client->enableESMTP();
        $this->assertTrue($this->client->getESMTP());

        $this->assertTrue($this->client->supportsESMTP());

        $this->assertEmpty($this->client->getServerCapabilities());

        $this->assertEmpty($this->client->getServerLoginCapabilities());

        $this->assertEquals('', $this->client->getRemoteConnectionHost());

        $this->assertEquals('localhost', $this->client->getConnectionHost());
        $this->client->setConnectionHost('notlocalhost');
        $this->assertEquals('notlocalhost', $this->client->getConnectionHost());
    }

    public function test_connection_non_encrypted(): void
    {
        $this->client->connect($_ENV['SMTP_HOSTNAME'], 25, $this->context);
        $this->assertTrue($this->client->isConnected());
        $meta = $this->client->getConnectionMeta();
        $this->assertFalse($meta->isEncrypted());
        $this->assertTrue($this->client->disconnect());
        $this->assertNotEmpty($this->client->getLogger()->getLogs());
        $this->assertNotEmpty($this->client->getServerCapabilities());
        $this->assertEmpty($this->client->getServerLoginCapabilities());
    }

    public function test_connection_encrypted_bad_cert(): void
    {
        $this->context->setEncryption(SMTPEncryption::SSL);
        $this->expectException(ConnectionException::class);
        $this->client->connect($_ENV['SMTP_HOSTNAME'], 465, $this->context);
        $this->assertFalse($this->client->isConnected());
    }

    public function test_connection_encrypted_disable_verification(): void
    {
        $this->context->setEncryption(SMTPEncryption::SSL);
        $this->context->setSSLAllowSelfSigned(true);
        $this->context->setSSLVerifyPeerName(false);
        $this->client->connect($_ENV['SMTP_HOSTNAME'], 465, $this->context);
        $this->assertTrue($this->client->isConnected());
    }

    public function test_connection_encrypted_starttls_disable_verification(): void
    {
        $this->context->setEncryption(SMTPEncryption::STARTTLS);
        $this->context->setSSLAllowSelfSigned(true);
        $this->context->setSSLVerifyPeerName(false);
        $this->client->connect($_ENV['SMTP_HOSTNAME'], 587, $this->context);
        $this->assertTrue($this->client->isConnected());
        $meta = $this->client->getConnectionMeta();
        $this->assertTrue($meta->isEncrypted());
        $this->assertTrue($this->client->greetServer());
        $this->assertNotEmpty($this->client->getLogger()->getLogs());
        $this->assertNotEmpty($this->client->getServerCapabilities());
        $this->assertNotEmpty($this->client->getServerLoginCapabilities());
        $this->assertTrue($this->client->disconnect());
    }

    public function test_connection_authentication(): void
    {
        $this->context->setEncryption(SMTPEncryption::SSL);
        $this->context->setSSLAllowSelfSigned(true);
        $this->context->setSSLVerifyPeerName(false);
        $this->client->connect($_ENV['SMTP_HOSTNAME'], 465, $this->context);
        $this->assertTrue($this->client->isConnected());
        $meta = $this->client->getConnectionMeta();
        $this->assertTrue($meta->isEncrypted());
        $this->assertTrue($this->client->greetServer());
        $this->assertNotEmpty($this->client->getLogger()->getLogs());
        $this->assertNotEmpty($this->client->getServerCapabilities());
        $this->assertNotEmpty($this->client->getServerLoginCapabilities());
        $plain = new AuthPlain($_ENV['SMTP_USERNAME'], $_ENV['SMTP_PASSWORD'] . "WRONG", $this->client);
        $this->assertFalse($this->client->authenticate($plain));
        $plain = new AuthLogin($_ENV['SMTP_USERNAME'], $_ENV['SMTP_PASSWORD'], $this->client);
        $this->assertTrue($this->client->authenticate($plain));
        $this->assertTrue($this->client->greetServer());
        $this->assertTrue($this->client->disconnect());
    }

    public function test_connection_verify(): void
    {
        $this->context->setEncryption(SMTPEncryption::SSL);
        $this->context->setSSLAllowSelfSigned(true);
        $this->context->setSSLVerifyPeerName(false);
        $this->client->connect($_ENV['SMTP_HOSTNAME'], 465, $this->context);
        $this->assertTrue($this->client->isConnected());
        $meta = $this->client->getConnectionMeta();
        $this->assertTrue($meta->isEncrypted());
        $this->assertTrue($this->client->greetServer());
        $result = "WRONG";
        $this->assertFalse($this->client->verify('example@example.com', $result));
        $this->assertEquals($result, "WRONG");
        $result = null;
        $this->assertTrue($this->client->verify($_ENV['SMTP_EXISTING_EMAIL'], $result));
        $this->assertNotNull($result);
        $this->assertTrue($this->client->disconnect());
    }
}
