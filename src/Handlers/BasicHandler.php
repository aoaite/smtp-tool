<?php

namespace Aoaite\SmtpTool\Handlers;

use Aoaite\SmtpTool\Clients\SMTPClient;
use Aoaite\SmtpTool\Helpers\Parsers;
use Aoaite\SmtpTool\Helpers\SMTPHelpers;

abstract class BasicHandler implements SMTPHandler
{
    use Parsers, SMTPHelpers;

    protected SMTPClient $client;

    function __construct(SMTPClient $client)
    {
        $this->client = $client;
    }
}
