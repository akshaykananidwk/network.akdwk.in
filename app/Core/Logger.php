<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Structured JSON logger with daily rotation.
 *
 * Every value written passes through redact(). This is the single control that
 * satisfies the "GitHub token never appears in any log" requirement, so it is
 * deliberately aggressive: known secret-ish key names are replaced wholesale,
 * and known token shapes are masked even when they appear inside free text
 * (exception messages, curl errors, command output).
 */
final class Logger
{
    public const EMERGENCY = 'emergency';
    public const ALERT     = 'alert';
    public const CRITICAL  = 'critical';
    public const ERROR     = 'error';
    public const WARNING   = 'warning';
    public const NOTICE    = 'notice';
    public const INFO      = 'info';
    public const DEBUG     = 'debug';

    /** Keys whose values are replaced entirely, matched case-insensitively. */
    private const SECRET_KEYS = [
        'password', 'pass', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'auth', 'private_key', 'privatekey', 'key_hash',
        'token_encrypted', 'app_key', 'twofa_secret', 'recovery_codes',
        'db_pass', 'mail_pass', 'smtp_password', 'github_token', 'pat',
        'client_secret', 'signature', 'csrf', 'session_id', 'device_token',
    ];

    /** Token shapes masked anywhere they appear in a string. */
    private const SECRET_PATTERNS = [
        '/\bgh[pousr]_[A-Za-z0-9]{16,}/',                 // GitHub PAT / OAuth / refresh
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/',               // GitHub fine-grained PAT
        '/\bBearer\s+[A-Za-z0-9._\-]{12,}/i',             // Authorization headers
        '/\bBasic\s+[A-Za-z0-9+\/=]{12,}/i',
        '/\b(?:ak|sk)_(?:live|test)_[A-Za-z0-9]{12,}/',   // our own API keys
        '/:\/\/[^:@\/\s]+:[^@\/\s]+@/',                   // credentials inside a URL
    ];

    private static ?string $logDir = null;
    private static string $minLevel = self::DEBUG;

    /** @var array<string,int> */
    private static array $levelWeight = [
        self::DEBUG => 0, self::INFO => 1, self::NOTICE => 2, self::WARNING => 3,
        self::ERROR => 4, self::CRITICAL => 5, self::ALERT => 6, self::EMERGENCY => 7,
    ];

    public static function configure(string $logDir, string $minLevel = self::DEBUG): void
    {
        self::$logDir = rtrim($logDir, '/');
        self::$minLevel = isset(self::$levelWeight[$minLevel]) ? $minLevel : self::DEBUG;
    }

    /** @param array<string,mixed> $context */
    public static function log(string $level, string $channel, string $message, array $context = []): void
    {
        if (self::$logDir === null) {
            return;
        }
        if ((self::$levelWeight[$level] ?? 0) < (self::$levelWeight[self::$minLevel] ?? 0)) {
            return;
        }

        $record = [
            'ts'      => gmdate('c'),
            'level'   => $level,
            'channel' => $channel,
            'message' => self::redactString($message),
            'context' => self::redact($context),
            'req_id'  => Request::id(),
        ];

        $file = sprintf('%s/%s-%s.log', self::$logDir, self::channelFile($channel), gmdate('Y-m-d'));
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            $line = json_encode(['ts' => gmdate('c'), 'level' => $level, 'channel' => $channel, 'message' => 'unencodable log record']);
        }

        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0750, true);
        }
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $c */ public static function debug(string $ch, string $m, array $c = []): void { self::log(self::DEBUG, $ch, $m, $c); }
    /** @param array<string,mixed> $c */ public static function info(string $ch, string $m, array $c = []): void { self::log(self::INFO, $ch, $m, $c); }
    /** @param array<string,mixed> $c */ public static function notice(string $ch, string $m, array $c = []): void { self::log(self::NOTICE, $ch, $m, $c); }
    /** @param array<string,mixed> $c */ public static function warning(string $ch, string $m, array $c = []): void { self::log(self::WARNING, $ch, $m, $c); }
    /** @param array<string,mixed> $c */ public static function error(string $ch, string $m, array $c = []): void { self::log(self::ERROR, $ch, $m, $c); }
    /** @param array<string,mixed> $c */ public static function critical(string $ch, string $m, array $c = []): void { self::log(self::CRITICAL, $ch, $m, $c); }

    /**
     * Recursively strip secrets from an arbitrary structure.
     *
     * @param mixed $data
     * @return mixed
     */
    public static function redact(mixed $data): mixed
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && self::isSecretKey($key)) {
                    $out[$key] = self::mask(is_scalar($value) ? (string) $value : '');
                    continue;
                }
                $out[$key] = self::redact($value);
            }

            return $out;
        }

        if (is_object($data)) {
            return self::redact(get_object_vars($data));
        }

        if (is_string($data)) {
            return self::redactString($data);
        }

        return $data;
    }

    /** Mask known secret shapes inside free text. */
    public static function redactString(string $value): string
    {
        foreach (self::SECRET_PATTERNS as $pattern) {
            $value = (string) preg_replace_callback(
                $pattern,
                static fn (array $m): string => self::mask($m[0]),
                $value
            );
        }

        return $value;
    }

    /** Keep enough to recognise a value ("ghp_****abcd") without revealing it. */
    public static function mask(string $value): string
    {
        $len = strlen($value);
        if ($len === 0) {
            return '[redacted]';
        }
        if ($len <= 8) {
            return '[redacted]';
        }
        $prefixLen = 0;
        foreach (['ghp_', 'gho_', 'ghu_', 'ghs_', 'ghr_', 'github_pat_', 'ak_live_', 'ak_test_'] as $known) {
            if (str_starts_with($value, $known)) {
                $prefixLen = strlen($known);
                break;
            }
        }

        return substr($value, 0, $prefixLen) . '****' . substr($value, -4);
    }

    private static function isSecretKey(string $key): bool
    {
        $needle = strtolower($key);
        foreach (self::SECRET_KEYS as $secret) {
            if ($needle === $secret || str_contains($needle, $secret)) {
                return true;
            }
        }

        return false;
    }

    private static function channelFile(string $channel): string
    {
        return match ($channel) {
            'update', 'backup', 'migration' => 'update',
            'security', 'auth', 'audit'     => 'security',
            'api', 'agent'                  => 'api',
            default                         => 'app',
        };
    }

    /** Delete rotated logs older than the retention window. Called by the worker. */
    public static function prune(int $retentionDays): int
    {
        if (self::$logDir === null || !is_dir(self::$logDir)) {
            return 0;
        }
        $cutoff = time() - ($retentionDays * 86400);
        $removed = 0;
        foreach (glob(self::$logDir . '/*.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }
}
