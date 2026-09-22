<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Models\Setting;

/**
 * Critical alerts to a telephone, through whatever HTTP API sends them.
 *
 * Email is where an alert goes to be read tomorrow. A relay that has stopped
 * responding, a backup that failed, an update that rolled back — those need to
 * reach somebody now, and the thing every one of my customers and I already
 * have open is WhatsApp.
 *
 * The request is configured rather than coded, deliberately. The sending
 * service is an account, not a standard — it has its own field names, its own
 * idea of what a token looks like and its own URL — and a class that guessed
 * at those would be a class that works on exactly one day. So the endpoint,
 * the method, the headers and the body template are all settings, with two
 * placeholders substituted into them:
 *
 *   {{to}}   one recipient, as configured
 *   {{text}} the message, already flattened to one line
 *
 * Both are escaped for the body's content type before they go in, so a message
 * containing a quotation mark cannot break the JSON around it.
 *
 * The token is encrypted at rest and never logged: what is recorded is the
 * endpoint's host, the recipient count and the status code, which is enough to
 * tell a misconfiguration from an outage and carries no credential.
 */
final class WhatsAppAlerts
{
    private const PREFIX = 'alerts.whatsapp.';

    /** Only these levels are worth a telephone. */
    private const LEVELS = ['critical'];

    public static function enabled(): bool
    {
        return (string) (Setting::get(self::PREFIX . 'enabled', null) ?? '') === '1'
            && self::endpoint() !== ''
            && self::recipients() !== [];
    }

    public static function endpoint(): string
    {
        return trim((string) (Setting::get(self::PREFIX . 'endpoint', null) ?? ''));
    }

    /** @return list<string> */
    public static function recipients(): array
    {
        $raw = (string) (Setting::get(self::PREFIX . 'recipients', null) ?? '');

        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $entry) {
            $entry = trim($entry);
            // Digits and a leading plus. Anything else is a typing mistake,
            // and sending it would be a request to a stranger's number.
            if ($entry !== '' && preg_match('/^\+?[0-9]{6,20}$/', $entry) === 1) {
                $out[] = $entry;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Send one alert, if this is configured and the level warrants it.
     *
     * Never throws. An alerting channel that can break the thing raising the
     * alert is worse than no alerting channel: the caller is usually a
     * failure path already.
     */
    public static function send(string $level, string $title, string $body = ''): bool
    {
        if (!in_array($level, self::LEVELS, true) || !self::enabled()) {
            return false;
        }

        $text = self::flatten($title, $body);
        $sent = 0;

        foreach (self::recipients() as $to) {
            if (self::deliver($to, $text)) {
                $sent++;
            }
        }

        return $sent > 0;
    }

    /**
     * One line, bounded.
     *
     * A WhatsApp message is read on a telephone, standing up, by somebody who
     * is about to do something about it. The stack trace belongs in the log.
     */
    private static function flatten(string $title, string $body): string
    {
        $text = trim($title);
        $first = trim((string) strtok(trim($body), "\n"));

        if ($first !== '') {
            $text .= ' — ' . $first;
        }

        $text = (string) preg_replace('/\s+/', ' ', $text);

        return mb_substr($text, 0, 600);
    }

    private static function deliver(string $to, string $text): bool
    {
        $endpoint = self::endpoint();
        $method = strtoupper((string) (Setting::get(self::PREFIX . 'method', null) ?? 'POST'));
        $template = (string) (Setting::get(self::PREFIX . 'body', null) ?? '');
        $contentType = (string) (Setting::get(self::PREFIX . 'content_type', null) ?? 'application/json');

        if ($template === '') {
            return false;
        }

        $body = self::fill($template, $to, $text, $contentType);
        $url = self::fill($endpoint, $to, $text, 'url');

        $headers = ['Content-Type: ' . $contentType];
        foreach (self::headerLines() as $line) {
            $headers[] = $line;
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return false;
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => $method === 'GET' ? 'GET' : $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $method === 'GET' ? null : $body,
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        $ok = $response !== false && $status >= 200 && $status < 300;

        // Host, not URL: a URL can carry a token in its query string, and this
        // line is written on every failure.
        Logger::info('app', $ok ? 'WhatsApp alert sent' : 'WhatsApp alert failed', [
            'host'   => (string) (parse_url($url, PHP_URL_HOST) ?: 'unknown'),
            'status' => $status,
            'error'  => $ok ? '' : substr($error, 0, 120),
        ]);

        return $ok;
    }

    /**
     * The extra headers, one per line, as an operator types them.
     *
     * The token lives here — "Authorization: Bearer ..." — which is why the
     * whole block is stored encrypted and never returned to a page or a log.
     *
     * @return list<string>
     */
    private static function headerLines(): array
    {
        $raw = (string) (Setting::get(self::PREFIX . 'headers', null) ?? '');

        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            // A header with no colon is not a header, and a line break smuggled
            // into one is a request-splitting attempt.
            if ($line !== '' && str_contains($line, ':')) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * Substitute the two placeholders, escaped for where they are going.
     *
     * A message saying a backup failed with "can't write" would otherwise end
     * a JSON string early and produce a request the API rejects — on the one
     * day anybody needs it.
     */
    private static function fill(string $template, string $to, string $text, string $contentType): string
    {
        if (str_contains($contentType, 'json')) {
            // substr, not trim. json_encode('he said "x"') ends in \" — a
            // backslash and a quote — and trim($s, '"') strips that quote,
            // leaving a dangling backslash that escapes the template's own
            // closing quote. The result parsed as JSON right up until a
            // message contained a quotation mark, which is what an error
            // message does on the one day this matters. The test for it is in
            // tests/UnitTests.php and it caught exactly this.
            $escapedTo = self::insideQuotes($to);
            $escapedText = self::insideQuotes($text);
        } elseif ($contentType === 'url' || str_contains($contentType, 'form-urlencoded')) {
            $escapedTo = rawurlencode($to);
            $escapedText = rawurlencode($text);
        } else {
            $escapedTo = $to;
            $escapedText = $text;
        }

        return str_replace(['{{to}}', '{{text}}'], [$escapedTo, $escapedText], $template);
    }

    /**
     * A string JSON-encoded, with the surrounding quotation marks removed and
     * nothing else touched, for dropping inside a quoted template value.
     */
    private static function insideQuotes(string $value): string
    {
        $encoded = (string) json_encode($value, JSON_UNESCAPED_SLASHES);

        // json_encode returns false only for invalid UTF-8; an unquoted result
        // is not something to paste into a request.
        if (strlen($encoded) < 2 || $encoded[0] !== '"') {
            return '';
        }

        return substr($encoded, 1, -1);
    }
}
