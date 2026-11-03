<?php

namespace Aoaite\SmtpTool\Handlers;

use Aoaite\SmtpTool\Connection\SMTPConnection;
use Aoaite\SmtpTool\Exceptions\ConnectionException;
use Aoaite\SmtpTool\Helpers\PhpErrors;

class StarttlsHandler extends BasicHandler
{
    public function handle(SMTPConnection $connection): bool
    {
        $connection->write('STARTTLS');
        $data = $connection->read();
        $code = $this->helper_parseResponseCode($data);
        if ($code == 220) {
            $result = PhpErrors::capture(
                fn () => stream_socket_enable_crypto($connection->getConnection(), true, $connection->getContext()->getCryptoMethod()),
                $warnings
            );
            if ($result === true) {
                return true;
            }
            if ($warnings) {
                // TLS handshake failed (e.g. certificate verification); the connection is unusable now
                $message = implode('; ', $warnings);
                $this->client->getLogger()->log("ERROR: " . $message, 5);
                throw new ConnectionException($message);
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
