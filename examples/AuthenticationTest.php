<?php

namespace Examples;

use Aoaite\SmtpTool\Auth\AuthLogin;
use Aoaite\SmtpTool\Auth\AuthMechanism;
use Aoaite\SmtpTool\Auth\AuthPlain;
use Aoaite\SmtpTool\Clients\BasicSMTPClient;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Is authentication offered where it should be (and only there), and does it work?
 */
class AuthenticationTest extends SmtpServerTestCase
{
    public function test_no_auth_over_plaintext(): void
    {
        $this->connectSmtp();
        $this->assertSame([], $this->client->getServerLoginCapabilities(), 'AUTH offered without TLS');

        // Even if a client tries anyway, the password must not be accepted in plaintext
        $credentials = base64_encode("\0" . $this->env('SMTP_USERNAME') . "\0" . $this->env('SMTP_PASSWORD'));
        $this->assertRefused($this->client->sendCommand('AUTH PLAIN ' . $credentials));
    }

    public function test_login_offered_over_tls(): void
    {
        $this->connectSubmission();
        $this->assertNotEmpty(
            array_intersect(['PLAIN', 'LOGIN'], $this->client->getServerLoginCapabilities()),
            'Neither AUTH PLAIN nor AUTH LOGIN offered after STARTTLS'
        );
    }

    public static function mechanisms(): array
    {
        return [
            'PLAIN' => [fn (String $user, String $password, BasicSMTPClient $client) => new AuthPlain($user, $password, $client)],
            'LOGIN' => [fn (String $user, String $password, BasicSMTPClient $client) => new AuthLogin($user, $password, $client)],
        ];
    }

    /**
     * @param callable(String, String, BasicSMTPClient): AuthMechanism $mechanism
     */
    #[DataProvider('mechanisms')]
    public function test_login_succeeds(callable $mechanism): void
    {
        $this->connectSubmission();
        $auth = $mechanism($this->env('SMTP_USERNAME'), $this->env('SMTP_PASSWORD'), $this->client);
        $this->assertTrue($this->client->authenticate($auth), $this->transcript());
    }

    #[DataProvider('mechanisms')]
    public function test_wrong_password_is_rejected(callable $mechanism): void
    {
        $this->connectSubmission();
        $auth = $mechanism($this->env('SMTP_USERNAME'), 'definitely-wrong', $this->client);
        // false means the server answered 535 (authentication credentials invalid)
        $this->assertFalse($this->client->authenticate($auth), $this->transcript());
    }

    public function test_unknown_mechanism_is_rejected(): void
    {
        $this->connectSubmission();
        $this->assertRefused($this->client->sendCommand('AUTH NOT-A-MECHANISM'));
    }

    public function test_submission_requires_login(): void
    {
        $this->connectSubmission();
        $this->assertReplyCode(250, $this->client->sendCommand('MAIL FROM:<' . $this->env('SMTP_EXISTING_EMAIL') . '>'));
        $this->assertRefused($this->client->sendCommand('RCPT TO:<' . $this->env('SMTP_EXISTING_EMAIL') . '>'));
    }

    public function test_logged_in_user_may_relay(): void
    {
        $this->connectSubmission();
        $this->login();
        $this->assertReplyCode(250, $this->client->sendCommand('MAIL FROM:<' . $this->env('SMTP_EXISTING_EMAIL') . '>'));
        $this->assertReplyCode(250, $this->client->sendCommand('RCPT TO:<' . $this->env('SMTP_RELAY_RECIPIENT') . '>'));
        // Nothing is actually sent
        $this->client->sendCommand('RSET');
    }
}
