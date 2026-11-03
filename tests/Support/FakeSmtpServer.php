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
     * Same as start(), with a certificate for STARTTLS / implicit TLS. The certificate is issued
     * by a test CA (see caFile()) for "fake.test" and "*.wild.test".
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

    /**
     * @return String[] the values recorded with the given type, e.g. "pregreet"
     */
    public function getTranscriptOfType(String $type): array
    {
        return $this->valuesOfType($type);
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

    /**
     * The test CA that issued the fake server's certificate (PEM file).
     */
    public static function caFile(): String
    {
        return self::certificates()['ca'];
    }

    private static function certificate(): array
    {
        $files = self::certificates();
        return [$files['cert'], $files['key']];
    }

    private static function certificates(): array
    {
        static $files = null;
        if ($files === null) {
            $config = tempnam(sys_get_temp_dir(), 'smtp-cnf');
            file_put_contents($config, implode("\n", [
                '[req]',
                'distinguished_name = dn',
                '[dn]',
                '[ca]',
                'basicConstraints = critical,CA:TRUE',
                'keyUsage = critical,keyCertSign,cRLSign',
                'subjectKeyIdentifier = hash',
                '[leaf]',
                'basicConstraints = CA:FALSE',
                'keyUsage = critical,digitalSignature,keyEncipherment',
                'extendedKeyUsage = serverAuth',
                'subjectAltName = DNS:fake.test,DNS:*.wild.test',
                'authorityKeyIdentifier = keyid',
            ]) . "\n");
            $options = fn (String $section) => ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => $section];
            $newKey = fn () => openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

            $caKey = $newKey();
            $caCsr = openssl_csr_new(['commonName' => 'Fake Test CA', 'organizationName' => 'smtp-tool'], $caKey, $options('ca'));
            $ca = openssl_csr_sign($caCsr, null, $caKey, 2, $options('ca'), 1);

            $key = $newKey();
            $csr = openssl_csr_new(['commonName' => 'fake.test'], $key, $options('leaf'));
            $cert = openssl_csr_sign($csr, $ca, $caKey, 2, $options('leaf'), 2);

            $files = [
                'ca' => tempnam(sys_get_temp_dir(), 'smtp-ca'),
                'cert' => tempnam(sys_get_temp_dir(), 'smtp-crt'),
                'key' => tempnam(sys_get_temp_dir(), 'smtp-key'),
            ];
            openssl_x509_export_to_file($ca, $files['ca']);
            openssl_x509_export_to_file($cert, $files['cert']);
            openssl_pkey_export_to_file($key, $files['key']);
            unlink($config);
            register_shutdown_function(function () use ($files) {
                array_map('unlink', $files);
            });
        }
        return $files;
    }
}
