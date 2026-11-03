<?php

namespace Aoaite\SmtpTool\Handlers;

use Aoaite\SmtpTool\Connection\SMTPConnection;
use Aoaite\SmtpTool\Mail\Message;
use Aoaite\SmtpTool\Clients\SMTPClient;

/**
 * Runs one mail transaction: MAIL FROM, RCPT TO (per recipient), DATA.
 *
 * The transaction proceeds to DATA as long as at least one recipient was accepted.
 * If it is aborted before DATA, RSET is sent so the connection can be reused.
 */
class SendMailHandler extends BasicHandler
{
    private Message $message;
    private array $dsnNotify;

    private array $results = [];
    private array $acceptedRecipients = [];
    private array $rejectedRecipients = [];

    /**
     * @param String[] $dsnNotify DSN NOTIFY values (RFC 3461), e.g. ['SUCCESS', 'FAILURE'].
     *                           Empty means no NOTIFY parameter is sent.
     */
    function __construct(Message $message, SMTPClient $client, array $dsnNotify = [])
    {
        $this->message = $message;
        $this->dsnNotify = $dsnNotify;
        parent::__construct($client);
    }

    public function handle(SMTPConnection $connection): bool
    {
        if ($this->command($connection, 'MAIL FROM:' . $this->message->getEnvelopeFrom()) != 250) {
            return false;
        }

        $rcptParameters = $this->dsnNotify ? ' NOTIFY=' . strtoupper(implode(',', $this->dsnNotify)) : '';
        foreach ($this->message->getEnvelopeRecipients() as $recipient) {
            $code = $this->command($connection, 'RCPT TO:' . $recipient . $rcptParameters);
            if ($code == 250 || $code == 251) {
                $this->acceptedRecipients[] = $recipient;
            } else {
                $this->rejectedRecipients[] = $recipient;
            }
        }
        if (!$this->acceptedRecipients) {
            $this->client->getLogger()->log("No recipient was accepted, aborting transaction", 4);
            $this->reset($connection);
            return false;
        }

        if ($this->command($connection, 'DATA') != 354) {
            $this->reset($connection);
            return false;
        }

        $data = $this->message->getData();
        if ($data !== '' && !str_ends_with($data, "\r\n")) {
            // The end-of-data marker is "<CRLF>.<CRLF>", so the "." must start a new line
            $data .= "\r\n";
        }
        if ($data !== '') {
            $connection->writeRaw($data);
        }
        return $this->command($connection, '.') == 250;
    }

    private function command(SMTPConnection $connection, String $command): int
    {
        $connection->write($command);
        $response = $connection->read();
        $code = $this->helper_parseResponseCode($response);
        $this->results[] = ['command' => $command, 'code' => $code, 'response' => $response];
        return $code;
    }

    private function reset(SMTPConnection $connection): void
    {
        $this->command($connection, 'RSET');
    }

    /**
     * Every command sent with the server's reply, as ['command' => ..., 'code' => ..., 'response' => ...].
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getAcceptedRecipients(): array
    {
        return $this->acceptedRecipients;
    }

    public function getRejectedRecipients(): array
    {
        return $this->rejectedRecipients;
    }

    public static function capability(): ?string
    {
        return null;
    }
}
