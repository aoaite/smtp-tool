<?php

namespace Tests\Unit;

use Aoaite\SmtpTool\Mail\BasicMessage;
use Aoaite\SmtpTool\Mail\Contact;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_contact_formats(): void
    {
        $this->assertSame('<a@example.com>', (new Contact('a@example.com', 'A'))->getEnvelope());
        $this->assertSame('a@example.com', (new Contact('a@example.com'))->getRFC());
        $this->assertSame('John Doe <a@example.com>', (new Contact('a@example.com', 'John Doe'))->getRFC());
        $this->assertSame('"Doe, John" <a@example.com>', (new Contact('a@example.com', 'Doe, John'))->getRFC());
        $this->assertSame('=?UTF-8?B?' . base64_encode('Jürg') . '?= <a@example.com>', (new Contact('a@example.com', 'Jürg'))->getRFC());
    }

    public function test_basic_message_headers(): void
    {
        $message = new BasicMessage(
            new Contact('from@example.com'),
            new Contact('to@example.com'),
            'body',
            'Grüezi',
            ['X-Test' => 'yes', 'content-type' => 'text/html']
        );
        $headers = $message->getHeaders();
        $this->assertSame('from@example.com', $headers['From']);
        $this->assertSame('to@example.com', $headers['To']);
        $this->assertSame('=?UTF-8?B?' . base64_encode('Grüezi') . '?=', $headers['Subject']);
        $this->assertMatchesRegularExpression('/^<[0-9a-f]{32}@example\.com>$/', $headers['Message-ID']);
        $this->assertSame('yes', $headers['X-Test']);
        $this->assertSame('text/html', $headers['content-type']);
        $this->assertArrayNotHasKey('Content-Type', $headers);

        $this->assertSame(['<to@example.com>'], $message->getEnvelopeRecipients());
        $this->assertSame('<from@example.com>', $message->getEnvelopeFrom());
        $this->assertStringEndsWith("\r\n\r\nbody\r\n", $message->getData());
    }

    public function test_data_is_normalised_and_dot_stuffed(): void
    {
        $message = new BasicMessage(new Contact('a@example.com'), new Contact('b@example.com'), ".one\r\ntwo\n.\rthree\r\n");
        $this->assertStringEndsWith("\r\n\r\n..one\r\ntwo\r\n..\r\nthree\r\n", $message->getData());
    }

    public function test_requires_recipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BasicMessage(new Contact('a@example.com'), [], 'body');
    }
}
