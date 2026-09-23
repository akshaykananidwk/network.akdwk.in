<?php

declare(strict_types=1);

/**
 * The owner rule for every panel CLI: act as the owner of the panel's files.
 *
 * Required by cli/_bootstrap.php and by cli/install.php, before either writes
 * anything. See the comment where _bootstrap.php requires it for why; the
 * short form is that git refuses a repository that is not the caller's, and
 * the panel cannot update or roll back over a file that is not its own — so
 * every tool that touches this tree does it as the tree's owner, not as
 * whoever happened to type the command.
 */
function akconnect_become_tree_owner(string $root): void
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        return;
    }

    $treeOwner = @fileowner($root);
    if (!is_int($treeOwner) || $treeOwner === 0) {
        return;
    }

    $account = function_exists('posix_getpwuid') ? posix_getpwuid($treeOwner) : false;
    $name = is_array($account) ? (string) $account['name'] : 'uid ' . $treeOwner;

    // Every call checked for first. PHP 8 removes a function listed in
    // disable_functions, so calling one throws — and aaPanel's default list
    // disables putenv, for the CLI as well as for PHP-FPM.
    $became = is_array($account)
        && function_exists('posix_setgid') && function_exists('posix_setuid')
        && posix_setgid((int) $account['gid'])
        && (!function_exists('posix_initgroups') || posix_initgroups($name, (int) $account['gid']))
        && posix_setuid($treeOwner)
        && posix_geteuid() === $treeOwner;

    if (!$became) {
        $command = implode(' ', array_map('escapeshellarg', $_SERVER['argv'] ?? []));
        fwrite(STDERR, "This panel's files belong to {$name}, and this is running as root.\n"
            . "Anything it wrote would be root's, and the panel — which runs as {$name} — could not\n"
            . "use, update or roll back over it. "
            . (is_array($account)
                ? "Run it as that user:\n    sudo -u {$name} php {$command}\n"
                // No account to become, and sudo refuses a uid it cannot name.
                : "That uid has no account on this machine, so nothing can run as it.\n"
                    . "Give the files to the user the web server runs PHP as, then run this as that user:\n"
                    . "    chown -R <web user>: " . $root . "\n"));
        exit(1);
    }

    if (function_exists('putenv')) {
        putenv('HOME=' . (string) $account['dir']);
    }
    $_SERVER['HOME'] = (string) $account['dir'];
    $_ENV['HOME'] = (string) $account['dir'];
    fwrite(STDERR, "  (running as {$name}, the owner of this panel's files — not as root)\n");
}
