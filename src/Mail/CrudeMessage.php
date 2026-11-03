<?php

namespace Aoaite\SmtpTool\Mail;

/**
 * A message sent exactly as given, for testing how a server deals with odd input.
 *
 * - $from is sent verbatim after "MAIL FROM:" (include the angle brackets yourself, e.g. "<a@example.com>")
 *   so parameters can be added too: "<a@example.com> SIZE=1000 BODY=8BITMIME"
 * - each $to is sent verbatim after "RCPT TO:"
 * - $content is sent verbatim: no line ending normalisation and no dot-stuffing, so bare LFs,
 *   lone "." lines and the like reach the server untouched. With DATA only the terminating
 *   "<CRLF>.<CRLF>" is appended; with BDAT nothing is.
 */
class CrudeMessage implements RawMessage
{
    private String $from;
    /** @var String[] */
    private array $to;
    private String $content;

    /**
     * @param String|String[] $to
     */
    function __construct(String $from, String|array $to, String $content)
    {
        $this->from = $from;
        $this->to = is_array($to) ? array_values($to) : [$to];
        $this->content = $content;
    }

    public function getEnvelopeFrom(): String
    {
        return $this->from;
    }

    public function getEnvelopeRecipients(): array
    {
        return $this->to;
    }

    public function getContent(): String
    {
        return $this->content;
    }
}
