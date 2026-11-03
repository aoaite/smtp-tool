<?php

namespace Aoaite\SmtpTool\Handlers;

use Aoaite\SmtpTool\Connection\SMTPConnection;
use Aoaite\SmtpTool\Mail\Message;
use Aoaite\SmtpTool\Mail\RawMessage;
use Aoaite\SmtpTool\Clients\SMTPClient;

/**
 * Runs one mail transaction: MAIL FROM, RCPT TO (per recipient), then DATA or BDAT (RFC 3030).
 *
 * The transaction proceeds as long as at least one recipient was accepted.
 * If it is aborted before the content is accepted, RSET is sent so the connection can be reused.
 */
class SendMailHandler extends BasicHandler
{
    private Message $message;
    private array $dsnNotify;
    private ?int $bdatChunkSize;

    private array $results = [];
    private array $acceptedRecipients = [];
    private array $rejectedRecipients = [];

    /**
     * @param String[] $dsnNotify DSN NOTIFY values (RFC 3461), e.g. ['SUCCESS', 'FAILURE'].
     *                           Empty means no NOTIFY parameter is sent.
     * @param int|null $bdatChunkSize null sends the content with DATA. Otherwise BDAT is used,
     *                           with chunks of at most this many bytes (0: a single chunk).
     */
    function __construct(Message $message, SMTPClient $client, array $dsnNotify = [], ?int $bdatChunkSize = null)
    {
        if ($bdatChunkSize !== null && $bdatChunkSize < 0) {
            throw new \InvalidArgumentException("BDAT chunk size must not be negative");
        }
        $this->message = $message;
        $this->dsnNotify = $dsnNotify;
        $this->bdatChunkSize = $bdatChunkSize;
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

        return $this->bdatChunkSize === null ? $this->sendData($connection) : $this->sendBdat($connection);
    }

    private function sendData(SMTPConnection $connection): bool
    {
        if ($this->command($connection, 'DATA') != 354) {
            $this->reset($connection);
            return false;
        }

        $data = $this->message->getContent();
        if (!$this->message instanceof RawMessage) {
            // Dot-stuffing (RFC 5321 4.5.2): lines starting with "." get an extra "."
            $data = preg_replace('/^\./m', '..', $data);
        }
        if ($data !== '' && !str_ends_with($data, "\r\n")) {
            // The end-of-data marker is "<CRLF>.<CRLF>", so the "." must start a new line
            $data .= "\r\n";
        }
        if ($data !== '') {
            $connection->writeRaw($data);
        }
        return $this->command($connection, '.') == 250;
    }

    /**
     * BDAT sends the content in chunks of a declared size, so "." has no special meaning
     * and no dot-stuffing is done.
     */
    private function sendBdat(SMTPConnection $connection): bool
    {
        $content = $this->message->getContent();
        $chunks = $this->bdatChunkSize > 0 && $content !== '' ? str_split($content, $this->bdatChunkSize) : [$content];
        $last = count($chunks) - 1;
        foreach ($chunks as $i => $chunk) {
            $command = 'BDAT ' . strlen($chunk) . ($i === $last ? ' LAST' : '');
            if ($this->command($connection, $command, $chunk) != 250) {
                // After a failed BDAT the server may already have discarded the transaction;
                // RSET makes sure of it
                $this->reset($connection);
                return false;
            }
        }
        return true;
    }

    /**
     * Sends a command (followed by $payload, for BDAT) and records the reply.
     */
    private function command(SMTPConnection $connection, String $command, ?String $payload = null): int
    {
        if ($payload === null) {
            $connection->write($command);
        } else {
            $connection->writeRaw($command . "\r\n" . $payload);
        }
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
