#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Unattended installation from a JSON answers file.
 *
 * For scripted deployments and for hosts where the web installer cannot run.
 * It performs exactly the same work as the wizard and produces the same
 * config/config.php and install.lock.
 *
 *   php cli/install.php --answers=install-answers.json
 *   php cli/install.php --print-template > install-answers.json
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('APP_ROOT', dirname(__DIR__));

// Run as root on a tree the web user owns, the installer wrote config.php,
// .env and install.lock as root:root 0640. The panel, running as the web
// user, then could not read its own configuration and answered 500 to every
// request, while this printed "Installation complete".
//
// An answers file is read first, as whoever started this. A root run that
// becomes the web user can no longer open a file root keeps in /root —
// `--print-template > install-answers.json` there is the documented way — and
// it then said "No such answers file" about a file that was right there.
$answersReadEarly = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--answers=') && $argument !== '--answers=-') {
        $path = substr($argument, strlen('--answers='));
        if ($path !== '' && is_file($path) && is_readable($path)) {
            $contents = @file_get_contents($path);
            if (is_string($contents)) {
                $answersReadEarly = ['path' => $path, 'raw' => $contents];
            }
        }
    }
}

require __DIR__ . '/_owner.php';
akconnect_become_tree_owner(APP_ROOT);

require APP_ROOT . '/app/Core/Autoloader.php';
$autoloader = new App\Core\Autoloader();
$autoloader->addNamespace('App', APP_ROOT . '/app');
$autoloader->addNamespace('Install', APP_ROOT . '/install');
$autoloader->register();
require APP_ROOT . '/app/Core/helpers.php';

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Totp;
use Install\Installer;

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--')) {
        $argument = substr($argument, 2);
        [$key, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, true];
        $options[$key] = $value;
    }
}

