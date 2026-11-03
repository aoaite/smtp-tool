<?php

namespace Aoaite\SmtpTool\Connection;

/**
 * A parsed (possibly multi-line) SMTP reply, e.g. "550 5.1.1 <a@example.com>: Recipient address rejected".
 */
final class SMTPReply
{
    private int $code;
    private ?String $enhancedCode;
    private array $lines;
    private String $raw;

    private function __construct(int $code, ?String $enhancedCode, array $lines, String $raw)
    {
        $this->code = $code;
        $this->enhancedCode = $enhancedCode;
        $this->lines = $lines;
        $this->raw = $raw;
    }

    public static function parse(?String $raw): self
    {
        $raw ??= '';
        $lines = [];
        $code = 0;
        foreach (preg_split('/\r\n|\n/', rtrim($raw, "\r\n")) as $i => $line) {
            if ($line === '' && $raw === '') {
                break;
            }
            if ($i === 0 && preg_match('/^\d{3}/', $line)) {
                $code = (int)substr($line, 0, 3);
            }
            $lines[] = preg_match('/^\d{3}[ -]?/', $line, $m) ? substr($line, strlen($m[0])) : $line;
        }
        // RFC 3463 enhanced status code, e.g. "5.1.1", at the start of the text
        $enhanced = isset($lines[0]) && preg_match('/^([245]\.\d{1,3}\.\d{1,3})(?:\s|$)/', $lines[0], $m) ? $m[1] : null;
        return new self($code, $enhanced, $lines, $raw);
    }

    /**
     * The three digit reply code, or 0 if nothing (or nothing parseable) was received.
     */
    public function getCode(): int
    {
        return $this->code;
    }

    /**
     * The enhanced status code (RFC 3463), e.g. "5.1.1", if the server sent one.
     */
    public function getEnhancedCode(): ?String
    {
        return $this->enhancedCode;
    }

    /**
     * The text of each line, without the reply code.
     *
     * @return String[]
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    public function getText(): String
    {
        return implode("\n", $this->lines);
    }

    /**
     * The reply exactly as received, including line endings.
     */
    public function getRaw(): String
    {
        return $this->raw;
    }

    /** 2xx */
    public function isPositiveCompletion(): bool
    {
        return $this->code >= 200 && $this->code < 300;
    }

    /** 3xx, e.g. 354 after DATA or 334 during AUTH */
    public function isPositiveIntermediate(): bool
    {
        return $this->code >= 300 && $this->code < 400;
    }

    /** 4xx: temporary failure, the client may retry later */
    public function isTransientFailure(): bool
    {
        return $this->code >= 400 && $this->code < 500;
    }

    /** 5xx: permanent failure */
    public function isPermanentFailure(): bool
    {
        return $this->code >= 500 && $this->code < 600;
    }

    /** 4xx or 5xx */
    public function isFailure(): bool
    {
        return $this->isTransientFailure() || $this->isPermanentFailure();
    }

    public function __toString(): String
    {
        return $this->raw;
    }
}
