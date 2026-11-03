# Example server tests

PHPUnit tests that check an SMTP server's behaviour. They run against the Postfix test server
from `docker-compose.yml` by default and are meant to be copied and adapted for your own server.

| File | Checks |
| --- | --- |
| `ConnectionTest.php` | 220 greeting, ESMTP, STARTTLS offered and negotiating TLS 1.2+, implicit TLS on 465, submission requires STARTTLS, clients talking before the greeting are rejected (pregreet) |
| `CertificateTest.php` | on 25 (STARTTLS), 587 and 465: certificate trusted and valid for the expected hostname, not expiring within `SMTP_CERT_MIN_DAYS`, greeting and EHLO announce the expected hostname |
| `AuthenticationTest.php` | no AUTH in plaintext, PLAIN/LOGIN work over TLS, wrong passwords and unknown mechanisms rejected, submission requires login, logged-in users may relay |
| `MailTransactionTest.php` | mail for a local mailbox accepted, unknown recipients rejected (5.1.1), no open relay, null sender accepted, oversized `SIZE=` refused |
| `MalformedInputTest.php` | unknown and out-of-sequence commands rejected, malformed addresses rejected, not vulnerable to SMTP smuggling (6 DATA end-of-data variants, fake end of data in BDAT chunks), DATA after BDAT rejected |

`SmtpServerTestCase.php` holds the shared helpers (`connectSmtp()`, `connectSubmission()`,
`connectSmtps()`, `login()`, `assertRefused()`, ...). Failed assertions print the whole SMTP
conversation, so you can see what the server said.

## Running against your own server

Copy `phpunit.xml.dist` to `phpunit.xml` and set:

| Variable | Meaning |
| --- | --- |
| `SMTP_HOSTNAME` | server to test |
| `SMTP_PORT`, `SMTP_SUBMISSION_PORT`, `SMTP_SSL_PORT` | usually 25, 587, 465 |
| `SMTP_USERNAME`, `SMTP_PASSWORD` | a working login |
| `SMTP_EXISTING_EMAIL` | a mailbox the server accepts mail for (test mails are sent to it) |
| `SMTP_RELAY_RECIPIENT` | an external address the server must not relay to without login (nothing is sent to it) |
| `SMTP_PREGREET_PORT` | port with pregreet detection, usually the same as `SMTP_PORT` |
| `SMTP_VERIFY_TLS` | `true` to require a trusted certificate valid for `SMTP_EXPECTED_HOSTNAME` in all tests |
| `SMTP_EXPECTED_HOSTNAME` | the name the server should present (certificate, greeting, EHLO), e.g. its MX name |
| `SMTP_CA_FILE` | a CA (PEM) to trust instead of the system CAs; empty for public certificates |
| `SMTP_CERT_MIN_DAYS` | fail when the certificate expires within this many days (default 14) |

```bash
composer test:examples
```

Some checks are policy rather than RFC requirements (e.g. AUTH only after STARTTLS, rejecting
early talkers); drop the ones that don't match how your server is meant to behave. No DNS
lookups are done: whether the expected hostname is your MX record and resolves back to the
server is up to you.
