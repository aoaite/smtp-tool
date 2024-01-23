<?php

namespace Aoaite\SmtpTool\Helpers;

use Aoaite\SmtpTool\Connection\SMTPConnection;

trait SMTPHelpers
{
    protected function helper_readCode(SMTPConnection $connection)
    {
        $data = $connection->read();
        $code = $this->helper_parseResponseCode($data);
        return $code;
    }
}
