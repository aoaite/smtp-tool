<?php

namespace Aoaite\SmtpTool\Mail;

class BasicMessage implements Message
{
    private Contact $from;
    private Contact $to;
    private String $body;

    function __construct(Contact $from, Contact $to, String $body)
    {
        $this->from = $from;
        $this->to = $to;
        $this->body = $body;
    }

    public function getFrom(): Contact
    {
        return $this->from;
    }

    public function getRFCFrom(): string
    {
        return $this->from->getRFC();
    }

    public function getTo(): Contact
    {
        return $this->to;
    }

    public function getRFCTo(): string
    {
        return $this->to->getRFC();
    }

    public function getRFCBody(): string
    {
        // TODO: Make that better
        return $this->body;
    }
}
