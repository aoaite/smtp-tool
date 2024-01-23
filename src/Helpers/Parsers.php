<?php

namespace Aoaite\SmtpTool\Helpers;

trait Parsers
{
    protected function helper_parseResponseCode(String $data): ?int
    {
        return intval(substr($data, 0, 3));
    }

    protected function helper_parseList(String $data, bool $strip_status_codes = true): array
    {
        $result = [];
        $token = strtok($data, $this->client->getConnection()->getSeparators());
        while ($token !== false) {
            if (strlen($token) > 4) {
                $result[] = $strip_status_codes ? substr($token, 4) : $token;
            }
            $token = strtok($this->client->getConnection()->getSeparators());
        }
        return $result;
    }

    protected function helper_codeOk(int $code): bool
    {
        return $code >= 200 && $code < 260;
    }
}
