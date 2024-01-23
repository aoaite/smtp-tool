<?php

namespace Aoaite\SmtpTool\Auth;

use Aoaite\SmtpTool\Connection\SMTPConnection;
use Aoaite\SmtpTool\Clients\SMTPClient;

class AuthPlain extends AuthMechanism
{
    private $username;
    private $password;

    function __construct(String $username, String $password, SMTPClient $client)
    {
        $this->username = $username;
        $this->password = $password;
        parent::__construct($client);
    }

    public function handle(SMTPConnection $connection): ?bool
    {
        $connection->write("AUTH PLAIN");
        if ($this->helper_readCode($connection) == 334) {
            $connection->write(base64_encode("\0" . $this->username . "\0" . $this->password));
            $code = $this->helper_readCode($connection);
            if ($code == 235) {
                return true;
            } else if ($code == 535) {
                return false;
            }
        }
        return null;
    }

    public static function smtpAuthMechanism(): String
    {
        return "PLAIN";
    }
}
