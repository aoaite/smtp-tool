<?php

namespace Aoaite\SmtpTool\Handlers;

use Aoaite\SmtpTool\Connection\SMTPConnection;

class QuitHandler extends BasicHandler
{
    public function handle(SMTPConnection $connection): bool
    {
        $connection->write('QUIT');
        $data = $connection->read();
        $code = $this->helper_parseResponseCode($data);
        if ($code != 221) {
            $this->client->getLogger()->log("Unexpected response code", 4);
        }
        return true;
    }

    public static function capability(): ?string
    {
        return null;
    }
}
