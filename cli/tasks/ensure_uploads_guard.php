<?php

declare(strict_types=1);

/**
 * Post-update task: make sure uploads/.htaccess exists.
 *
 * uploads/ is a protected path, so the updater does not write into it — that
 * is what keeps a customer's logo through an update. 1.9.1 carves this one
 * file out of that rule (PathGuard::SHIPPED_CONTROLS), but the updater that
 * applies 1.9.1 is the *old* code: on every site updating from 1.9.0 or
 * earlier, the file in the release is skipped and the directory stays as it
 * was — which is to say, with nothing stopping an uploaded .php from being
 * executed.
 *
 * So this writes it, from the copy that arrived with the release. From 1.9.1
 * onwards the updater does it directly and this task finds nothing to do.
 *
 * Referenced from update.json's "post_update" list. It is included by the
 * updater with APP_ROOT defined and the application booted.
 */

use App\Core\Logger;

if (!defined('APP_ROOT')) {
    return;
}

$source = APP_ROOT . '/uploads/.htaccess';

try {
    // The release's own copy, from the staged tree the updater extracted. The
    // stage is named after the commit and is still on disk when post-update
    // tasks run, so the newest one is this release's.
    $wanted = null;
    $staged = glob(APP_ROOT . '/storage/updates/stage/*/uploads/.htaccess') ?: [];
    usort($staged, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

    if ($staged !== []) {
        $wanted = (string) file_get_contents($staged[0]);
    } elseif (is_file($source)) {
        // Nothing staged — running from the CLI against a checked-out tree.
        $wanted = (string) file_get_contents($source);
    }

    if ($wanted === null || trim($wanted) === '') {
        Logger::warning('update', 'Post-update: no uploads/.htaccess in this release to install');

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "  warning: this release carries no uploads/.htaccess\n");
        }

        return;
    }

    $current = is_file($source) ? (string) file_get_contents($source) : '';
    if (hash('sha256', $current) === hash('sha256', $wanted)) {
        Logger::info('update', 'Post-update: uploads/.htaccess is already current');

        return;
    }

    if (!is_dir(dirname($source)) && !mkdir(dirname($source), 0775, true) && !is_dir(dirname($source))) {
        throw new RuntimeException('uploads/ does not exist and could not be created');
    }

    if (file_put_contents($source, $wanted) === false) {
        throw new RuntimeException('could not write ' . $source);
    }

    @chmod($source, 0644);

    Logger::notice('update', 'Post-update: uploads/.htaccess installed', [
        'existed' => $current !== '',
    ]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, "  Wrote uploads/.htaccess — an uploaded .php can no longer be executed.\n");
    }
} catch (Throwable $e) {
    // A post-update task is not load-bearing — log and let the update stand.
    Logger::warning('update', 'Post-update uploads guard failed', ['error' => $e->getMessage()]);
}
