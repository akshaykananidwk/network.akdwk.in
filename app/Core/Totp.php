<?php

declare(strict_types=1);

namespace App\Core;

/**
 * RFC 6238 TOTP (SHA-1, 6 digits, 30s) — what Google Authenticator, Authy and
 * 1Password implement. Verification accepts a ±1 step window for clock drift.
 *
 * No QR image is generated server-side; we emit the otpauth:// URI and the
 * browser renders it, which keeps the shared secret out of any image cache.
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALGO = 'sha1';
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, ?int $timestamp = null, int $offsetSteps = 0): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        $counter = intdiv($timestamp ?? time(), self::PERIOD) + $offsetSteps;
        $binaryCounter = pack('N*', 0, $counter);
        $hash = hash_hmac(self::ALGO, $binaryCounter, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** @param int $window number of 30s steps of tolerance either side */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, null, $i), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    /**
     * Recovery codes are shown once, then only their hashes are stored.
     *
     * @return array{plain:list<string>,hashes:list<string>}
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = Crypto::randomCode(5) . '-' . Crypto::randomCode(5);
            $plain[] = $code;
            $hashes[] = hash('sha256', $code);
        }

        return ['plain' => $plain, 'hashes' => $hashes];
    }

    /**
     * Consume a recovery code. Returns the remaining hashes, or null if the
     * code did not match.
     *
     * @param list<string> $hashes
     * @return list<string>|null
     */
    public static function consumeRecoveryCode(string $code, array $hashes): ?array
    {
        $needle = hash('sha256', strtoupper(trim($code)));
        $remaining = [];
        $found = false;
        foreach ($hashes as $hash) {
            if (!$found && hash_equals($hash, $needle)) {
                $found = true;
                continue;
            }
            $remaining[] = $hash;
        }

        return $found ? $remaining : null;
    }

    public static function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = rtrim(strtoupper($encoded), '=');
        if ($encoded === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($encoded) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr((int) bindec($chunk));
            }
        }

        return $out;
    }
}
