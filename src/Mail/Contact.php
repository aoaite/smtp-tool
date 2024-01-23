<?php

namespace Aoaite\SmtpTool\Mail;

class Contact
{
    private String $email;
    private String $name;

    function __construct(String $email, String $name)
    {
        $this->email = $email;
        $this->name = $name;
    }

    public function getEmail(): String
    {
        return $this->email;
    }

    public function getRFC(): String
    {
        return $this->name . '<' . $this->email . '>';
    }

    public function getName(): String
    {
        return $this->name;
    }
}
