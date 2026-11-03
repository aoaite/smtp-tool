<?php

namespace Tests\Support;

/**
 * Starts tests/Support/fake-smtp-server.php in a separate process and gives access to
 * everything the client sent. See that script for the available options.
 */
final class FakeSmtpServer
{
    private $process;
    private array $pipes = [];
    private String $optionsFile;
    private String $transcriptFile;
    private int $port;

    private function __construct(array $options)
    {
        $this->optionsFile = tempnam(sys_get_temp_dir(), 'smtp-opt');
        $this->transcriptFile = tempnam(sys_get_temp_dir(), 'smtp-log');
        file_put_contents($this->optionsFile, json_encode((object)$options));

        $this->process = proc_open(
            [PHP_BINARY, __DIR__ . '/fake-smtp-server.php', $this->optionsFile, $this->transcriptFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes
        );
        $port = fgets($this->pipes[1]);
        if (!$port) {
            throw new \RuntimeException('Fake SMTP server did not start: ' . stream_get_contents($this->pipes[2]));
        }
        $this->port = (int)$port;
    }

    public static function start(array $options = []): self
    {
        return new self($options);
    }

    /**
     * Same as start(), with a self-signed certificate for STARTTLS / implicit TLS.
     */
    public static function startWithTls(array $options = []): self
    {
        [$cert, $key] = self::certificate();
        return new self($options + ['cert' => $cert, 'key' => $key]);
    }

    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return array<array{type: string, value: string}>
     */
    public function getTranscript(): array
    {
        $lines = file($this->transcriptFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_map(fn ($line) => json_decode($line, true), $lines);
    }

    /**
     * @return String[] the command lines received (not including AUTH responses or DATA)
     */
    public function getCommands(): array
    {
        return $this->valuesOfType('command');
    }

    /**
     * @return String[] the raw DATA payloads received, without the terminating "<CRLF>.<CRLF>"
     */
    public function getData(): array
    {
        return $this->valuesOfType('data');
    }

    /**
     * @return String[] the (base64) lines sent in response to AUTH challenges
     */
    public function getAuthResponses(): array
    {
        return $this->valuesOfType('auth');
    }

    private function valuesOfType(String $type): array
    {
        return array_values(array_map(
            fn ($entry) => $entry['value'],
            array_filter($this->getTranscript(), fn ($entry) => $entry['type'] === $type)
        ));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->process);
        }
        @unlink($this->optionsFile);
        @unlink($this->transcriptFile);
    }

    public function __destruct()
    {
        $this->stop();
    }

    private static function certificate(): array
    {
        static $paths = null;
        if ($paths === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $csr = openssl_csr_new(['commonName' => 'fake.test'], $key, ['digest_alg' => 'sha256']);
            $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
            $paths = [tempnam(sys_get_temp_dir(), 'smtp-crt'), tempnam(sys_get_temp_dir(), 'smtp-key')];
            openssl_x509_export_to_file($cert, $paths[0]);
            openssl_pkey_export_to_file($key, $paths[1]);
            register_shutdown_function(function () use ($paths) {
                array_map('unlink', $paths);
            });
        }
        return $paths;
    }
}
