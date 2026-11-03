<?php

namespace Examples;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Does the server refuse broken input instead of guessing what was meant?
 */
class MalformedInputTest extends SmtpServerTestCase
{
    public function test_unknown_command_is_rejected(): void
    {
        $this->connectSmtp();
        $this->assertTrue($this->client->sendCommand('XYZZY')->isPermanentFailure());
    }

    public function test_commands_out_of_sequence_are_rejected(): void
    {
        $this->connectSmtp();
        $mailbox = '<' . $this->env('SMTP_EXISTING_EMAIL') . '>';
        $this->assertReplyCode(503, $this->client->sendCommand('RCPT TO:' . $mailbox));  // RCPT before MAIL
        $this->assertReplyCode(503, $this->client->sendCommand('DATA'));                 // DATA before RCPT
        $this->assertReplyCode(250, $this->client->sendCommand('MAIL FROM:' . $mailbox));
        $this->assertReplyCode(503, $this->client->sendCommand('MAIL FROM:' . $mailbox)); // nested MAIL
    }

    public function test_malformed_address_is_rejected(): void
    {
        $this->connectSmtp();
        $this->assertReplyCode(501, $this->client->sendCommand('MAIL FROM:<unterminated@example.com'));
    }

    public static function smugglingVariants(): array
    {
        // End-of-data look-alikes from the SMTP smuggling research (SEC Consult, 2023).
        // Only "<CR><LF>.<CR><LF>" may end the DATA section.
        return [
            '<LF>.<LF>' => ["\n.\n"],
            '<LF>.<CR><LF>' => ["\n.\r\n"],
            '<CR><LF>.<LF>' => ["\r\n.\n"],
            '<CR>.<CR>' => ["\r.\r"],
            '<CR>.<CR><LF>' => ["\r.\r\n"],
            '<CR><LF>.<CR>' => ["\r\n.\r"],
        ];
    }

    /**
     * Sends a message whose body contains a fake end-of-data followed by a command.
     *
     * A safe server treats the whole thing as message content: it replies once (250) to the real
     * end of data, and the next command (NOOP) gets its own reply. A vulnerable server ends the
     * message early and runs the smuggled command, so the NOOP reads that command's stale reply.
     */
    #[DataProvider('smugglingVariants')]
    public function test_not_vulnerable_to_smtp_smuggling(String $fakeEnd): void
    {
        $this->connectSmtp();
        $mailbox = '<' . $this->env('SMTP_EXISTING_EMAIL') . '>';
        $this->assertReplyCode(250, $this->client->sendCommand('MAIL FROM:' . $mailbox));
        $this->assertReplyCode(250, $this->client->sendCommand('RCPT TO:' . $mailbox));
        $this->assertReplyCode(354, $this->client->sendCommand('DATA'));

        $data = "Subject: smtp-tool smuggling test\r\n\r\nbody" . $fakeEnd . "XSMUGGLED\r\n";
        $reply = $this->client->sendRaw($data . "\r\n.\r\n");
        if (!$reply->isPositiveCompletion()) {
            // Refusing the message (or bare CR/LF) outright is fine too
            $this->assertTrue($reply->isFailure(), $this->transcript());
            return;
        }
        $this->assertReplyCode(250, $this->client->sendCommand('NOOP'));
    }
}
