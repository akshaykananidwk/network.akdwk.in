<?php

declare(strict_types=1);

namespace App\Core;

/**
 * SMTP client with no external dependency.
 *
 * Composer is off the table, and mail() on a VPS lands in spam, so this speaks
 * SMTP directly: EHLO, optional STARTTLS, AUTH LOGIN/PLAIN, MAIL FROM, DATA.
 * Enough for transactional mail (alerts, resets, invites) and nothing more.
 */
final class Mailer
{
    private const CRLF = "\r\n";

    /** @var resource|null */
    private $socket = null;
    private string $lastResponse = '';
    /** @var list<string> */
    private array $transcript = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $security = 'tls',
        private readonly int $timeout = 15,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) (Config::get('mail.host') ?? Config::env('MAIL_HOST', '')),
            (int) (Config::get('mail.port') ?? Config::env('MAIL_PORT', 587)),
            (string) (Config::get('mail.user') ?? Config::env('MAIL_USER', '')),
            (string) (Config::get('mail.pass') ?? Config::env('MAIL_PASS', '')),
            (string) (Config::get('mail.security') ?? Config::env('MAIL_SECURE', 'tls')),
            (int) Config::get('mail.timeout', 15),
        );
    }

    /**
     * Send one message.
     *
     * @param array<string,string> $headers extra headers
     * @return array{success:bool,error:string,transcript:list<string>}
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = '', array $headers = []): array
    {
        $this->transcript = [];

        if ($this->host === '') {
            return ['success' => false, 'error' => 'SMTP host is not configured.', 'transcript' => []];
        }

        $fromEmail = (string) (Config::get('mail.from') ?? Config::env('MAIL_FROM', 'no-reply@localhost'));
        $fromName = (string) Config::get('mail.from_name', (string) Config::get('brand.name', 'Panel'));

        try {
            $this->connect();
            $this->command('EHLO ' . $this->heloName(), [250]);

            if ($this->security === 'tls') {
                $this->command('STARTTLS', [220]);
                $this->enableCrypto();
                $this->command('EHLO ' . $this->heloName(), [250]);
            }

            if ($this->username !== '') {
                $this->authenticate();
            }

            $this->command('MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->command('RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command('DATA', [354]);

            $this->write($this->buildMessage($fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody, $textBody, $headers));
            $this->command('.', [250]);
            $this->command('QUIT', [221, 250]);
            $this->disconnect();

            Logger::info('app', 'Email sent', ['to' => $toEmail, 'subject' => $subject]);

            return ['success' => true, 'error' => '', 'transcript' => $this->transcript];
        } catch (\Throwable $e) {
            $this->disconnect();
            // The transcript can carry the AUTH line; redact before it is stored
            // or shown in the installer's "send test email" result.
            $error = Logger::redactString($e->getMessage());
            Logger::error('app', 'Email send failed', ['to' => $toEmail, 'error' => $error]);

            return ['success' => false, 'error' => $error, 'transcript' => $this->transcript];
        }
    }

    /** @return array{success:bool,error:string,transcript:list<string>} */
    public function sendTest(string $toEmail): array
    {
        $brand = (string) Config::get('brand.name', 'Panel');

        return $this->send(
            $toEmail,
            '',
            $brand . ' — SMTP test',
            '<p>This is a test message from <strong>' . htmlspecialchars($brand, ENT_QUOTES) . '</strong>.</p>'
            . '<p>If you are reading this, outgoing mail is configured correctly.</p>',
            "This is a test message from {$brand}. If you are reading this, outgoing mail is configured correctly."
        );
    }

    // ------------------------------------------------------------- internals

    private function connect(): void
    {
        $transport = $this->security === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => (bool) Config::get('mail.verify_peer', true),
                'verify_peer_name'  => (bool) Config::get('mail.verify_peer', true),
                'allow_self_signed' => !(bool) Config::get('mail.verify_peer', true),
            ],
        ]);

        $socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new AppException(sprintf('Cannot connect to SMTP %s:%d — %s (%d)', $this->host, $this->port, $errstr, $errno));
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);
        $this->expect([220]);
    }

    private function enableCrypto(): void
    {
        if ($this->socket === null) {
            throw new AppException('Not connected.');
        }
        $ok = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($ok !== true) {
            throw new AppException('STARTTLS negotiation failed.');
        }
    }

    private function authenticate(): void
    {
        // Prefer AUTH LOGIN (most widely supported), fall back to PLAIN.
        try {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334]);
            $this->command(base64_encode($this->password), [235]);
        } catch (AppException) {
            $this->command(
                'AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password),
                [235]
            );
        }
    }

    /** @param list<int> $expected */
    private function command(string $command, array $expected): void
    {
        $this->write($command . self::CRLF);
        // Never let a credential reach the transcript.
        $this->transcript[] = '> ' . (preg_match('/^(AUTH|[A-Za-z0-9+\/=]{16,}$)/', $command) === 1 ? '[redacted]' : $command);
        $this->expect($expected);
    }

    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw new AppException('Not connected to SMTP server.');
        }
        if (@fwrite($this->socket, $data) === false) {
            throw new AppException('Failed writing to SMTP socket.');
        }
    }

    /** @param list<int> $expected */
    private function expect(array $expected): void
    {
        $response = $this->readResponse();
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new AppException('SMTP error: ' . trim($response));
        }
    }

    private function readResponse(): string
    {
        if ($this->socket === null) {
            throw new AppException('Not connected to SMTP server.');
        }
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            // Multi-line replies use "250-"; the final line uses "250 ".
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $this->lastResponse = $response;
        $this->transcript[] = '< ' . trim($response);

        return $response;
    }

    private function disconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function heloName(): string
    {
        $domain = (string) Config::get('app.domain', '');

        return $domain !== '' ? $domain : 'localhost';
    }

    /** @param array<string,string> $extraHeaders */
    private function buildMessage(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $extraHeaders
    ): string {
        $boundary = 'b' . bin2hex(random_bytes(12));
        if ($textBody === '') {
            $textBody = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody) ?? $htmlBody)));
        }

        $headers = [
            'Date'         => gmdate('r'),
            'From'         => $this->formatAddress($fromEmail, $fromName),
            'To'           => $this->formatAddress($toEmail, $toName),
            'Subject'      => $this->encodeHeader($subject),
            'Message-ID'   => '<' . bin2hex(random_bytes(12)) . '@' . $this->heloName() . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer'     => (string) Config::get('brand.name', 'Panel'),
        ];
        foreach ($extraHeaders as $name => $value) {
            $headers[$name] = $this->encodeHeader($value);
        }

        $message = '';
        foreach ($headers as $name => $value) {
            $message .= $name . ': ' . $value . self::CRLF;
        }
        $message .= self::CRLF;
        $message .= '--' . $boundary . self::CRLF;
        $message .= 'Content-Type: text/plain; charset=UTF-8' . self::CRLF;
        $message .= 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF;
        $message .= chunk_split(base64_encode($textBody), 76, self::CRLF);
        $message .= '--' . $boundary . self::CRLF;
        $message .= 'Content-Type: text/html; charset=UTF-8' . self::CRLF;
        $message .= 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF;
        $message .= chunk_split(base64_encode($htmlBody), 76, self::CRLF);
        $message .= '--' . $boundary . '--' . self::CRLF;

        // Dot-stuffing: a line that is just "." would terminate DATA early.
        return preg_replace('/^\./m', '..', $message) ?? $message;
    }

    private function formatAddress(string $email, string $name): string
    {
        return $name === '' ? $email : $this->encodeHeader($name) . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value); // header injection guard
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
