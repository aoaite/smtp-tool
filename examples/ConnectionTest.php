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

    /**
     * Spambots often don't wait for the greeting. Servers with a greeting delay (e.g. Postfix
     * postscreen with postscreen_greet_action = enforce or drop) catch them: postscreen sends
     * "220-", waits, and rejects clients that talk before the final "220 " line.
     */
    public function test_client_talking_before_greeting_is_rejected(): void
    {
        $this->client->open($this->env('SMTP_HOSTNAME'), $this->pregreetPort(), $this->context(SMTPEncryption::NONE));
        $this->client->sendRaw("EHLO pregreet.example.com\r\n", false); // before the banner
        $rejected = $this->client->readGreeting()->isFailure();

        if (!$rejected && $this->client->isConnected()) {
            // The reply to the early EHLO; a server that drops early talkers sends 521 here
            $rejected = $this->client->readReply()->isFailure();
        }
        if (!$rejected && $this->client->isConnected()) {
            // A server that plays along (postscreen "enforce") must refuse the mail
            $this->client->sendCommand('MAIL FROM:' . $this->mailbox());
            $rejected = $this->client->sendCommand('RCPT TO:' . $this->mailbox())->isFailure();
        }
        $this->assertTrue($rejected || !$this->client->isConnected(), "Early talker was not rejected:\n" . $this->transcript());
    }

    public function test_patient_client_passes_greeting_delay(): void
    {
        $this->connect($this->pregreetPort(), SMTPEncryption::NONE);
        $this->assertReplyCode(250, $this->client->sendCommand('MAIL FROM:' . $this->mailbox()));
        $this->assertReplyCode(250, $this->client->sendCommand('RCPT TO:' . $this->mailbox()));
        $this->client->sendCommand('RSET');
    }

    private function pregreetPort(): int
    {
        return (int)$this->env('SMTP_PREGREET_PORT', $this->env('SMTP_PORT'));
    }

    public function test_submission_requires_starttls_before_mail(): void
    {
        // Connect to the submission port *without* STARTTLS and try to start a transaction
        $this->connect((int)$this->env('SMTP_SUBMISSION_PORT'), SMTPEncryption::NONE);
        $this->assertRefused($this->client->sendCommand('MAIL FROM:<' . $this->env('SMTP_EXISTING_EMAIL') . '>'));
    }
}
