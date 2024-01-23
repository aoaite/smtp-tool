<?php

namespace Aoaite\SmtpTool\Handlers;

use Aoaite\SmtpTool\Connection\SMTPConnection;
use Aoaite\SmtpTool\Clients\SMTPClient;

class HeloHandler extends BasicHandler
{
    private bool $esmtp;
    private String $host;

    private array $capabilities;

    function __construct(String $host, SMTPClient $client, bool $esmtp = true)
    {
        $this->host = $host;
        $this->esmtp = $esmtp;
        parent::__construct($client);
    }

    public function handle(SMTPConnection $connection): bool
    {
        $connection->write(($this->esmtp ? 'EHLO' : 'HELO') . ' ' . $this->host);
        $data = $connection->read();
        $code = $this->helper_parseResponseCode($data);
        if ($code == 250) {
            $this->capabilities = $this->helper_parseList($data);
            return true;
        }
        return false;
    }

    public function getCapabilities(): ?array
    {
        return $this->capabilities;
    }

    public static function capability(): ?string
    {
        return null;
    }
}
