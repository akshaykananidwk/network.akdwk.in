<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Logger;
use App\Core\UpdateException;

/**
 * Every filesystem decision the updater makes passes through here.
 *
 * Two separate jobs, deliberately in one place so they can be reviewed
 * together and tested together:
 *
 *   1. Containment — a destination must resolve inside the application root.
 *      Checked on the *resolved* path, so `a/../../etc/passwd`, an absolute
 *      path, and a symlink pointing outside are all rejected. The check works
 *      on paths that do not exist yet (the common case when applying an
 *      update), by resolving the nearest existing ancestor.
 *
 *   2. Protection — a path matching the protected list is never written,
 *      deleted or restored over, even when a manifest explicitly asks.
 *      config/config.php, .env, uploads/ and storage/ survive every update.
 *      The one exception is a shipped control (SHIPPED_CONTROLS below), which
 *      the product may write but no release may delete.
 */
final class PathGuard
{
    /**
     * Paths the product owns even though they sit inside a protected
     * directory.
     *
     * uploads/ is protected because it holds customer content, and that must
     * survive every update. But uploads/.htaccess is not customer content: it
     * is the rule that stops an uploaded .php from being executed. A
     * production install found the directory shipping without it, and if the
     * updater cannot write it, every existing install stays exposed no matter
     * how many releases go out.
     *
     * Writable, never deletable: a manifest that asks to remove one of these
     * is still refused, so a release cannot strip a security control.
     *
     * @var list<string>
     */
    public const SHIPPED_CONTROLS = [
        'uploads/.htaccess',
    ];

    /** @param list<string> $protectedPatterns */
    public function __construct(
        private readonly string $appRoot,
        private readonly array $protectedPatterns = [],
    ) {
        if (realpath($this->appRoot) === false) {
            throw new UpdateException('Application root does not exist: ' . $this->appRoot);
        }
    }

    public function root(): string
    {
        return rtrim((string) realpath($this->appRoot), '/');
    }

