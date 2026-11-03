<?php

namespace Aoaite\SmtpTool\Mail;

interface Message
{
    /**
     * Reverse-path sent after "MAIL FROM:", e.g. "<john@example.com>" (or "<>" for a null sender).
     */
    public function getEnvelopeFrom(): String;

    /**
     * Forward-paths, each sent after "RCPT TO:", e.g. ["<jane@example.com>"].
     *
     * @return String[]
     */
    public function getEnvelopeRecipients(): array;

    /**
     * Content sent after DATA, exactly as it should go over the wire (CRLF line
     * endings, dot-stuffed), without the terminating "." line.
     */
    public function getData(): String;
}
