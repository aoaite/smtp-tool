<?php

namespace Aoaite\SmtpTool\Mail;

/**
 * A simple, well-formed plain text message.
 *
 * The From, To, Date, Message-ID and MIME headers are generated; extra headers
 * can be passed as ['Header-Name' => 'value'] and override generated ones.
 */
class BasicMessage implements Message
{
    private Contact $from;
    /** @var Contact[] */
    private array $to;
    private String $body;
    private String $subject;
    private array $headers;

    /**
     * @param Contact|Contact[] $to
     */
    function __construct(Contact $from, Contact|array $to, String $body, String $subject = '', array $headers = [])
    {
        $this->from = $from;
        $this->to = is_array($to) ? array_values($to) : [$to];
        if (!$this->to) {
            throw new \InvalidArgumentException("A message needs at least one recipient");
        }
        $this->body = $body;
        $this->subject = $subject;
        $this->headers = $headers;
    }

    public function getFrom(): Contact
    {
        return $this->from;
    }

    /**
     * @return Contact[]
     */
    public function getTo(): array
    {
        return $this->to;
    }

    public function getSubject(): String
    {
        return $this->subject;
    }

    public function getBody(): String
    {
        return $this->body;
    }

    public function getRFCFrom(): String
    {
        return $this->from->getRFC();
    }

    public function getRFCTo(): String
    {
        return implode(', ', array_map(fn (Contact $c) => $c->getRFC(), $this->to));
    }

    public function getEnvelopeFrom(): String
    {
        return $this->from->getEnvelope();
    }

    public function getEnvelopeRecipients(): array
    {
        return array_map(fn (Contact $c) => $c->getEnvelope(), $this->to);
    }

    public function getHeaders(): array
    {
        $domain = substr(strrchr($this->from->getEmail(), '@') ?: '@localhost', 1);
        $headers = [
            'Date' => date(DATE_RFC2822),
            'From' => $this->getRFCFrom(),
            'To' => $this->getRFCTo(),
        ];
        if ($this->subject !== '') {
            $headers['Subject'] = preg_match('/[^\x20-\x7E]/', $this->subject)
                ? '=?UTF-8?B?' . base64_encode($this->subject) . '?='
                : $this->subject;
        }
        $headers += [
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
        ];
        // Custom headers replace generated ones (case-insensitively)
        foreach ($this->headers as $name => $value) {
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    unset($headers[$existing]);
                }
            }
            $headers[$name] = $value;
        }
        return $headers;
    }

    public function getData(): String
    {
        $data = '';
        foreach ($this->getHeaders() as $name => $value) {
            $data .= $name . ': ' . $value . "\r\n";
        }
        $data .= "\r\n" . $this->body;
        // Normalise line endings to CRLF and make sure the data ends with one
        $data = preg_replace("/\r\n|\r|\n/", "\r\n", $data);
        if (!str_ends_with($data, "\r\n")) {
            $data .= "\r\n";
        }
        // Dot-stuffing (RFC 5321 4.5.2): lines starting with "." get an extra "."
        return preg_replace('/^\./m', '..', $data);
    }
}
