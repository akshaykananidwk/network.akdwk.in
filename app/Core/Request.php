<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish view over the current HTTP request.
 *
 * Nothing here trusts client input: proxy headers are honoured only for hosts
 * explicitly listed in config, and all accessors return strings so callers must
 * cast deliberately.
 */
final class Request
{
    private static ?self $current = null;
    private static string $requestId = '';

    /** @param array<string,mixed> $query @param array<string,mixed> $post @param array<string,mixed> $server */
    private function __construct(
        private readonly array $query,
        private readonly array $post,
        private readonly array $server,
        private readonly array $cookies,
        private readonly array $files,
        private readonly string $rawBody,
    ) {
    }

    public static function capture(): self
    {
        $raw = '';
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($contentType, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
        }

        self::$current = new self($_GET, $_POST, $_SERVER, $_COOKIE, $_FILES, $raw);

        return self::$current;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * The authenticated device row, set by ApiKeyMiddleware.
     *
     * Kept as a typed property rather than a dynamic one: PHP 8.2 deprecates
     * dynamic properties, and an agent request's identity is important enough
     * to be declared.
     *
     * @var array<string,mixed>|null
     */
    private ?array $deviceContext = null;

    /** @param array<string,mixed> $device */
    public function setDeviceContext(array $device): void
    {
        $this->deviceContext = $device;
    }

    /** @return array<string,mixed>|null */
    public function deviceContext(): ?array
    {
        return $this->deviceContext;
    }

    /** Correlation id echoed into every log line and the X-Request-Id header. */
    public static function id(): string
    {
        if (self::$requestId === '') {
            self::$requestId = bin2hex(random_bytes(8));
        }

        return self::$requestId;
    }

    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
        // Browsers cannot issue PATCH/DELETE from a form; honour the override
        // only on POST so a GET can never be promoted to a write.
        if ($method === 'POST') {
            $override = strtoupper((string) ($this->post['_method'] ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        // Strip the sub-directory the app is installed under, if any.
        $base = (string) Config::get('app.base_path', '');
        if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim(rawurldecode($path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? $this->json()[$key] ?? $this->query[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->post, $this->json());
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }
        $decoded = json_decode($this->rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        if ($value === null && in_array(strtolower($name), ['content-type', 'content-length'], true)) {
            $value = $this->server[strtoupper(str_replace('-', '_', $name))] ?? null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization');
        if ($header !== null && preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Client IP. X-Forwarded-For is honoured only when the immediate peer is a
     * configured trusted proxy — otherwise any client could spoof its address
     * and defeat rate limiting and the update-mode IP allowlist.
     */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array) Config::get('app.trusted_proxies', []);
        if ($trusted === [] || !in_array($remote, $trusted, true)) {
            return $remote;
        }

        $forwarded = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
        foreach (array_map('trim', explode(',', $forwarded)) as $candidate) {
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return $remote;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        if (!empty($this->server['HTTPS']) && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        $trusted = (array) Config::get('app.trusted_proxies', []);
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        if (in_array($remote, $trusted, true)) {
            return strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }

        return (int) ($this->server['SERVER_PORT'] ?? 80) === 443;
    }

    public function host(): string
    {
        return (string) ($this->server['HTTP_HOST'] ?? Config::get('app.domain', 'localhost'));
    }

    /** True when the caller wants JSON: API path, XHR, or an explicit Accept. */
    public function wantsJson(): bool
    {
        if (str_starts_with($this->path(), '/api/')) {
            return true;
        }
        if (strtolower((string) $this->header('X-Requested-With')) === 'xmlhttprequest') {
            return true;
        }
        $accept = (string) $this->header('Accept');

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }
}
