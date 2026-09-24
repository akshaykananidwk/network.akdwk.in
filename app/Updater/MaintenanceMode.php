<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Logger;

/**
 * Maintenance flag (§9.4 step 2).
 *
 * A file rather than a settings row, deliberately: the flag has to work when
 * the database is mid-migration or unreachable, which is exactly when it
 * matters most. The bypass token lets the operator watching the progress bar
 * keep using the panel while everyone else gets a 503.
 */
final class MaintenanceMode
{
    private const FLAG = '/storage/maintenance.flag';

    /**
     * When the last maintenance window began and ended, kept after the flag
     * is gone. Devices cannot heartbeat while the panel answers 503, so a
     * device is not counted offline for time the panel could not hear it —
     * see Device::onlineCutoff().
     */
    private const LAST = '/storage/maintenance.last';

    public function __construct(private readonly string $appRoot)
    {
    }

    public static function make(): self
    {
        return new self(APP_ROOT);
    }

    public function flagPath(): string
    {
        return $this->appRoot . self::FLAG;
    }

    /**
     * @param list<string> $allowedIps IPs that bypass the 503
     * @return string the bypass token, to be set as a cookie for the operator
     */
    public function enable(string $reason, array $allowedIps = [], int $retryAfter = 300): string
    {
        $token = bin2hex(random_bytes(16));

        $payload = [
            'enabled_at'  => gmdate('c'),
            'reason'      => $reason,
            'allowed_ips' => array_values(array_filter($allowedIps, static fn ($ip): bool => is_string($ip) && $ip !== '')),
            'bypass_hash' => hash('sha256', $token),
            'retry_after' => $retryAfter,
        ];

        $directory = dirname($this->flagPath());
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        file_put_contents($this->flagPath(), (string) json_encode($payload, JSON_PRETTY_PRINT), LOCK_EX);
        @chmod($this->flagPath(), 0640);

        Logger::notice('update', 'Maintenance mode enabled', ['reason' => $reason, 'allowed_ips' => $payload['allowed_ips']]);

        return $token;
    }

    public function disable(): void
    {
        if (is_file($this->flagPath())) {
            $started = strtotime((string) ($this->details()['enabled_at'] ?? ''));
            if ($started !== false) {
                @file_put_contents($this->appRoot . self::LAST, (string) json_encode([
                    'started' => $started,
                    'ended'   => time(),
                ]), LOCK_EX);
            }

            @unlink($this->flagPath());
            Logger::notice('update', 'Maintenance mode disabled');
        }
    }

    /**
     * The current or most recent maintenance window: [started, ended], unix
     * times, `ended` null while it is still on. Null when there has been none.
     *
     * @return array{0:int,1:?int}|null
     */
    public function lastWindow(): ?array
    {
        if ($this->isEnabled()) {
            $started = strtotime((string) ($this->details()['enabled_at'] ?? ''));

            return $started !== false ? [$started, null] : null;
        }

        $raw = @file_get_contents($this->appRoot . self::LAST);
        $last = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($last) || !is_int($last['started'] ?? null) || !is_int($last['ended'] ?? null)) {
            return null;
        }

        return [$last['started'], $last['ended']];
    }

    public function isEnabled(): bool
    {
        return is_file($this->flagPath());
    }

    /** @return array<string,mixed>|null */
    public function details(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $contents = file_get_contents($this->flagPath());
        if ($contents === false) {
            return ['reason' => 'Maintenance in progress.', 'retry_after' => 300];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : ['reason' => 'Maintenance in progress.', 'retry_after' => 300];
    }

    /** May this request pass through the 503? */
    public function allows(string $ip, ?string $bypassToken): bool
    {
        $details = $this->details();
        if ($details === null) {
            return true;
        }

        if (in_array($ip, (array) ($details['allowed_ips'] ?? []), true)) {
            return true;
        }

        $expected = (string) ($details['bypass_hash'] ?? '');

        return $bypassToken !== null
            && $expected !== ''
            && hash_equals($expected, hash('sha256', $bypassToken));
    }

    public function retryAfter(): int
    {
        return (int) ($this->details()['retry_after'] ?? 300);
    }

    public static function cookieName(): string
    {
        return 'ak_maint_bypass';
    }
}
