<?php

namespace Tests\Unit;

use Aoaite\SmtpTool\Auth\AuthLogin;
use Aoaite\SmtpTool\Auth\AuthPlain;
use Aoaite\SmtpTool\Clients\BasicSMTPClient;
use Aoaite\SmtpTool\Connection\SMTPConnectionContext;
use Aoaite\SmtpTool\Connection\SMTPEncryption;
use Aoaite\SmtpTool\Exceptions\ConnectionException;
use Aoaite\SmtpTool\Helpers\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSmtpServer;

class ClientTest extends TestCase
{
    private BasicSMTPClient $client;

    protected function setUp(): void
    {
        $this->client = new BasicSMTPClient();
    }

    private function connect(FakeSmtpServer $server, SMTPEncryption $encryption = SMTPEncryption::NONE): bool
    {
        // The fake server uses a self-signed certificate
        $context = new SMTPConnectionContext($encryption, false, false, true);
        return $this->client->connect('127.0.0.1', $server->getPort(), $context);
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

    public function test_uses_injected_logger(): void
    {
        $logger = new Logger();
        $client = new BasicSMTPClient($logger);
        $this->assertSame($logger, $client->getLogger());
    }

    public function test_not_connected_before_connect(): void
    {
        $this->assertFalse($this->client->isConnected());
        $this->assertFalse($this->client->disconnect());
        $this->expectException(ConnectionException::class);
        $this->client->verify('a@example.com', $result);
    }

    public function test_connect_and_disconnect(): void
    {
        $server = FakeSmtpServer::start();
        $this->assertTrue($this->connect($server));
        $this->assertTrue($this->client->isConnected());
        $this->assertSame("220 fake.test ESMTP\r\n", $this->client->getGreeting());
        $this->assertSame('fake.test', $this->client->getRemoteConnectionHost());
        $this->assertContains('SIZE 10240000', $this->client->getServerCapabilities());
        $this->assertTrue($this->client->hasCapability('size'));
        $this->assertFalse($this->client->hasCapability('SMTPUTF8'));
        $this->assertFalse($this->client->getConnectionMeta()->isEncrypted());

        $this->assertTrue($this->client->disconnect());
        $this->assertFalse($this->client->isConnected());
        $this->assertSame(['EHLO localhost', 'QUIT'], $server->getCommands());
    }

    public function test_reply_ending_with_bare_code_does_not_block(): void
    {
        $server = FakeSmtpServer::start(['bareFinalLine' => true]);
        $start = microtime(true);
        $this->assertTrue($this->connect($server));
        $this->assertLessThan(2, microtime(true) - $start);
        $this->assertContains('DSN', $this->client->getServerCapabilities());
        $this->assertTrue($this->client->disconnect());
    }

    public function test_connect_fails_without_220_greeting(): void
    {
        $server = FakeSmtpServer::start(['greeting' => '554 5.3.2 Go away']);
        $this->assertFalse($this->connect($server));
        $this->assertSame("554 5.3.2 Go away\r\n", $this->client->getGreeting());
        $this->assertSame([], $server->getCommands());
    }

    public function test_falls_back_to_helo(): void
    {
        $server = FakeSmtpServer::start(['ehlo' => false]);
        $this->assertTrue($this->connect($server));
        $this->assertFalse($this->client->supportsESMTP());
        $this->assertSame(['EHLO localhost', 'HELO localhost'], $server->getCommands());
    }

    public function test_helo_only_when_esmtp_disabled(): void
    {
        $server = FakeSmtpServer::start();
        $this->client->disableESMTP();
        $this->assertTrue($this->connect($server));
        $this->assertSame(['HELO localhost'], $server->getCommands());
    }

    public function test_starttls_greets_again_and_refreshes_capabilities(): void
    {
        $server = FakeSmtpServer::startWithTls(['tlsCapabilities' => ['AUTH PLAIN LOGIN']]);
        $this->assertTrue($this->connect($server, SMTPEncryption::STARTTLS));
        $this->assertTrue($this->client->getConnectionMeta()->isEncrypted());
        $this->assertSame(['PLAIN', 'LOGIN'], $this->client->getServerLoginCapabilities());
        $this->assertSame(['EHLO localhost', 'STARTTLS', 'EHLO localhost'], $server->getCommands());
    }

    public function test_starttls_not_advertised(): void
    {
        $server = FakeSmtpServer::startWithTls(['capabilities' => ['SIZE 1000']]);
        $this->assertFalse($this->connect($server, SMTPEncryption::STARTTLS));
        $this->assertFalse($this->client->getConnectionMeta()->isEncrypted());
        $this->assertSame(['EHLO localhost'], $server->getCommands());
    }

    public function test_implicit_tls(): void
    {
        $server = FakeSmtpServer::startWithTls(['implicitTls' => true, 'tlsCapabilities' => ['AUTH PLAIN']]);
        $this->assertTrue($this->connect($server, SMTPEncryption::TLS));
        $this->assertTrue($this->client->getConnectionMeta()->isEncrypted());
        $this->assertSame(['PLAIN'], $this->client->getServerLoginCapabilities());
    }

    public function test_implicit_tls_rejects_self_signed_certificate_by_default(): void
    {
        $server = FakeSmtpServer::startWithTls(['implicitTls' => true]);
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('certificate verify failed');
        $this->client->connect('127.0.0.1', $server->getPort(), new SMTPConnectionContext(SMTPEncryption::TLS));
    }

    public function test_starttls_rejects_self_signed_certificate_by_default(): void
    {
        $server = FakeSmtpServer::startWithTls();
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('certificate verify failed');
        $this->client->connect('127.0.0.1', $server->getPort(), new SMTPConnectionContext(SMTPEncryption::STARTTLS));
    }

    public function test_auth_plain(): void
    {
        $server = FakeSmtpServer::start(['capabilities' => ['AUTH PLAIN LOGIN'], 'users' => ['user' => 'secret']]);
        $this->connect($server);
        $this->assertFalse($this->client->authenticate(new AuthPlain('user', 'wrong', $this->client)));
        $this->assertTrue($this->client->authenticate(new AuthPlain('user', 'secret', $this->client)));
        $this->assertSame(base64_encode("\0user\0secret"), $server->getAuthResponses()[1]);
    }

    public function test_auth_login(): void
    {
        $server = FakeSmtpServer::start(['capabilities' => ['AUTH=LOGIN', 'AUTH LOGIN'], 'users' => ['user' => 'secret']]);
        $this->connect($server);
        $this->assertTrue($this->client->authenticate(new AuthLogin('user', 'secret', $this->client)));
        $this->assertSame([base64_encode('user'), base64_encode('secret')], $server->getAuthResponses());
    }

    public function test_auth_not_advertised(): void
    {
        $server = FakeSmtpServer::start(['users' => ['user' => 'secret']]);
        $this->connect($server);
        $this->assertNull($this->client->authenticate(new AuthPlain('user', 'secret', $this->client)));
        $this->assertNotContains('AUTH PLAIN', $server->getCommands());
        // Forcing sends it anyway
        $this->assertTrue($this->client->authenticate(new AuthPlain('user', 'secret', $this->client), true));
    }

    public function test_verify(): void
    {
        $server = FakeSmtpServer::start(['vrfy' => ['known@example.com' => '252 2.0.0 known@example.com']]);
        $this->connect($server);
        $result = 'untouched';
        $this->assertFalse($this->client->verify('unknown@example.com', $result));
        $this->assertSame('untouched', $result);
        $this->assertTrue($this->client->verify('known@example.com', $result));
        $this->assertSame(['252 2.0.0 known@example.com'], $result);
    }

    public function test_send_command(): void
    {
        $server = FakeSmtpServer::start();
        $this->connect($server);
        $this->assertSame("250 2.0.0 Ok\r\n", $this->client->sendCommand('NOOP'));
        $this->assertStringStartsWith('502', $this->client->sendCommand('BOGUS'));
    }

    public function test_commands_reject_line_breaks(): void
    {
        $server = FakeSmtpServer::start();
        $this->connect($server);
        $this->expectException(\InvalidArgumentException::class);
        $this->client->verify("a@example.com\r\nQUIT", $result);
    }

    public function test_send_raw(): void
    {
        $server = FakeSmtpServer::start();
        $this->connect($server);
        // Two commands in one write: only the first reply is read here
        $this->assertSame("250 2.0.0 Ok\r\n", $this->client->sendRaw("NOOP\r\nRSET\r\n"));
        $this->assertSame("250 2.0.0 Ok\r\n", $this->client->getConnection()->read());
        $this->assertNull($this->client->sendRaw("NOOP\r\n", false));
        $this->assertSame(['EHLO localhost', 'NOOP', 'RSET'], array_slice($server->getCommands(), 0, 3));
    }
}
