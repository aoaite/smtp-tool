<?php

namespace Tests\Unit;

use Aoaite\SmtpTool\Connection\SMTPConnectionContext;
use Aoaite\SmtpTool\Connection\SMTPEncryption;
use PHPUnit\Framework\TestCase;

class ContextTest extends TestCase
{
    private SMTPConnectionContext $context;

    protected function setUp(): void
    {
        $this->context = new SMTPConnectionContext();
    }

    public function test_getters_and_setters(): void
    {
        $this->assertTrue($this->context->getEncryption() === SMTPEncryption::NONE);
        $this->context->setEncryption(SMTPEncryption::TLS);
        $this->assertTrue($this->context->getEncryption() === SMTPEncryption::TLS);

        $this->assertTrue($this->context->getCryptoMethod() === STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $this->context->setCryptoMethod(STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        $this->assertTrue($this->context->getCryptoMethod() === STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
    }
}
