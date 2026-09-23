<?php

declare(strict_types=1);

/**
 * Does this build's Argon2 defeat the installer?
 *
 * Run inside the sodium-Argon2 container against the repository mounted at
 * /app. It loads the real Crypto class — not a copy of its logic — because the
 * defect was in that class and a test of a reimplementation proves nothing.
 */

define('APP_ROOT', '/app');

require APP_ROOT . '/app/Core/Autoloader.php';
$autoloader = new App\Core\Autoloader();
$autoloader->addNamespace('App', APP_ROOT . '/app');
$autoloader->register();

App\Core\Logger::configure(sys_get_temp_dir(), 'error');

$pass = 0;
$fail = 0;

function check(bool $ok, string $name, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        printf("  \033[32m✓\033[0m %s%s\n", $name, $detail === '' ? '' : '  ' . $detail);
    } else {
        $fail++;
        printf("  \033[31m✗\033[0m %s%s\n", $name, $detail === '' ? '' : "\n      " . $detail);
    }
}

$provider = defined('PASSWORD_ARGON2_PROVIDER') ? PASSWORD_ARGON2_PROVIDER : 'none';

printf("\n  \033[1mPHP %s, Argon2 provider: %s\033[0m\n\n", PHP_VERSION, $provider);

// The target only means anything if it is the build we set out to make.
check($provider === 'sodium', 'this build takes its Argon2 from libsodium', 'provider=' . $provider);

// The exact call the production box made, with the parameters 1.9.0 used.
$threw = null;

try {
    password_hash('Installer!Pass2026', PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost'   => 4,
        'threads'     => 2,
    ]);
} catch (\Throwable $e) {
    $threw = $e;
}

check(
    $threw !== null,
    'threads => 2 is refused by this build, as it was in production',
    $threw !== null ? $threw::class . ': ' . $threw->getMessage() : 'it was accepted — this is not the failing build'
);

// And the fix: the real class, on the build that broke.
$hash = '';
$error = null;

try {
    $hash = App\Core\Crypto::hashPassword('Installer!Pass2026');
} catch (\Throwable $e) {
    $error = $e;
}

check($error === null, 'Crypto::hashPassword survives this build',
    $error !== null ? $error::class . ': ' . $error->getMessage() : '');
check($hash !== '' && App\Core\Crypto::verifyPassword('Installer!Pass2026', $hash), 'and the hash verifies');
check($hash !== '' && !App\Core\Crypto::verifyPassword('wrong', $hash), 'and a wrong password does not');
check($hash !== '' && !App\Core\Crypto::passwordNeedsRehash($hash),
    'and it does not want rehashing, which would throw on every login', substr($hash, 0, 30) . '…');

printf("\n  ────────────────────────────────────────────────────────────\n");
printf("  %d passed, %d failed\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
