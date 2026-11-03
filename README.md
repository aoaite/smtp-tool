# smtp-tool

A small SMTP client library for **testing SMTP servers**: check that a server greets correctly,
offers STARTTLS, requires authentication, accepts or rejects mail, and how it reacts to
malformed input. It is not meant as a general purpose mailer.

Requires PHP 8.1+ with the openssl extension.

```bash
composer require --dev aoaite/smtp-tool:dev-main
```

## Usage

```php
use Aoaite\SmtpTool\Auth\AuthPlain;
use Aoaite\SmtpTool\Clients\BasicSMTPClient;
use Aoaite\SmtpTool\Connection\SMTPConnectionContext;
use Aoaite\SmtpTool\Connection\SMTPEncryption;

$client = new BasicSMTPClient();

// NONE, SSL / TLS (implicit TLS, e.g. port 465) or STARTTLS (e.g. port 587)
$context = new SMTPConnectionContext(SMTPEncryption::STARTTLS);
// For self-signed test servers:
// $context->setSSLVerifyPeer(false); $context->setSSLVerifyPeerName(false); $context->setSSLAllowSelfSigned(true);

$client->connect('mail.example.com', 587, $context); // false if no 220 greeting / STARTTLS failed

$client->getGreeting();               // "220 mail.example.com ESMTP\r\n"
$client->getServerCapabilities();     // ["PIPELINING", "SIZE 10240000", "AUTH PLAIN LOGIN", ...]
$client->hasCapability('DSN');        // true / false
$client->getServerLoginCapabilities();// ["PLAIN", "LOGIN"]
$client->getConnectionMeta()->getEncryptionProtocol(); // "TLSv1.3"

$client->authenticate(new AuthPlain('user', 'secret', $client)); // true, false (535) or null (not advertised / other reply)
$client->verify('someone@example.com', $result);                 // VRFY

$client->disconnect();

// Every line sent (<), received (>) and logged (=), with milliseconds since connect
print_r($client->getLogger()->getLogs());
```

### Sending mail

```php
use Aoaite\SmtpTool\Mail\BasicMessage;
use Aoaite\SmtpTool\Mail\Contact;

$message = new BasicMessage(
    new Contact('sender@example.com', 'Sender'),
    [new Contact('one@example.com'), new Contact('two@example.com', 'Two')],
    "Hello!\nThis is a test.",
    'Test subject',
    ['X-Test' => 'yes'] // optional extra headers
);

// Optional DSN NOTIFY (only sent if the server advertises DSN)
$sent = $client->sendMail($message, ['SUCCESS', 'FAILURE'], $results);

// $results holds every command with the server's reply:
// [['command' => 'MAIL FROM:<sender@example.com>', 'code' => 250, 'response' => "250 2.1.0 Ok\r\n"], ...]
```

`sendMail()` keeps going if only some recipients are rejected, and sends `RSET` if the
transaction is aborted before `DATA`, so the connection can be reused.

### Sending odd input

Normal commands refuse CR/LF characters, so a value can't inject extra commands by accident.
To send malformed input on purpose:

```php
use Aoaite\SmtpTool\Mail\CrudeMessage;

// A single (validated) command line; returns an SMTPReply
$reply = $client->sendCommand('XFOO');
$reply->getCode();            // 500
$reply->getEnhancedCode();    // "5.5.2"
$reply->isPermanentFailure(); // true

// Raw bytes, no line ending appended; returns the first reply
$client->sendRaw("NOOP\r\nNOOP\r\n");
$client->readReply();         // the second (pipelined) reply

// Envelope and DATA sent exactly as given: no normalisation, no dot-stuffing.
// E.g. check that a server does not accept "<LF>.<LF>" as end of data (SMTP smuggling):
$client->sendMail(new CrudeMessage(
    '<sender@example.com>',
    '<rcpt@example.com>',
    "Subject: test\n\nbody\n.\nMAIL FROM:<spoofed@example.com>\r\n"
), [], $results);

// Change the line separator used for commands and replies (default "\r\n")
$client->getConnection()->setSeparators("\n");
```

## Example tests

[`examples/`](examples/) has ready-made PHPUnit tests for checking a server: greeting and TLS,
authentication (not offered in plaintext, wrong passwords rejected, submission requires login),
mail transactions (no open relay, unknown recipients rejected, null sender accepted) and malformed
input (out-of-sequence commands, SMTP smuggling). Point them at your server via `phpunit.xml`
and run:

```bash
composer test:examples
```

## Tests

```bash
composer test               # unit tests, no server needed (uses a scripted fake server)
```

The integration tests run against a real server. `docker-compose.yml` provides a Postfix test
server (self-signed certificate, user `username` / `password`, mailbox `existing@example.com`)
on 127.0.0.1 ports 2525 (smtp), 4650 (smtps) and 5870 (submission):

```bash
docker compose up -d --wait
```

```bash
composer test:integration
```

`SMTP_POSTCONF` in `docker-compose.yml` adds Postfix settings, e.g.
`smtpd_forbid_bare_newline = no` makes the test server vulnerable to SMTP smuggling so you can
watch the examples catch it.

To test your own server, copy `phpunit.xml.dist` to `phpunit.xml` (git-ignored) and change the
`SMTP_*` values, or set them as environment variables. Integration tests are skipped when the
server cannot be reached.

## License

MIT
