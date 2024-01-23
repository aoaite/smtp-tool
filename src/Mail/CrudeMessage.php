<?php

namespace Aoaite\SmtpTool\Mail;

class CrudeMessage implements Message
{
    private String $from;
    private String $to;
    private String $body;

    function __construct(String $from, String $to, String $body)
    {
        $this->from = $from;
        $this->to = $to;
        $this->body = $body;
    }

    public function getRFCFrom(): string
    {
        return $this->from;
    }

    public function getRFCTo(): string
    {
        return $this->to;
    }

    public function getRFCBody(): string
    {
        return $this->body;
    }
}
