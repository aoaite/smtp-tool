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
     * The message (headers and body) with CRLF line endings, as it should arrive.
     * Dot-stuffing for DATA is done when sending, unless this is a RawMessage.
     */
    public function getContent(): String;
}
