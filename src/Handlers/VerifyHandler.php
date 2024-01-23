<?php

namespace Aoaite\SmtpTool\Handlers;

use Aoaite\SmtpTool\Connection\SMTPConnection;
use Aoaite\SmtpTool\Clients\SMTPClient;

class VerifyHandler extends BasicHandler
{
    private String $mailbox;

    private array $results;

    function __construct(String $mailbox, SMTPClient $client)
    {
        $this->mailbox = $mailbox;
        parent::__construct($client);
    }

    public function handle(SMTPConnection $connection): bool
    {
        $connection->write('VRFY ' . $this->mailbox);
        $data = $connection->read();
        $code = $this->helper_parseResponseCode($data);
        $this->results = $this->helper_parseList($data, false);
        return $code >= 250 && $code <= 252;
    }

    public function getResults(): ?array
    {
        return $this->results;
    }

    public static function capability(): ?string
    {
        return 'VRFY';
    }
}
