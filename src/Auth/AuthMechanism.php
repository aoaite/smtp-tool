<?php

namespace Aoaite\SmtpTool\Auth;

use Aoaite\SmtpTool\Connection\SMTPConnection;
use Aoaite\SmtpTool\Helpers\Parsers;
use Aoaite\SmtpTool\Helpers\SMTPHelpers;
use Aoaite\SmtpTool\Clients\SMTPClient;

abstract class AuthMechanism
{
    use Parsers, SMTPHelpers;

    protected SMTPClient $client;

    function __construct(SMTPClient $client)
    {
        $this->client = $client;
    }

    abstract public function handle(SMTPConnection $connection): ?bool;
    abstract public static function smtpAuthMechanism(): String;
}
