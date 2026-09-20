<?php

declare(strict_types=1);

/**
 * Post-update task: bump every active network's configuration revision.
 *
 * A release can change how ACLs compile or what an agent's configuration
 * contains. Agents only re-read when the revision moves, so without this they
 * would keep running last week's rules until something else happened to change.
 *
 * Referenced from update.json's "post_update" list. It is included by the
 * updater with APP_ROOT defined and the application booted.
 */

use App\Core\DB;
use App\Core\Logger;
use App\Middleware\TenantScope;

if (!defined('APP_ROOT')) {
    return;
}

try {
    $bumped = TenantScope::acrossAllTenants('post-update revision bump', static fn (): int => DB::execute(
        'UPDATE ' . DB::table('networks') . '
         SET config_revision = config_revision + 1, updated_at = UTC_TIMESTAMP()
         WHERE deleted_at IS NULL AND status = \'active\''
    )->rowCount());

    // Drop any compiled ACL artefacts so they are rebuilt from the new code.
    foreach (glob(APP_ROOT . '/storage/cache/acl-*') ?: [] as $file) {
        @unlink($file);
    }

    Logger::info('update', 'Post-update: network revisions bumped', ['networks' => $bumped]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, sprintf("  Bumped %d network revision(s); agents will re-read their configuration.\n", $bumped));
    }
} catch (Throwable $e) {
    // A post-update task is not load-bearing — log and let the update stand.
    Logger::warning('update', 'Post-update ACL rebuild failed', ['error' => $e->getMessage()]);
}
