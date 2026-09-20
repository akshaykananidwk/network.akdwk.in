<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Symmetric encryption for secrets at rest (GitHub PAT, SMTP password,
 * coordinator shared secret) plus the signature verification used by the
 * update manifest checker.
 *
 * AES-256-GCM via openssl — authenticated, so a tampered ciphertext fails to
 * decrypt rather than decrypting to garbage. Payload layout:
 *     base64( "v1" | 0x00 | iv(12) | tag(16) | ciphertext )
 */
final class Crypto
{
    private const VERSION = 'v1';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    /** Derive the 32-byte key from the configured APP_KEY. */
    private static function key(?string $appKey = null): string
    {
        $raw = $appKey ?? (string) Config::get('app.key', '');
        if ($raw === '') {
            throw new AppException('APP_KEY is not configured; cannot encrypt or decrypt secrets.');
        }
        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        // Any other format is stretched to 32 bytes deterministically.
        return hash('sha256', $raw, true);
    }

    public static function generateAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function encrypt(string $plaintext, ?string $appKey = null): string
    {
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key($appKey), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($ciphertext === false) {
            throw new AppException('Encryption failed.');
        }

        return base64_encode(self::VERSION . "\0" . $iv . $tag . $ciphertext);
    }

    /** @return string|null null when the payload is absent, malformed or tampered with */
    public static function decrypt(?string $payload, ?string $appKey = null): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        $raw = base64_decode($payload, true);
        if ($raw === false) {
            return null;
        }
        $sep = strpos($raw, "\0");
        if ($sep === false || substr($raw, 0, $sep) !== self::VERSION) {
            return null;
        }
        $body = substr($raw, $sep + 1);
        if (strlen($body) < self::IV_LEN + self::TAG_LEN) {
            return null;
        }
        $iv  = substr($body, 0, self::IV_LEN);
        $tag = substr($body, self::IV_LEN, self::TAG_LEN);
        $ct  = substr($body, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($ct, self::CIPHER, self::key($appKey), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    // ---------------------------------------------------------------- hashing

    public static function hashPassword(string $password): string
    {
        // Argon2id where available (PHP is built with libargon2 by default
        // since 7.3); bcrypt otherwise so the installer never hard-fails.
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 2,
            ]);
        }

        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function passwordNeedsRehash(string $hash): bool
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2,
            ]);
        }

        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    // ---------------------------------------------------------------- tokens

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function randomHex(int $bytes = 8): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Human-typable code (join codes, recovery codes). Crockford-ish alphabet
     * with I/L/O/U removed so codes read aloud without ambiguity.
     */
    public static function randomCode(int $length = 12): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function hashEquals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    // ------------------------------------------------------------- signatures

    /**
     * Ed25519 keypair for the controller identity and the update manifest.
     *
     * @return array{public:string,secret:string} both hex encoded
     */
    public static function generateSigningKeypair(): array
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            throw new AppException('ext-sodium is required to generate signing keys.');
        }
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => bin2hex(sodium_crypto_sign_publickey($pair)),
            'secret' => bin2hex(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /**
     * Verify a detached ed25519 signature.
     *
     * @param string $signature hex, optionally prefixed "ed25519:"
     * @param string $publicKey hex
     */
    public static function verifySignature(string $message, string $signature, string $publicKey): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        if (str_starts_with($signature, 'ed25519:')) {
            $signature = substr($signature, 8);
        }
        $sigRaw = @hex2bin($signature);
        $keyRaw = @hex2bin($publicKey);
        if ($sigRaw === false || $keyRaw === false) {
            return false;
        }
        if (strlen($sigRaw) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($keyRaw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($sigRaw, $message, $keyRaw);
        } catch (\SodiumException) {
            return false;
        }
    }

    public static function sign(string $message, string $secretKeyHex): string
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new AppException('ext-sodium is required to sign.');
        }
        $key = @hex2bin($secretKeyHex);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new AppException('Invalid signing key.');
        }

        return 'ed25519:' . bin2hex(sodium_crypto_sign_detached($message, $key));
    }

    /** Curve25519 public key sanity check for device enrolment (32 bytes, base64). */
    public static function isValidCurve25519PublicKey(string $base64): bool
    {
        $raw = base64_decode($base64, true);

        return $raw !== false && strlen($raw) === 32;
    }
}
