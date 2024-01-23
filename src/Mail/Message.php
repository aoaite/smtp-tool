<?php

namespace Aoaite\SmtpTool\Mail;

interface Message
{
    public function getRFCFrom(): String;

    public function getRFCTo(): String;

    public function getRFCBody(): String;
}
