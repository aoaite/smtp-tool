<?php

namespace Aoaite\SmtpTool\Helpers;

/**
 * Runs stream functions while collecting the PHP warnings they emit (e.g. TLS handshake
 * errors such as "certificate verify failed"), so they can be reported in an exception
 * instead of leaking out as warnings.
 */
final class PhpErrors
{
    /**
     * @param String[] $messages receives the collected warning messages
     */
    public static function capture(callable $callback, ?array &$messages): mixed
    {
        $messages = [];
        set_error_handler(function (int $level, String $message) use (&$messages) {
            // Strip the "function(): " prefix PHP adds
            $messages[] = preg_replace('/^[a-z_]+\(\): /', '', $message);
            return true;
        });
        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