    /**
     * Resolve a relative path to an absolute one inside the root.
     *
     * @throws UpdateException when the result would escape the root
     */
    public function resolve(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));

        if ($relativePath === '' || $relativePath === '.') {
            return $this->root();
        }

        // An absolute path, a Windows drive letter or a UNC path is never a
        // legitimate entry in a repository archive or a manifest.
        if (str_starts_with($relativePath, '/')
            || preg_match('#^[a-zA-Z]:[\\\\/]#', $relativePath) === 1
            || str_starts_with($relativePath, '//')) {
            throw new UpdateException('Refusing absolute path: ' . $relativePath);
        }

        if (str_contains($relativePath, "\0")) {
            throw new UpdateException('Refusing path containing a null byte.');
        }

        $candidate = $this->root() . '/' . ltrim($relativePath, '/');
        $normalised = $this->normalise($candidate);

        if (!$this->isInsideRoot($normalised)) {
            Logger::critical('update', 'Path traversal attempt blocked', ['path' => $relativePath]);
            throw new UpdateException('Refusing path outside the application root: ' . $relativePath);
        }

        // A path that exists must also resolve inside the root once symlinks
        // are followed — a symlinked directory is the traversal this catches.
        $real = realpath($normalised);
        if ($real !== false && !$this->isInsideRoot($real)) {
            Logger::critical('update', 'Symlink escape blocked', ['path' => $relativePath, 'resolved' => $real]);
            throw new UpdateException('Refusing path that resolves outside the application root: ' . $relativePath);
        }

        return $normalised;
    }

    /** Non-throwing variant, for filtering lists. */
    public function isSafe(string $relativePath): bool
    {
        try {
            $this->resolve($relativePath);

            return true;
        } catch (UpdateException) {
            return false;
        }
    }

    /**
     * Is this path protected from modification?
     *
     * Matching rules, checked against the path relative to the root:
     *   "uploads/"        — the directory and everything under it
     *   ".env"            — that exact file
     *   "*.local.php"     — glob against the full relative path and basename
     */
    public function isProtected(string $relativePath): bool
    {
        $path = str_replace('\\', '/', trim($relativePath));
        // Strip a leading "./" only — a character-class ltrim would eat the
        // leading dot of a dotfile and let ".env" through unprotected.
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        $path = ltrim($path, '/');

        if ($path === '') {
            return true; // the root itself
        }

        foreach ($this->protectedPatterns as $pattern) {
            $pattern = ltrim(str_replace('\\', '/', trim($pattern)), '/');
            if ($pattern === '') {
                continue;
            }

            if (str_ends_with($pattern, '/')) {
                $directory = rtrim($pattern, '/');
                if ($path === $directory || str_starts_with($path, $directory . '/')) {
                    return true;
                }
                continue;
            }

            if ($path === $pattern) {
                return true;
            }

            if (str_contains($pattern, '*')) {
                if (fnmatch($pattern, $path) || fnmatch($pattern, basename($path))) {
                    return true;
                }
                continue;
            }

            // A protected file name also protects it wherever the archive puts
            // it, so "config/config.php" cannot be slipped in as "./config/config.php".
            if (str_ends_with($path, '/' . $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A path the product owns and may therefore write, protected or not.
     *
     * Matched exactly, against the path relative to the root. No globbing and
     * no suffix matching: a carve-out from the protection rules is only ever
     * as wide as the literal list above.
     */
    public function isShippedControl(string $relativePath): bool
    {
        $path = str_replace('\\', '/', trim($relativePath));
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return in_array(ltrim($path, '/'), self::SHIPPED_CONTROLS, true);
    }

    /**
     * May the updater write this path?
     *
     * Protection minus the shipped-controls carve-out. Deletion still consults
     * isProtected(), so nothing here makes a protected path removable.
     */
    public function isWriteBlocked(string $relativePath): bool
    {
        return $this->isProtected($relativePath) && !$this->isShippedControl($relativePath);
    }

    /**
     * Validate one entry from a downloaded archive.
     *
     * This is the zip-slip guard (§9.4 step 5). An entry is rejected when it
     * is absolute, contains a traversal segment, is a symlink, or has an
     * implausible name. Rejections are logged at critical level because a
     * legitimate repository never produces one.
     *
     * @return string|null the safe relative path, or null when the entry must be skipped
     */
    public function validateArchiveEntry(string $entryName, bool $isSymlink = false): ?string
    {
        $name = str_replace('\\', '/', $entryName);

        if ($name === '' || str_ends_with($name, '/')) {
            return null; // directory entries are created implicitly
        }

        if ($isSymlink) {
            Logger::critical('update', 'Archive symlink entry rejected', ['entry' => $entryName]);

            return null;
        }

        if (str_starts_with($name, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $name) === 1) {
            Logger::critical('update', 'Archive absolute-path entry rejected', ['entry' => $entryName]);

            return null;
        }

        if (str_contains($name, "\0")) {
            Logger::critical('update', 'Archive entry with null byte rejected', ['entry' => $entryName]);

            return null;
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                Logger::critical('update', 'Archive traversal entry rejected', ['entry' => $entryName]);

                return null;
            }
        }

        if (strlen($name) > 1024) {
            Logger::critical('update', 'Archive entry with excessive path length rejected', ['length' => strlen($name)]);

            return null;
        }

        return $name;
    }

    /**
     * Assert a destination is writable and permitted before anything is copied
     * over it.
     *
     * @throws UpdateException
     */
    public function assertWritableTarget(string $relativePath): string
    {
        if ($this->isWriteBlocked($relativePath)) {
            throw new UpdateException('Refusing to write a protected path: ' . $relativePath);
        }

        $absolute = $this->resolve($relativePath);

        $directory = dirname($absolute);
        if (is_dir($directory) && !is_writable($directory)) {
            throw new UpdateException('Directory is not writable: ' . $directory);
        }
        if (is_file($absolute) && !is_writable($absolute)) {
            throw new UpdateException('File is not writable: ' . $absolute);
        }

        return $absolute;
    }

    /** The path relative to the root, for logging and journalling. */
    public function relative(string $absolutePath): string
    {
        $root = $this->root() . '/';
        $normalised = $this->normalise($absolutePath);

        return str_starts_with($normalised, $root) ? substr($normalised, strlen($root)) : $normalised;
    }

    /**
     * Lexical path normalisation — resolves "." and ".." without touching the
     * filesystem, so it works for paths that do not exist yet.
     */
    public function normalise(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $isAbsolute = str_starts_with($path, '/');

        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out);
                } elseif (!$isAbsolute) {
                    $out[] = '..';
                }
                continue;
            }
            $out[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $out);
    }

    private function isInsideRoot(string $absolutePath): bool
    {
        $root = $this->root();
        $normalised = $this->normalise($absolutePath);

        return $normalised === $root || str_starts_with($normalised, $root . '/');
    }
}