if (isset($options['print-template'])) {
    echo json_encode([
        'site_name'        => 'AK Connect',
        'org_name'         => 'AK Computer',
        'app_url'          => 'https://net.example.com',
        'timezone'         => 'Asia/Kolkata',
        'support_email'    => 'support@net.example.com',
        'db_host'          => '127.0.0.1',
        'db_port'          => 3306,
        'db_name'          => 'akconnect',
        'db_user'          => 'akconnect',
        'db_pass'          => '',
        'db_prefix'        => '',
        'admin_name'       => 'Administrator',
        'admin_email'      => 'admin@example.com',
        'admin_password'   => 'change-this-to-a-strong-password',
        'enable_2fa'       => false,
        'mail_host'        => '',
        'mail_port'        => 587,
        'mail_user'        => '',
        'mail_pass'        => '',
        'mail_security'    => 'tls',
        'mail_from'        => '',
        'coordinator_host' => '127.0.0.1',
        'coordinator_port' => 8443,
        'gh_owner'         => '',
        'gh_repo'          => '',
        'gh_branch'        => 'main',
        'gh_token'         => '',
        'drop_existing'    => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$installer = new Installer(APP_ROOT);

// The same evidence the browser wizard uses, and for the same reason: the
// lock file is something an operator was once told to delete, so it cannot be
// the only thing standing between a live panel and a reinstall.
$evidence = $installer->installedEvidence();
if ($evidence !== []) {
    fwrite(STDERR, "Already installed:\n");
    foreach ($evidence as $reason) {
        fwrite(STDERR, '  - ' . $reason . "\n");
    }
    fwrite(STDERR, "Remove install/install.lock and config/config.php to reinstall — after taking a backup.\n");

    if ($installer->relock()) {
        fwrite(STDERR, "install/install.lock has been restored.\n");
    }

    exit(1);
}

$answersFile = (string) ($options['answers'] ?? '');
if ($answersFile === '') {
    fwrite(STDERR, "Usage: php cli/install.php --answers=<file.json>\n"
        . "       php cli/install.php --answers=-          read the answers from standard input\n"
        . "       php cli/install.php --print-template\n");
    exit(1);
}

// Standard input, so a caller with secrets to hand over never has to write
// them to a file at all. deploy/getting-started.sh uses this: it runs as root
// and the installer runs as the web user, and the obvious way to bridge that
// — a file only root can read — is how a real install failed in the field.
if ($answersFile === '-') {
    $raw = stream_get_contents(STDIN);
    if ($raw === false || trim((string) $raw) === '') {
        fwrite(STDERR, "No answers arrived on standard input.\n");
        exit(1);
    }
    $source = 'standard input';
} elseif ($answersReadEarly !== null && $answersReadEarly['path'] === $answersFile) {
    $raw = $answersReadEarly['raw'];
    $source = $answersFile;
} else {
    if (!is_file($answersFile)) {
        fwrite(STDERR, 'No such answers file: ' . $answersFile . "\n");
        exit(1);
    }

    // Asked before reading, and said as itself.
    //
    // Reading an unreadable file returns false, false casts to the empty
    // string, and the empty string is not valid JSON — so a permission
    // problem reported itself as "The answers file is not valid JSON", which
    // is true and useless. The operator went looking at the JSON. It is a
    // different fault with a different fix and it now says so.
    if (!is_readable($answersFile)) {
        fwrite(STDERR, 'Cannot read ' . $answersFile . ': permission denied'
            . ' (running as ' . currentUserName() . ").\n"
            . "The file exists but this user may not open it. Either hand the answers over on\n"
            . "standard input — php cli/install.php --answers=- — or make the file readable by\n"
            . "the user the installer runs as.\n");
        exit(1);
    }

    $raw = @file_get_contents($answersFile);
    if ($raw === false) {
        $error = error_get_last();
        fwrite(STDERR, 'Cannot read ' . $answersFile . ': '
            . ($error['message'] ?? 'unknown error') . "\n");
        exit(1);
    }
    $source = $answersFile;
}

$answers = json_decode((string) $raw, true);
if (!is_array($answers)) {
    fwrite(STDERR, 'The answers read from ' . $source . ' are not valid JSON: '
        . json_last_error_msg() . "\n");
    exit(1);
}

/**
 * Who this process is, for a permission message that would otherwise leave
 * the reader guessing which user could not open the file.
 */
function currentUserName(): string
{
    if (function_exists('posix_geteuid')) {
        $uid = posix_geteuid();
        $info = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;

        return is_array($info) && isset($info['name']) ? (string) $info['name'] : 'uid ' . $uid;
    }

    $user = getenv('USER');

    return $user !== false && $user !== '' ? $user : 'this user';
}

function out(string $message): void
{
    fwrite(STDOUT, $message . "\n");
}

function fail(string $message): never
{
    fwrite(STDERR, "✗ " . $message . "\n");
    exit(1);
}

// ------------------------------------------------------------- validation

foreach (['app_url', 'db_name', 'db_user', 'admin_name', 'admin_email', 'admin_password'] as $required) {
    if (($answers[$required] ?? '') === '') {
        fail('Missing required answer: ' . $required);
    }
}
if (filter_var($answers['admin_email'], FILTER_VALIDATE_EMAIL) === false) {
    fail('admin_email is not a valid email address.');
}
if (strlen((string) $answers['admin_password']) < 10) {
    fail('admin_password must be at least 10 characters.');
}

$answers += [
    'db_host' => '127.0.0.1', 'db_port' => 3306, 'db_pass' => '', 'db_prefix' => '',
    'timezone' => 'Asia/Kolkata', 'site_name' => 'AK Connect', 'support_email' => '',
];

// ------------------------------------------------------------ requirements

out('Checking requirements…');
$check = $installer->checkRequirements();
foreach ($check['groups'] as $group => $rows) {
    foreach ($rows as $row) {
        if ($row['required'] && !$row['ok']) {
            out(sprintf('  ✗ %s: %s (need %s)', $row['label'], $row['found'], $row['expected']));
            if ($row['fix'] !== '') {
                out('    ' . $row['fix']);
            }
        }
    }
}
// The rewrite probe needs a reachable HTTP server, which a CLI install may not
// have; it is reported but not fatal here.
if (!$check['ok']) {
    $blocking = false;
    foreach ($check['groups'] as $group => $rows) {
        foreach ($rows as $row) {
            if ($row['required'] && !$row['ok'] && $group !== 'Web server') {
                $blocking = true;
            }
        }
    }
    if ($blocking) {
        fail('Required checks failed. Fix them and run again.');
    }
    out('  ! URL rewriting could not be verified from the command line. Confirm it once the site is reachable.');
}
out('  ✓ Requirements satisfied');

// ---------------------------------------------------------------- database

out('Connecting to the database…');
$test = $installer->testDatabase(
    (string) $answers['db_host'], (int) $answers['db_port'], (string) $answers['db_name'],
    (string) $answers['db_user'], (string) $answers['db_pass'], (string) $answers['db_prefix']
);
if (!$test['ok']) {
    fail($test['message']);
}
out('  ✓ ' . $test['message']);

$existing = (int) ($test['existing_tables'] ?? 0);
if ($existing > 0 && empty($answers['drop_existing'])) {
    fail(sprintf(
        'The database already contains %d table(s). Point at an empty database, or set "drop_existing": true '
        . 'in the answers file to drop them (this cannot be undone).',
        $existing
    ));
}

$pdo = DB::connectWith(
    (string) $answers['db_host'], (int) $answers['db_port'], (string) $answers['db_name'],
    (string) $answers['db_user'], (string) $answers['db_pass'], (string) $answers['db_prefix']
);
DB::setConnection($pdo, (string) $answers['db_prefix']);

out('Importing the schema…');
$import = $installer->importSchema($pdo, (string) $answers['db_prefix'], $existing > 0 && !empty($answers['drop_existing']));
if (!$import['ok']) {
    fail($import['message']);
}
out('  ✓ ' . $import['message']);

// ------------------------------------------------------------------ secrets

$answers['app_key'] = Crypto::generateAppKey();
$answers['backup_key'] = Crypto::generateAppKey();
$answers['health_token'] = Crypto::randomToken(16);
$answers['coordinator_secret'] = Crypto::randomToken(32);
$answers['admin_password_hash'] = Crypto::hashPassword((string) $answers['admin_password']);

if (function_exists('sodium_crypto_sign_keypair')) {
    $keypair = Crypto::generateSigningKeypair();
    $answers['controller_public_key'] = $keypair['public'];
    $answers['controller_secret_key'] = $keypair['secret'];
} else {
    $answers['controller_public_key'] = '';
    $answers['controller_secret_key'] = '';
    out('  ! ext-sodium is missing — no controller signing keypair was generated.');
}

App\Core\Config::set('app.key', $answers['app_key']);

// ------------------------------------------------------------ admin account

out('Creating the administrator…');
$prefix = (string) $answers['db_prefix'];

$twofaSecret = null;
$recovery = null;
if (!empty($answers['enable_2fa'])) {
    $plainSecret = Totp::generateSecret();
    $twofaSecret = Crypto::encrypt($plainSecret);
    $recovery = Totp::generateRecoveryCodes(8);
    $answers['twofa_secret'] = $plainSecret;
}

DB::execute(
    'INSERT INTO ' . $prefix . 'users
        (tenant_id, name, email, password_hash, role, status, twofa_secret, twofa_enabled,
         twofa_recovery_json, timezone, password_changed_at, created_at, updated_at)
     VALUES
        (NULL, :name, :email, :hash, \'super_admin\', \'active\', :secret, :enabled,
         :recovery, :tz, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE
        name = VALUES(name), password_hash = VALUES(password_hash), role = \'super_admin\',
        status = \'active\', updated_at = UTC_TIMESTAMP()',
    [
        'name'     => $answers['admin_name'],
        'email'    => strtolower((string) $answers['admin_email']),
        'hash'     => $answers['admin_password_hash'],
        'secret'   => $twofaSecret,
        'enabled'  => $twofaSecret !== null ? 1 : 0,
        'recovery' => $recovery !== null ? json_encode($recovery['hashes']) : null,
        'tz'       => $answers['timezone'],
    ]
);
out('  ✓ ' . $answers['admin_email']);

if (($answers['gh_owner'] ?? '') !== '' && ($answers['gh_repo'] ?? '') !== '') {
    DB::execute(
        'INSERT INTO ' . $prefix . 'update_settings (id, repo_owner, repo_name, branch, token_encrypted, created_at, updated_at)
         VALUES (1, :owner, :repo, :branch, :token, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            repo_owner = VALUES(repo_owner), repo_name = VALUES(repo_name),
            branch = VALUES(branch), token_encrypted = VALUES(token_encrypted), updated_at = UTC_TIMESTAMP()',
        [
            'owner'  => $answers['gh_owner'],
            'repo'   => $answers['gh_repo'],
            'branch' => $answers['gh_branch'] ?? 'main',
            'token'  => ($answers['gh_token'] ?? '') !== '' ? Crypto::encrypt((string) $answers['gh_token']) : null,
        ]
    );
    out('  ✓ GitHub updates configured for ' . $answers['gh_owner'] . '/' . $answers['gh_repo']);
}

// ------------------------------------------------------------------ config

out('Writing the configuration…');
$installer->writeConfig($answers);
$installer->writeEnv($answers);
$installer->lock();
$installer->writeLog();
out('  ✓ config/config.php, .env, install/install.lock');

out('');
out('Installation complete.');
out('');
out('  Sign in at : ' . rtrim((string) $answers['app_url'], '/') . '/login');
out('  Username   : ' . $answers['admin_email']);
out('');

if ($recovery !== null) {
    out('  Two-factor recovery codes (shown once — save them now):');
    foreach (array_chunk($recovery['plain'], 4) as $chunk) {
        out('    ' . implode('   ', $chunk));
    }
    out('');
    out('  Authenticator key: ' . chunk_split((string) $answers['twofa_secret'], 4, ' '));
    out('');
}

$worker = function_exists('posix_getpwuid') ? (string) (posix_getpwuid((int) @fileowner(APP_ROOT))['name'] ?? '') : '';
out('  The five-minute worker runs as the user that owns the panel\'s files'
    . ($worker !== '' ? ' (' . $worker . ')' : '') . ', never as root:');
out('    crontab -u ' . ($worker !== '' ? $worker : '<that user>') . ' -e');
out('    ' . $installer->crontabLine());
out('  A server set up by deploy/getting-started.sh already has it, as akconnect-worker.timer —');
out('  do not add it a second time.');
out('');
out('  Leave the install/ folder where it is. It locks itself, and deleting it takes');
out('  the lock with it — which is how a production panel once served this wizard again.');
out('');

exit(0);
