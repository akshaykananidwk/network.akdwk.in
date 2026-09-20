<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Logger;

/**
 * Per-run, human-readable log file tailed live by the progress UI.
 *
 * Separate from the structured application log because this one is read by a
 * person at 2am trying to work out what an update did. Every line is
 * timestamped and redacted; the GitHub token cannot reach it even if an error
 * message from curl or GitHub contains one.
 */
final class UpdateLog
{
    public function __construct(private readonly string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }
    }

    public static function forUpdate(int $updateId): self
    {
        return new self(sprintf('%s/storage/logs/update-%d.log', APP_ROOT, $updateId));
    }

    public function path(): string
    {
        return $this->path;
    }

    public function relativePath(): string
    {
        $root = rtrim(APP_ROOT, '/') . '/';

        return str_starts_with($this->path, $root) ? substr($this->path, strlen($root)) : $this->path;
    }

    /** @param array<string,mixed> $context */
    public function write(string $level, string $message, array $context = []): void
    {
        $line = sprintf(
            '[%s] %-7s %s%s',
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            Logger::redactString($message),
            $context === [] ? '' : ' ' . (string) json_encode(Logger::redact($context), JSON_UNESCAPED_SLASHES)
        );

        @file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
        Logger::info('update', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warn(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
        Logger::warning('update', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
        Logger::error('update', $message, $context);
    }

    public function step(string $step, string $message): void
    {
        $this->write('step', sprintf('== %s == %s', $step, $message));
    }

    /**
     * Read the log back for display.
     *
     * @return list<string>
     */
    public function tail(int $lines = 200): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $all = file($this->path, FILE_IGNORE_NEW_LINES);
        if ($all === false) {
            return [];
        }

        return array_slice($all, -max(1, $lines));
    }

    public function contents(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
    }

    public function sizeBytes(): int
    {
        return is_file($this->path) ? (int) filesize($this->path) : 0;
    }
}
