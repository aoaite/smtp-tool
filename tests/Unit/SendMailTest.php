<?php

namespace Tests\Unit;

use Aoaite\SmtpTool\Clients\BasicSMTPClient;
use Aoaite\SmtpTool\Connection\SMTPConnectionContext;
use Aoaite\SmtpTool\Mail\BasicMessage;
use Aoaite\SmtpTool\Mail\Contact;
use Aoaite\SmtpTool\Mail\CrudeMessage;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSmtpServer;

class SendMailTest extends TestCase
{
    private BasicSMTPClient $client;

    protected function setUp(): void
    {
        $this->client = new BasicSMTPClient();
    }

    private function start(array $options = []): FakeSmtpServer
    {
        $server = FakeSmtpServer::start($options);
        $this->client->connect('127.0.0.1', $server->getPort(), new SMTPConnectionContext());
        return $server;
    }

    private function transactionCommands(FakeSmtpServer $server): array
    {
        return array_slice($server->getCommands(), 1); // skip EHLO
    }

    public function test_sends_basic_message(): void
    {
        $server = $this->start();
        $message = new BasicMessage(
            new Contact('sender@example.com', 'Sender'),
            [new Contact('one@example.com'), new Contact('two@example.com', 'Two')],
            "Hello\n.leading dot\nBye",
            'Test'
        );
        $this->assertTrue($this->client->sendMail($message, [], $results));

        $this->assertSame([
            'MAIL FROM:<sender@example.com>',
            'RCPT TO:<one@example.com>',
            'RCPT TO:<two@example.com>',
            'DATA',
        ], $this->transactionCommands($server));

        $data = $server->getData()[0];
        $this->assertStringContainsString("\r\nFrom: Sender <sender@example.com>\r\n", $data);
        $this->assertStringContainsString("\r\nTo: one@example.com, Two <two@example.com>\r\n", $data);
        $this->assertStringContainsString("\r\nSubject: Test\r\n", $data);
        $this->assertStringEndsWith("\r\n\r\nHello\r\n..leading dot\r\nBye\r\n", $data);

        $this->assertSame(['MAIL FROM:<sender@example.com>', 250], [$results[0]['command'], $results[0]['code']]);
        $this->assertSame(['.', 250], [end($results)['command'], end($results)['code']]);
    }

    public function test_continues_when_some_recipients_are_rejected(): void
    {
        $server = $this->start(['rejectRecipients' => ['<bad@example.com>']]);
        $message = new CrudeMessage('<a@example.com>', ['<bad@example.com>', '<good@example.com>'], "Subject: x\r\n\r\nbody\r\n");
        $this->assertTrue($this->client->sendMail($message, [], $results));
        $this->assertSame(550, $results[1]['code']);
        $this->assertCount(1, $server->getData());
    }

    public function test_resets_when_all_recipients_are_rejected(): void
    {
        $server = $this->start(['rejectRecipients' => ['<bad@example.com>']]);
        $message = new CrudeMessage('<a@example.com>', '<bad@example.com>', "body\r\n");
        $this->assertFalse($this->client->sendMail($message));
        $this->assertSame(['MAIL FROM:<a@example.com>', 'RCPT TO:<bad@example.com>', 'RSET'], $this->transactionCommands($server));
        $this->assertSame([], $server->getData());
        // The connection is still usable
        $this->assertTrue($this->client->sendMail(new CrudeMessage('<a@example.com>', '<ok@example.com>', "body\r\n")));
    }

    public function test_dsn_notify_when_advertised(): void
    {
        $server = $this->start();
        $this->client->sendMail(new CrudeMessage('<a@example.com>', '<b@example.com>', "x\r\n"), ['success', 'failure']);
        $this->assertSame('RCPT TO:<b@example.com> NOTIFY=SUCCESS,FAILURE', $this->transactionCommands($server)[1]);
    }

    public function test_dsn_notify_omitted_when_not_advertised(): void
    {
        $server = $this->start(['capabilities' => ['SIZE 1000']]);
        $this->client->sendMail(new CrudeMessage('<a@example.com>', '<b@example.com>', "x\r\n"), ['SUCCESS']);
        $this->assertSame('RCPT TO:<b@example.com>', $this->transactionCommands($server)[1]);
    }

    public function test_bdat_single_chunk(): void
    {
        $server = $this->start(['capabilities' => ['CHUNKING']]);
        $message = new BasicMessage(new Contact('a@example.com'), new Contact('b@example.com'), ".not stuffed\nend");
        $this->assertTrue($this->client->sendMailWithBdat($message, 0, [], $results));
        $content = $message->getContent();
        $this->assertSame(['MAIL FROM:<a@example.com>', 'RCPT TO:<b@example.com>', 'BDAT ' . strlen($content) . ' LAST'], $this->transactionCommands($server));
        $this->assertSame([$content], $server->getData());
        $this->assertStringEndsWith("\r\n.not stuffed\r\nend\r\n", $server->getData()[0]);
    }

    public function test_bdat_multiple_chunks(): void
    {
        $server = $this->start(['capabilities' => ['CHUNKING']]);
        $content = "Subject: chunks\r\n\r\n0123456789abcdefghij\r\n";
        $this->assertTrue($this->client->sendMailWithBdat(new CrudeMessage('<a@example.com>', '<b@example.com>', $content), 10));
        // 41 bytes: four chunks of 10 and a last one of 1
        $this->assertSame(['BDAT 10', 'BDAT 10', 'BDAT 10', 'BDAT 10', 'BDAT 1 LAST'], array_slice($this->transactionCommands($server), 2));
        $this->assertSame([$content], $server->getData());
    }

    public function test_bdat_sends_fake_end_of_data_as_content(): void
    {
        $server = $this->start(['capabilities' => ['CHUNKING']]);
        $content = "Subject: x\r\n\r\nbody\r\n.\r\nXSMUGGLED\r\n";
        $this->assertTrue($this->client->sendMailWithBdat(new CrudeMessage('<a@example.com>', '<b@example.com>', $content)));
        $this->assertSame([$content], $server->getData());
        $this->assertNotContains('XSMUGGLED', $server->getCommands());
        $this->assertSame(250, $this->client->sendCommand('NOOP')->getCode());
    }

    public function test_bdat_rejected_resets(): void
    {
        $server = $this->start(['capabilities' => ['CHUNKING'], 'rejectBdat' => true]);
        $this->assertFalse($this->client->sendMailWithBdat(new CrudeMessage('<a@example.com>', '<b@example.com>', "x\r\n"), 0, [], $results));
        $this->assertSame(554, $results[2]['code']);
        $commands = $this->transactionCommands($server);
        $this->assertSame('RSET', end($commands));
    }

    public function test_bdat_without_chunking_is_sent_anyway(): void
    {
        $server = $this->start(['capabilities' => []]);
        $this->assertTrue($this->client->sendMailWithBdat(new CrudeMessage('<a@example.com>', '<b@example.com>', "x\r\n")));
        $this->assertStringContainsString('CHUNKING was not advertised', json_encode($this->client->getLogger()->getLogs()));
    }

    public function test_crude_message_data_is_sent_verbatim(): void
    {
        $server = $this->start();
        // Bare LF line endings and a "\n.\n" that a lenient server might take as end of data
        $data = "Subject: smuggling\n\nfirst\n.\nMAIL FROM:<x@example.com>";
        $this->assertTrue($this->client->sendMail(new CrudeMessage('<a@example.com>', '<b@example.com>', $data)));
        $this->assertSame($data . "\r\n", $server->getData()[0]);
    }
}
