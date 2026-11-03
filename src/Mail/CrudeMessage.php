<?php

namespace Aoaite\SmtpTool\Mail;

/**
 * A message sent exactly as given, for testing how a server deals with odd input.
 *
 * - $from is sent verbatim after "MAIL FROM:" (include the angle brackets yourself, e.g. "<a@example.com>")
 * - each $to is sent verbatim after "RCPT TO:"
 * - $data is sent verbatim after DATA: no line ending normalisation and no dot-stuffing,
 *   so bare LFs, lone "." lines and the like reach the server untouched.
 *   Only the terminating "<CRLF>.<CRLF>" is appended.
 */
class CrudeMessage implements Message
{
    private String $from;
    /** @var String[] */
    private array $to;
    private String $data;

    /**
     * @param String|String[] $to
     */
    function __construct(String $from, String|array $to, String $data)
    {
        $this->from = $from;
        $this->to = is_array($to) ? array_values($to) : [$to];
        $this->data = $data;
    }

    public function getEnvelopeFrom(): String
    {
        return $this->from;
    }

    public function getEnvelopeRecipients(): array
    {
        return $this->to;
    }

    public function getData(): String
    {
        return $this->data;
    }
}
