<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Response builder. Nothing is sent until send() is called, so middleware can
 * still add headers after a controller has returned.
 */
final class Response
{
    /** @var array<string,string> */
    private array $headers = [];
    private string $body = '';
    private int $status = 200;
    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    private array $cookies = [];

    public static function make(string $body = '', int $status = 200): self
    {
        $response = new self();
        $response->body = $body;
        $response->status = $status;

        return $response;
    }

    public static function html(string $html, int $status = 200): self
    {
        return self::make($html, $status)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public static function text(string $text, int $status = 200): self
    {
        return self::make($text, $status)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * Send a file from disk, without loading it into memory.
     *
     * A 14 MB installer read into a string works and is still the wrong shape:
     * the same code path serves every download, and the day one of them is a
     * backup archive it stops working on a host with a modest memory limit.
     * The body stays empty and send() streams the file instead.
     */
    public static function file(string $path, string $downloadName, string $contentType): self
    {
        $size = (int) @filesize($path);

        return self::make('', 200)
            ->header('Content-Type', $contentType)
            ->header('Content-Length', (string) $size)
            // A quoted basename, and only a basename: the name is ours, not
            // the caller's, so there is nothing here a header injection could
            // use.
            ->header('Content-Disposition', 'attachment; filename="' . basename($downloadName) . '"')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'public, max-age=300')
            ->streamFrom($path);
    }

    /**
     * Standard API envelope. Every /api/v1 response has this exact shape so
     * clients can branch on `success` alone.
     *
     * @param array<string,mixed> $meta
     */
    public static function api(mixed $data = null, array $meta = [], int $status = 200): self
    {
        return self::json([
            'success' => true,
            'data'    => $data,
            'meta'    => (object) $meta,
            'error'   => null,
        ], $status);
    }

    /** @param array<string,mixed> $details */
    public static function apiError(string $message, int $status = 400, string $code = '', array $details = []): self
    {
        return self::json([
            'success' => false,
            'data'    => null,
            'meta'    => (object) [],
            'error'   => [
                'code'    => $code !== '' ? $code : self::defaultCode($status),
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $status);
    }

    public static function json(mixed $payload, int $status = 200): self
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return self::make($encoded === false ? '{"success":false}' : $encoded, $status)
            ->header('Content-Type', 'application/json; charset=UTF-8');
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return self::make('', $status)->header('Location', $location);
    }

    public static function noContent(): self
    {
        return self::make('', 204);
    }

    /** Server-Sent Events frame writer helper (see StreamController). */
    public static function eventStream(): self
    {
        return self::make('', 200)
            ->header('Content-Type', 'text/event-stream')
            ->header('Cache-Control', 'no-cache, no-transform')
            ->header('X-Accel-Buffering', 'no')
            ->header('Connection', 'keep-alive');
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** @param array<string,mixed> $options */
    public function cookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return $this;
    }

    public function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** The file send() will stream instead of echoing a body. */
    private ?string $streamPath = null;

    private function streamFrom(string $path): self
    {
        $this->streamPath = $path;

        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], $cookie['options']);
            }
        }

        if ($this->streamPath !== null) {
            // Any output buffering is discarded first: a buffer that captures
            // fourteen megabytes defeats the point of streaming it.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $handle = fopen($this->streamPath, 'rb');
            if ($handle !== false) {
                while (!feof($handle)) {
                    $chunk = fread($handle, 262144);
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                    flush();
                }
                fclose($handle);
            }

            return;
        }

        echo $this->body;
    }

    private static function defaultCode(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            402 => 'limit_exceeded',
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'conflict',
            422 => 'validation_failed',
            429 => 'rate_limited',
            503 => 'maintenance',
            default => 'error',
        };
    }
}
