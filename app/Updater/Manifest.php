<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Logger;
use App\Core\UpdateException;

/**
 * Parses and validates update.json (§9.3).
 *
 * A manifest is not trusted input: it decides which files get deleted and
 * which scripts run after an update. Everything is type-checked, paths are
 * filtered through PathGuard, and — when verify_signature is on — the whole
 * document must carry a valid ed25519 signature or the update is refused.
 */
final class Manifest
{
    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @throws UpdateException when the document is not a usable manifest
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new UpdateException('update.json is not valid JSON.');
        }
        if (!isset($decoded['version']) || !is_string($decoded['version'])) {
            throw new UpdateException('update.json is missing a "version" string.');
        }

        return new self($decoded);
    }

    /** A repository without update.json still updates, with conservative defaults. */
    public static function fallback(string $version): self
    {
        return new self([
            'version'     => $version,
            'breaking'    => false,
            'notes'       => 'No update.json in this revision; applying with default settings.',
            'migrations'  => [],
            'delete'      => [],
            'post_update' => [],
            'checksums'   => [],
            '_synthetic'  => true,
        ]);
    }

    public function isSynthetic(): bool
    {
        return (bool) ($this->data['_synthetic'] ?? false);
    }

    public function version(): string
    {
        return (string) $this->data['version'];
    }

    public function notes(): string
    {
        return (string) ($this->data['notes'] ?? '');
    }

    public function releasedAt(): ?string
    {
        $value = $this->data['released_at'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function isBreaking(): bool
    {
        return (bool) ($this->data['breaking'] ?? false);
    }

    public function minPhp(): ?string
    {
        $value = $this->data['min_php'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function minMysql(): ?string
    {
        $value = $this->data['min_mysql'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    public function migrations(): array
    {
        return $this->stringList('migrations');
    }

    /**
     * Files the release wants removed.
     *
     * Filtered twice: unsafe paths are dropped outright, and protected paths
     * are dropped with a warning — a manifest may not delete config.php even
     * if it says so (§9.5).
     *
     * @return list<string>
     */
    public function deletions(PathGuard $guard): array
    {
        $out = [];
        foreach ($this->stringList('delete') as $path) {
            if (!$guard->isSafe($path)) {
                Logger::critical('update', 'Manifest delete entry rejected as unsafe', ['path' => $path]);
                continue;
            }
            if ($guard->isProtected($path)) {
                Logger::warning('update', 'Manifest asked to delete a protected path; refused', ['path' => $path]);
                continue;
            }
            $out[] = $path;
        }

        return $out;
    }

    /**
     * Scripts run after files are in place.
     *
     * Constrained to PHP files inside the app root — a manifest cannot ask us
     * to execute a shell command or a file outside the tree.
     *
     * @return list<string>
     */
    public function postUpdateScripts(PathGuard $guard): array
    {
        $out = [];
        foreach ($this->stringList('post_update') as $script) {
            if (!$guard->isSafe($script)) {
                Logger::critical('update', 'Manifest post_update entry rejected as unsafe', ['script' => $script]);
                continue;
            }
            if (!str_ends_with(strtolower($script), '.php')) {
                Logger::warning('update', 'Manifest post_update entry is not a PHP file; skipped', ['script' => $script]);
                continue;
            }
            $out[] = $script;
        }

        return $out;
    }

    /**
     * Additional protected paths declared by the release itself.
     *
     * @return list<string>
     */
    public function protectedPaths(): array
    {
        return $this->stringList('protected');
    }

    /** @return array<string,string> relative path => "sha256:..." */
    public function checksums(): array
    {
        $checksums = $this->data['checksums'] ?? [];
        if (!is_array($checksums)) {
            return [];
        }

        $out = [];
        foreach ($checksums as $path => $hash) {
            if (is_string($path) && is_string($hash)) {
                $out[$path] = $hash;
            }
        }

        return $out;
    }

    public function signature(): ?string
    {
        $value = $this->data['signature'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Verify the manifest's ed25519 signature.
     *
     * The signed message is the manifest with its own "signature" key removed,
     * re-encoded with sorted keys — so signing and verification agree on the
     * bytes regardless of how the publisher's tooling ordered the JSON.
     */
    public function verifySignature(?string $publicKey = null): bool
    {
        $signature = $this->signature();
        if ($signature === null) {
            return false;
        }

        $key = $publicKey ?? (string) Config::get('security.update_public_key', '');
        if ($key === '') {
            Logger::error('update', 'Signature verification requested but no update public key is configured.');

            return false;
        }

        return Crypto::verifySignature($this->canonicalPayload(), $signature, $key);
    }

    /** The exact bytes that are signed. Also used by the signing helper. */
    public function canonicalPayload(): string
    {
        $data = $this->data;
        unset($data['signature'], $data['_synthetic']);
        self::ksortRecursive($data);

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Environment requirements check.
     *
     * @return array{ok:bool,failures:list<string>}
     */
    public function checkRequirements(?string $mysqlVersion = null): array
    {
        $failures = [];

        $minPhp = $this->minPhp();
        if ($minPhp !== null && version_compare(PHP_VERSION, $minPhp, '<')) {
            $failures[] = sprintf('This release needs PHP %s or newer; this server runs %s.', $minPhp, PHP_VERSION);
        }

        $minMysql = $this->minMysql();
        if ($minMysql !== null && $mysqlVersion !== null) {
            // MariaDB reports e.g. "10.6.16-MariaDB"; compare the numeric head.
            $numeric = preg_replace('/[^0-9.].*$/', '', $mysqlVersion) ?? $mysqlVersion;
            $isMariaDb = stripos($mysqlVersion, 'mariadb') !== false;
            if (!$isMariaDb && version_compare($numeric, $minMysql, '<')) {
                $failures[] = sprintf('This release needs MySQL %s or newer; this server runs %s.', $minMysql, $mysqlVersion);
            }
        }

        return ['ok' => $failures === [], 'failures' => $failures];
    }

    /** @return array<string,mixed> the raw document, for storage and display */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return list<string> */
    private function stringList(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $value),
            static fn (string $v): bool => $v !== ''
        ));
    }

    /** @param array<mixed> $array */
    private static function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }
}
