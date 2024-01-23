<?php

namespace Aoaite\SmtpTool\Handlers;

use Aoaite\SmtpTool\Connection\SMTPConnection;
use Aoaite\SmtpTool\Exceptions\ConnectionException;

class StarttlsHandler extends BasicHandler
{
    public function handle(SMTPConnection $connection): bool
    {
        $connection->write('STARTTLS');
        $data = $connection->read();
        $code = $this->helper_parseResponseCode($data);
        if ($code == 220) {
            try {
                $result = stream_socket_enable_crypto($connection->getConnection(), true, $connection->getContext()->getCryptoMethod(), $connection->getConnection());
            } catch (\Throwable $e) {
                $this->client->getLogger()->log("ERROR: " . $e->getMessage(), 5);
                throw new ConnectionException($e->getMessage(), 1, $e);
            }
            if ($result === true) {
                return true;
            }
        }
        $this->client->getLogger()->log("STARTTLS connection rejected", 4);
        return false;
    }

    public static function capability(): ?string
    {
        return 'STARTTLS';
    }
}
