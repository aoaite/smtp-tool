<?php

namespace Examples;

use Aoaite\SmtpTool\Mail\BasicMessage;
use Aoaite\SmtpTool\Mail\Contact;

/**
 * Does the server accept the mail it should and refuse the mail it shouldn't?
 */
class MailTransactionTest extends SmtpServerTestCase
{
    public function test_accepts_mail_for_local_mailbox(): void
    {
        $this->connectSmtp();
        $mailbox = new Contact($this->env('SMTP_EXISTING_EMAIL'));
        $message = new BasicMessage($mailbox, $mailbox, "Sent by the smtp-tool examples.", 'smtp-tool example');
        $this->assertTrue($this->client->sendMail($message, [], $results), $this->transcript());
    }

    public function test_rejects_unknown_recipient(): void
    {
        $this->connectSmtp();
        $domain = substr(strrchr($this->env('SMTP_EXISTING_EMAIL'), '@'), 1);
        $this->client->sendCommand('MAIL FROM:<' . $this->env('SMTP_EXISTING_EMAIL') . '>');
        $reply = $this->client->sendCommand('RCPT TO:<no-such-user-' . bin2hex(random_bytes(4)) . '@' . $domain . '>');
        // 550 5.1.1 "user unknown" - rejecting at RCPT time avoids sending backscatter later
        $this->assertTrue($reply->isPermanentFailure(), $this->transcript());
        $this->assertSame('5.1.1', $reply->getEnhancedCode());
    }

    public function test_is_not_an_open_relay(): void
    {
        $this->connectSmtp();
        $this->client->sendCommand('MAIL FROM:<' . $this->env('SMTP_EXISTING_EMAIL') . '>');
        $this->assertRefused($this->client->sendCommand('RCPT TO:<' . $this->env('SMTP_RELAY_RECIPIENT') . '>'));
    }

    public function test_accepts_null_sender(): void
    {
        // Bounces and delivery reports are sent with an empty reverse-path; refusing them breaks DSNs
        $this->connectSmtp();
        $this->assertReplyCode(250, $this->client->sendCommand('MAIL FROM:<>'));
        $this->assertReplyCode(250, $this->client->sendCommand('RCPT TO:<' . $this->env('SMTP_EXISTING_EMAIL') . '>'));
        $this->client->sendCommand('RSET');
    }

    public function test_refuses_oversized_message_announced_with_size(): void
    {
        $this->connectSmtp();
        if (!$this->client->hasCapability('SIZE')) {
            $this->markTestSkipped('Server does not advertise SIZE');
        }
        $reply = $this->client->sendCommand('MAIL FROM:<' . $this->env('SMTP_EXISTING_EMAIL') . '> SIZE=999999999999');
        $this->assertSame(552, $reply->getCode(), $this->transcript());
    }
}
