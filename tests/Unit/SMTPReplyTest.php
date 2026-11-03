<?php

namespace Tests\Unit;

use Aoaite\SmtpTool\Connection\SMTPReply;
use PHPUnit\Framework\TestCase;

class SMTPReplyTest extends TestCase
{
    public function test_single_line(): void
    {
        $reply = SMTPReply::parse("550 5.1.1 <a@example.com>: Recipient address rejected\r\n");
        $this->assertSame(550, $reply->getCode());
        $this->assertSame('5.1.1', $reply->getEnhancedCode());
        $this->assertSame(['5.1.1 <a@example.com>: Recipient address rejected'], $reply->getLines());
        $this->assertTrue($reply->isPermanentFailure());
        $this->assertTrue($reply->isFailure());
        $this->assertFalse($reply->isTransientFailure());
        $this->assertFalse($reply->isPositiveCompletion());
    }

    public function test_multi_line(): void
    {
        $reply = SMTPReply::parse("250-mail.example.com\r\n250-SIZE 1000\r\n250 DSN\r\n");
        $this->assertSame(250, $reply->getCode());
        $this->assertNull($reply->getEnhancedCode());
        $this->assertSame(['mail.example.com', 'SIZE 1000', 'DSN'], $reply->getLines());
        $this->assertSame("mail.example.com\nSIZE 1000\nDSN", $reply->getText());
        $this->assertTrue($reply->isPositiveCompletion());
    }

    public function test_bare_code_and_intermediate(): void
    {
        $reply = SMTPReply::parse("354\r\n");
        $this->assertSame(354, $reply->getCode());
        $this->assertSame([''], $reply->getLines());
        $this->assertTrue($reply->isPositiveIntermediate());
        $this->assertTrue(SMTPReply::parse("454 4.7.1 Relay access denied\r\n")->isTransientFailure());
    }

    public function test_empty_or_garbage(): void
    {
        foreach ([null, '', "garbage\r\n"] as $raw) {
            $reply = SMTPReply::parse($raw);
            $this->assertSame(0, $reply->getCode());
            $this->assertFalse($reply->isFailure());
            $this->assertFalse($reply->isPositiveCompletion());
        }
        $this->assertSame([], SMTPReply::parse('')->getLines());
        $this->assertSame("garbage\r\n", (string)SMTPReply::parse("garbage\r\n"));
    }
}
