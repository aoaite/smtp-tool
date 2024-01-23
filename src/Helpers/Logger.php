<?php

namespace Aoaite\SmtpTool\Helpers;

class Logger
{
    private array $logs = [];

    private float $timer = 0;

    private function getTime(): String
    {
        return number_format((microtime(true) - $this->timer) * (float)1000);
    }

    private function save(String $data, String $sign, int $level = 0)
    {
        $this->logs[] = [$this->getTime(), $sign, $data, $level];
    }

    public function logSent(String $data): void
    {
        $this->save($data, '<', 0);
    }

    public function logReceived(String $data): void
    {
        $this->save($data, '>', 0);
    }

    public function log(String $data, int $level = 0): void
    {
        $this->save($data, '=', $level);
    }

    public function getTimer(): float
    {
        return $this->timer;
    }

    public function setTimer(float $timer): void
    {
        $this->timer = $timer;
    }

    public function getLogs(): array
    {
        return $this->logs;
    }
}
