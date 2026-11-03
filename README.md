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
$client->getGreetingDomain();         // "mail.example.com" (from the banner)
$client->getEhloDomain();             // "mail.example.com" (from the EHLO reply)
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

`sendMailWithBdat($message, $chunkSize)` does the same with `BDAT` (RFC 3030 CHUNKING) instead
of `DATA`: the content is sent as-is in chunks of at most `$chunkSize` bytes (`0`: one chunk).

### Certificates

```php
$context = new SMTPConnectionContext(SMTPEncryption::STARTTLS); // verification is on by default
$context->setPeerName('mx.example.com');  // the name the certificate must be valid for
                                          // (default: the host you connect to, e.g. an IP)
$context->setCAFile('/path/to/ca.pem');   // optional: a private CA instead of the system CAs
$client->connect('203.0.113.5', 25, $context); // throws ConnectionException with the reason
                                               // ("certificate verify failed", name mismatch, ...)

$certificate = $client->getConnectionMeta()->getPeerCertificate();
$certificate->getSubjectCommonName();     // "mx.example.com"
$certificate->getDnsNames();              // ["mx.example.com", "*.mx.example.com"]
$certificate->getIssuerOrganization();    // "Let's Encrypt"
$certificate->getDaysUntilExpiry();       // 61
$certificate->matchesHostname($client->getEhloDomain()); // does the announced name match?
```

The certificate is also available with verification disabled, so a self-signed one can be
inspected too.

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

// Talk before the greeting (pregreet), e.g. to check postscreen catches it:
// open() only connects, nothing is read or sent
$client->open('mail.example.com', 25, new SMTPConnectionContext());
$client->sendRaw("EHLO early.example.com\r\n", false);
$client->readGreeting();      // postscreen leaves its "220-" greeting unfinished for early talkers
$client->readReply();         // the reply to the early EHLO
// ... greetServer(), startTls() and the other commands work as usual from here
```

## Example tests

[`examples/`](examples/) has ready-made PHPUnit tests for checking a server: greeting, TLS and
pregreet detection, the certificate (trusted, issued for the expected hostname, not expiring
soon, matching the announced hostname), authentication (not offered in plaintext, wrong passwords
rejected, submission requires login), mail transactions (no open relay, unknown recipients
rejected, null sender accepted) and malformed input (out-of-sequence commands, SMTP smuggling
with DATA and BDAT). Point them at your server via `phpunit.xml` (see
[`examples/README.md`](examples/README.md)) and run:

```bash
composer test:examples
```

## Tests

```bash
composer test               # unit tests, no server needed (uses a scripted fake server)
```

The integration tests run against a real server. `docker-compose.yml` provides a Postfix test
server (user `username` / `password`, mailbox `existing@example.com`, a certificate for
`mail.example.com` issued by the test-only CA in `docker/postfix/tls/ca.pem`) on 127.0.0.1
ports 2525 (smtp), 4650 (smtps), 5870 (submission) and 2526 (postscreen, rejects clients that
talk before the greeting):

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
