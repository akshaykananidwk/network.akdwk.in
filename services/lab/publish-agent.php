#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Publish an agent binary the way the panel does, for the self-update gate.
 *
 * deploy/upgrade-edge.sh publishes Windows binaries through the signed edge
 * upload. This does the same job for a platform the lab can actually execute,
 * so the swap can be watched end to end on Linux instead of reasoned about.
 *
 *   publish-agent.php <version> <file> <platform> <arch> [--tamper=sig|digest|none]
 *   publish-agent.php --withdraw <version>
 *
 * --tamper is the point of the gate as much as the happy path is: an agent
 * that installs a binary whose signature does not verify is a remote code
 * execution path into every customer machine, so the drill proves the refusal
 * as well as the install.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('APP_ROOT', dirname(__DIR__, 2));
require APP_ROOT . '/app/Core/Autoloader.php';
$autoloader = new App\Core\Autoloader();
$autoloader->addNamespace('App', APP_ROOT . '/app');
$autoloader->register();
require APP_ROOT . '/app/Core/helpers.php';
App\Core\Config::load(APP_ROOT . '/config/config.php');

use App\Core\Crypto;
use App\Core\DB;
use App\Models\AgentRelease;
use App\Services\CoordinatorSettings;

$args = array_slice($argv, 1);

if (($args[0] ?? '') === '--withdraw') {
    $version = (string) ($args[1] ?? '');
    if ($version === '') {
        fwrite(STDERR, "--withdraw needs a version\n");
        exit(2);
    }

    foreach (DB::select(
        'SELECT file_path FROM ' . DB::table('agent_releases') . ' WHERE version = :v',
        ['v' => $version]
    ) as $row) {
        $path = APP_ROOT . '/' . (string) $row['file_path'];
        if (is_file($path) && str_starts_with($path, APP_ROOT . '/storage/downloads/')) {
            @unlink($path);
        }
    }

    DB::execute('DELETE FROM ' . DB::table('agent_releases') . ' WHERE version = :v', ['v' => $version]);
    echo "withdrawn $version\n";
    exit(0);
}

[$version, $source, $platform, $arch] = [
    (string) ($args[0] ?? ''),
    (string) ($args[1] ?? ''),
    (string) ($args[2] ?? 'linux'),
    (string) ($args[3] ?? 'amd64'),
];

$tamper = 'none';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--tamper=')) {
        $tamper = substr($arg, strlen('--tamper='));
    }
}

if ($version === '' || !is_file($source)) {
    fwrite(STDERR, "usage: publish-agent.php <version> <file> [platform] [arch] [--tamper=sig|digest]\n");
    exit(2);
}

$dir = APP_ROOT . '/storage/downloads';
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    fwrite(STDERR, "cannot create $dir\n");
    exit(1);
}

$filename = sprintf('akconnect-agent-%s-%s-%s', $platform, $arch, $version);
$target = $dir . '/' . $filename;

if (!@copy($source, $target)) {
    fwrite(STDERR, "cannot copy $source to $target\n");
    exit(1);
}

$digest = (string) hash_file('sha256', $target);

// The signature is over the digest, which is what the agent checks — see
// services/agent/internal/selfupdate/verify.go.
$secret = (string) CoordinatorSettings::current()['signing_key'];
if ($secret === '') {
    fwrite(STDERR, "this panel has no controller signing key; nothing could be signed\n");
    exit(1);
}

$signature = Crypto::sign($digest, $secret);

if ($tamper === 'sig') {
    // A signature from a key that is not this panel's: the exact shape of a
    // release published by somebody who took the panel but not the key.
    $keypair = Crypto::generateSigningKeypair();
    $signature = Crypto::sign($digest, $keypair['secret']);
}

$published = $digest;
if ($tamper === 'digest') {
    // The panel advertises a digest the file does not have.
    $published = str_repeat('a', 64);
    $signature = Crypto::sign($published, $secret);
}

DB::execute('DELETE FROM ' . DB::table('agent_releases') . ' WHERE version = :v', ['v' => $version]);

AgentRelease::create([
    'version'         => $version,
    'channel'         => 'stable',
    'platform'        => $platform,
    'arch'            => $arch,
    'file_path'       => 'storage/downloads/' . $filename,
    'file_size'       => (int) filesize($target),
    'sha256'          => $published,
    'signature'       => $signature,
    'release_notes'   => 'Published by the self-update gate.',
    'rollout_percent' => 100,
    'published_at'    => gmdate('Y-m-d H:i:s'),
]);

printf("published %s %s/%s sha256=%s tamper=%s\n", $version, $platform, $arch, substr($published, 0, 16), $tamper);
