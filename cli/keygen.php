#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate signing keys.
 *
 *   php cli/keygen.php --controller   controller identity (signs agent config)
 *   php cli/keygen.php --update       manifest signing key for releases
 *   php cli/keygen.php --sign=update.json --key=<hex secret>
 *
 * The secret half is printed once and never stored anywhere by this script.
 */

require __DIR__ . '/_bootstrap.php';

use App\Core\Crypto;
use App\Updater\Manifest;

$options = cli_options($argv);

if (!function_exists('sodium_crypto_sign_keypair')) {
    cli_fail('ext-sodium is required for signing. Install it and try again.');
    exit(1);
}

if (isset($options['sign'])) {
    $file = (string) $options['sign'];
    $secret = (string) ($options['key'] ?? '');

    if (!is_file($file)) {
        cli_fail('File not found: ' . $file);
        exit(1);
    }
    if ($secret === '') {
        cli_fail('Provide the signing secret with --key=<hex>');
        exit(1);
    }

    $manifest = Manifest::fromJson((string) file_get_contents($file));
    $signature = Crypto::sign($manifest->canonicalPayload(), $secret);

    $data = $manifest->toArray();
    $data['signature'] = $signature;

    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    cli_ok('Signed ' . $file);
    cli_out('  ' . $signature);
    cli_out('');
    cli_out('Verify with the public half by enabling "Require a signed manifest" under System → Updates.');
    exit(0);
}

$purpose = isset($options['update']) ? 'update manifest' : 'controller identity';
$configKey = isset($options['update']) ? 'security.update_public_key' : 'security.controller_public_key';

$keypair = Crypto::generateSigningKeypair();

cli_heading('New ed25519 keypair for the ' . $purpose);
cli_out('');
cli_out(cli_colour('  Public key (safe to distribute):', 'grey'));
cli_out('    ' . $keypair['public']);
cli_out('');
cli_out(cli_colour('  Secret key (shown once — store it in a password manager):', 'grey'));
cli_out('    ' . $keypair['secret']);
cli_out('');
cli_out('Add the public half to config/config.php:');
cli_out('');
cli_out(cli_colour(sprintf("    '%s' => '%s',", str_replace('security.', '', $configKey), $keypair['public']), 'blue'));
cli_out('');

if (isset($options['controller'])) {
    cli_out('And the secret half under coordinator.signing_key, so the panel can sign agent configuration:');
    cli_out('');
    cli_out(cli_colour(sprintf("    'signing_key' => '%s',", $keypair['secret']), 'blue'));
    cli_out('');
    cli_warn('Rotating this key invalidates every agent\'s cached configuration signature.');
    cli_out(cli_colour('  Agents will re-fetch on their next poll, so do it during a quiet period.', 'grey'));
} else {
    cli_out('Keep the secret half on the machine that builds releases, and sign each update.json with:');
    cli_out('');
    cli_out(cli_colour('    php cli/keygen.php --sign=update.json --key=<secret>', 'blue'));
}

cli_out('');
exit(0);
