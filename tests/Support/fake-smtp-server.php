<?php

/**
 * Scripted fake SMTP server used by the unit tests. Run by FakeSmtpServer, not directly.
 *
 * Usage: php fake-smtp-server.php <options.json> <transcript.jsonl>
 *
 * Listens on a random port on 127.0.0.1, prints the port to stdout, serves exactly one
 * client and records everything it receives to the transcript (one JSON object per line).
 */

$options = json_decode(file_get_contents($argv[1]), true) + [
    'greeting' => '220 fake.test ESMTP',
    'ehlo' => true,
    'capabilities' => ['PIPELINING', 'SIZE 10240000', 'STARTTLS', 'DSN', '8BITMIME'],
    'tlsCapabilities' => [],      // only advertised after STARTTLS / on implicit TLS
    'bareFinalLine' => false,     // end multi-line replies with a bare "250"
    'implicitTls' => false,
    'cert' => null,
    'key' => null,
    'users' => [],                // username => password
    'vrfy' => [],                 // mailbox => reply
    'rejectRecipients' => [],     // forward-paths that get a 550
];

$context = stream_context_create(['ssl' => [
    'local_cert' => $options['cert'],
    'local_pk' => $options['key'],
    'verify_peer' => false,
]]);
$server = stream_socket_server(
    ($options['implicitTls'] ? 'tls' : 'tcp') . '://127.0.0.1:0',
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if (!$server) {
    fwrite(STDERR, "Could not listen: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

$client = @stream_socket_accept($server, 15);
if (!$client) {
    exit(1);
}
$transcript = fopen($argv[2], 'a');
$record = function (string $type, string $value) use ($transcript) {
    fwrite($transcript, json_encode(['type' => $type, 'value' => $value]) . "\n");
    fflush($transcript);
};
$reply = fn (string $line) => fwrite($client, $line . "\r\n");
$multiline = function (array $lines) use ($reply, $options) {
    if ($options['bareFinalLine']) {
        $lines[] = '';
    }
    $last = count($lines) - 1;
    foreach ($lines as $i => $line) {
        $reply('250' . ($i === $last ? ($line === '' ? '' : ' ') : '-') . $line);
    }
};

$tls = (bool)$options['implicitTls'];
$authenticated = false;
$hasSender = false;
$recipients = 0;
$reply($options['greeting']);

while (($line = stream_get_line($client, 4096, "\r\n")) !== false) {
    $record('command', $line);
    $verb = strtoupper(strtok($line, ' :'));
    switch ($verb) {
        case 'EHLO':
            if (!$options['ehlo']) {
                $reply('502 5.5.2 Error: command not recognized');
                break;
            }
            $multiline(array_merge(['fake.test'], $options['capabilities'], $tls ? $options['tlsCapabilities'] : []));
            break;
        case 'HELO':
            $reply('250 fake.test');
            break;
        case 'STARTTLS':
            if ($tls || !in_array('STARTTLS', $options['capabilities'])) {
                $reply('502 5.5.1 Error: command not implemented');
                break;
            }
            $reply('220 2.0.0 Ready to start TLS');
            if (!stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                exit(1);
            }
            $tls = true;
            break;
        case 'AUTH':
            $parts = explode(' ', $line);
            $mechanism = strtoupper($parts[1] ?? '');
            if ($mechanism === 'PLAIN') {
                $reply('334 ');
                $response = stream_get_line($client, 4096, "\r\n");
                $record('auth', $response);
                [, $user, $pass] = array_pad(explode("\0", base64_decode($response)), 3, '');
            } elseif ($mechanism === 'LOGIN') {
                $reply('334 ' . base64_encode('Username:'));
                $user = base64_decode($u = stream_get_line($client, 4096, "\r\n"));
                $record('auth', $u);
                $reply('334 ' . base64_encode('Password:'));
                $pass = base64_decode($p = stream_get_line($client, 4096, "\r\n"));
                $record('auth', $p);
            } else {
                $reply('504 5.5.4 Unrecognized authentication type');
                break;
            }
            if (($options['users'][$user] ?? null) === $pass) {
                $authenticated = true;
                $reply('235 2.7.0 Authentication successful');
            } else {
                $reply('535 5.7.8 Error: authentication failed');
            }
            break;
        case 'VRFY':
            $mailbox = trim(substr($line, 5));
            $reply($options['vrfy'][$mailbox] ?? '550 5.1.1 <' . $mailbox . '>: Recipient address rejected');
            break;
        case 'MAIL':
            $hasSender = true;
            $recipients = 0;
            $reply('250 2.1.0 Ok');
            break;
        case 'RCPT':
            if (!$hasSender) {
                $reply('503 5.5.1 Error: need MAIL command');
                break;
            }
            $path = strtok(substr($line, strlen('RCPT TO:')), ' ');
            if (in_array($path, $options['rejectRecipients'])) {
                $reply('550 5.1.1 ' . $path . ': Recipient address rejected');
            } else {
                $recipients++;
                $reply('250 2.1.5 Ok');
            }
            break;
        case 'DATA':
            if (!$recipients) {
                $reply('554 5.5.1 Error: no valid recipients');
                break;
            }
            $reply('354 End data with <CR><LF>.<CR><LF>');
            // Read raw bytes until the end-of-data marker, so bare LFs etc. are preserved
            $data = '';
            while (!str_ends_with("\r\n" . $data, "\r\n.\r\n")) {
                $chunk = fread($client, 1);
                if ($chunk === false || $chunk === '') {
                    exit(1);
                }
                $data .= $chunk;
            }
            $record('data', substr($data, 0, -3));
            $hasSender = false;
            $reply('250 2.0.0 Ok: queued as FAKE');
            break;
        case 'RSET':
            $hasSender = false;
            $recipients = 0;
            $reply('250 2.0.0 Ok');
            break;
        case 'NOOP':
            $reply('250 2.0.0 Ok');
            break;
        case 'QUIT':
            $reply('221 2.0.0 Bye');
            fclose($client);
            exit(0);
        default:
            $reply('502 5.5.2 Error: command not recognized');
    }
}
