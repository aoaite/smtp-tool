<?php

namespace Aoaite\SmtpTool\Mail;

class Contact
{
    private String $email;
    private String $name;

    function __construct(String $email, String $name = '')
    {
        $this->email = $email;
        $this->name = $name;
    }

    public function getEmail(): String
    {
        return $this->email;
    }

    public function getName(): String
    {
        return $this->name;
    }

    /**
     * Address as used in the SMTP envelope (MAIL FROM / RCPT TO), e.g. "<john@example.com>".
     */
    public function getEnvelope(): String
    {
        return '<' . $this->email . '>';
    }

    /**
     * Address as used in a message header (RFC 5322), e.g. "John Doe <john@example.com>".
     */
    public function getRFC(): String
    {
        if ($this->name === '') {
            return $this->email;
        }
        return self::encodeDisplayName($this->name) . ' <' . $this->email . '>';
    }

    private static function encodeDisplayName(String $name): String
    {
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            // Non-ASCII: RFC 2047 encoded word
            return '=?UTF-8?B?' . base64_encode($name) . '?=';
        }
        if (preg_match('/[()<>\[\]:;@\\\\,."]/', $name)) {
            // Contains specials: quoted string
            return '"' . addcslashes($name, '"\\') . '"';
        }
        return $name;
    }
}
