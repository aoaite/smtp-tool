<?php

namespace Examples;

use Aoaite\SmtpTool\Connection\SMTPEncryption;

/**
 * Does the server greet properly and offer encryption?
 */
class ConnectionTest extends SmtpServerTestCase
{
    public function test_greets_with_220_promptly(): void
    {
        $start = microtime(true);
        $this->connectSmtp();
        // Raise this if you use a deliberate greeting delay (e.g. postscreen's greet pause)
        $this->assertLessThan(5, microtime(true) - $start, 'Greeting took too long');
        $this->assertStringStartsWith('220', $this->client->getGreeting());
    }

    public function test_supports_esmtp(): void
    {
        $this->connectSmtp();
        $this->assertTrue($this->client->supportsESMTP(), 'EHLO was refused, only HELO works');
        $this->assertNotSame('', $this->client->getRemoteConnectionHost());
    }

    public function test_offers_starttls_on_port_25(): void
    {
        $this->connectSmtp();
        $this->assertTrue($this->client->hasCapability('STARTTLS'), 'STARTTLS not advertised');
    }

    public function test_starttls_negotiates_modern_tls(): void
    {
        $this->connectSmtpWithStartTls();
        $meta = $this->client->getConnectionMeta();
        $this->assertTrue($meta->isEncrypted());
        $this->assertContains($meta->getEncryptionProtocol(), ['TLSv1.2', 'TLSv1.3']);
    }

    public function test_implicit_tls_on_port_465(): void
    {
        $this->connectSmtps();
        $this->assertTrue($this->client->getConnectionMeta()->isEncrypted());
    }

    public function test_submission_requires_starttls_before_mail(): void
    {
        // Connect to the submission port *without* STARTTLS and try to start a transaction
        $this->connect((int)$this->env('SMTP_SUBMISSION_PORT'), SMTPEncryption::NONE);
        $this->assertRefused($this->client->sendCommand('MAIL FROM:<' . $this->env('SMTP_EXISTING_EMAIL') . '>'));
    }
}
