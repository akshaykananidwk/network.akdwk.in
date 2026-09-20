<?php

declare(strict_types=1);

namespace Tests;

/**
 * Tiny cookie-aware HTTP client for the end-to-end tests.
 *
 * Deliberately uses real HTTP against a running server rather than invoking
 * the Kernel in-process: session cookies, redirects, CSRF round-trips and
 * middleware ordering only behave correctly over the wire, and those are
 * exactly what the security tests are checking.
 */
final class HttpClient
{
    /** @var array<string,string> */
    private array $cookies = [];
    private string $lastBody = '';
    private int $lastStatus = 0;
    /** @var array<string,string> */
    private array $lastHeaders = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    /** @param array<string,string> $headers */
    public function get(string $path, array $headers = [], bool $followRedirects = false): self
    {
        return $this->request('GET', $path, null, $headers, $followRedirects);
    }

    /**
     * @param array<string,mixed>|string $body
     * @param array<string,string> $headers
     */
    public function post(string $path, array|string $body = [], array $headers = [], bool $followRedirects = false): self
    {
        return $this->request('POST', $path, $body, $headers, $followRedirects);
    }

    /** @param array<string,string> $headers */
    public function delete(string $path, array $headers = []): self
    {
        return $this->request('DELETE', $path, null, $headers, false);
    }

    /**
     * @param array<string,mixed>|string|null $body
     * @param array<string,string> $headers
     */
    public function request(string $method, string $path, array|string|null $body, array $headers, bool $followRedirects): self
    {
        $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . '/' . ltrim($path, '/');

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $curlHeaders[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Could not initialise curl.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_PROXY          => '', // never route loopback through a proxy
        ];

        if ($body !== null) {
            if (is_array($body)) {
                $options[CURLOPT_POSTFIELDS] = http_build_query($body);
                $curlHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            $options[CURLOPT_HTTPHEADER] = $curlHeaders;
        }

        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException('Request failed: ' . $error);
        }

        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $this->lastStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $rawHeaders = substr((string) $response, 0, $headerSize);
        $this->lastBody = substr((string) $response, $headerSize);
        $this->lastHeaders = [];

        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if ($name === 'set-cookie') {
                $pair = explode(';', $value)[0];
                if (str_contains($pair, '=')) {
                    [$cookieName, $cookieValue] = explode('=', $pair, 2);
                    if ($cookieValue === '' || str_contains($value, 'expires=Thu, 01 Jan 1970')) {
                        unset($this->cookies[trim($cookieName)]);
                    } else {
                        $this->cookies[trim($cookieName)] = $cookieValue;
                    }
                }
            }

            // Keep the last value for repeated headers; enough for these tests.
            $this->lastHeaders[$name] = $value;
        }

        return $this;
    }

    public function status(): int
    {
        return $this->lastStatus;
    }

    public function body(): string
    {
        return $this->lastBody;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->lastBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): ?string
    {
        return $this->lastHeaders[strtolower($name)] ?? null;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->lastHeaders;
    }

    public function location(): ?string
    {
        return $this->header('location');
    }

    /** Pull the CSRF token out of a rendered form so the next POST can pass. */
    public function csrfToken(): ?string
    {
        if (preg_match('/name="_token"\s+value="([^"]+)"/', $this->lastBody, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('/"csrfToken":"([^"]+)"/', $this->lastBody, $matches) === 1) {
            return stripslashes($matches[1]);
        }

        return null;
    }

    public function clearCookies(): self
    {
        $this->cookies = [];

        return $this;
    }

    /** @return array<string,string> */
    public function cookies(): array
    {
        return $this->cookies;
    }
}
