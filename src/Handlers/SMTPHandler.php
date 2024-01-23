<?php

namespace Aoaite\SmtpTool\Handlers;

use Aoaite\SmtpTool\Connection\SMTPConnection;

interface SMTPHandler
{
    public function handle(SMTPConnection $connection): bool;

    public static function capability(): ?String;
}
