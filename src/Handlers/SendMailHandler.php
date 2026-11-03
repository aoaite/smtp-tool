<?php

namespace Aoaite\SmtpTool\Handlers;

use Aoaite\SmtpTool\Connection\SMTPConnection;
use Aoaite\SmtpTool\Mail\Message;
use Aoaite\SmtpTool\Clients\SMTPClient;

class SendMailHandler extends BasicHandler
{
    private Message $message;

    private array $results = [];

    function __construct(Message $message, SMTPClient $client)
    {
        $this->message = $message;
        parent::__construct($client);
    }

    public function handle(SMTPConnection $connection): bool
    {
        $connection->write('MAIL FROM: ' . $this->message->getRFCFrom());
        if ($this->helper_readCode($connection) == 250) {
            // TODO: HANDLE MULTIPLE RCTPS
            $connection->write('RCPT TO: ' . $this->message->getRFCTo() . ' NOTIFY=success,failure');
            if ($this->helper_readCode($connection) == 250) {
                $connection->write('DATA');
                if ($this->helper_readCode($connection) == 354) {
                    // Normalise line endings and dot-stuff lines starting with "." (RFC 5321 4.5.2)
                    $body = preg_replace("/\r\n|\r|\n/", "\r\n", $this->message->getRFCBody());
                    $body = preg_replace('/^\./m', '..', $body);
                    if (str_ends_with($body, "\r\n")) {
                        $body = substr($body, 0, -2); // write() appends the final CRLF
                    }
                    if ($body !== '') {
                        $connection->write($body);
                    }
                    $connection->write('.');
                    if ($this->helper_readCode($connection) == 250) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function getResults(): ?array
    {
        return $this->results;
    }

    public static function capability(): ?string
    {
        return null;
    }
}
