<?php

declare(strict_types=1);

/**
 * Installation wizard.
 *
 * Self-contained by necessity: it runs before config/config.php exists, so it
 * cannot boot the application. It loads only the Core classes it needs and
 * keeps its answers in a session on disk (not the database, which may not
 * exist yet).
 *
 * Once install/install.lock exists this file refuses to do anything except say
 * so — re-running an installer against a live system is how people lose their
 * data.
 */

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/app/Core/Autoloader.php';
$autoloader = new App\Core\Autoloader();
$autoloader->addNamespace('App', APP_ROOT . '/app');
$autoloader->addNamespace('Install', APP_ROOT . '/install');
$autoloader->register();

use App\Core\Crypto;
use App\Core\Totp;
use Install\Installer;

$installer = new Installer(APP_ROOT);

// ---------------------------------------------------------------- lock gate

if ($installer->isLocked()) {
    http_response_code(403);
    render_shell('Already installed', <<<HTML
        <div class="alert alert-error">
            <span class="alert-icon">✕</span>
            <div>
                <strong>This installation is already complete.</strong>
                <p>Running the installer again could destroy live data, so it is disabled.</p>
            </div>
        </div>
        <p>If you genuinely need to reinstall:</p>
        <ol class="steps">
            <li>Take a backup of your database and of <code>config/config.php</code>.</li>
            <li>Delete <code>install/install.lock</code>.</li>
            <li>Delete <code>config/config.php</code>.</li>
            <li>Reload this page.</li>
        </ol>
        <p class="hint">The safest next step is usually to <a href="../">sign in</a> instead.</p>
        HTML);
    exit;
}

// Installer sessions are file-based on purpose: the database may not exist yet.
session_name('ak_install');
session_start();

$step = (string) ($_GET['step'] ?? 'welcome');
if (!in_array($step, Installer::STEPS, true)) {
    $step = 'welcome';
}

$answers = $_SESSION['install'] ?? [];
$errors = [];
$notice = null;

// -------------------------------------------------------------------- CSRF

if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['install_csrf'];

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost && !hash_equals($csrf, (string) ($_POST['_token'] ?? ''))) {
    http_response_code(403);
    render_shell('Session expired', '<div class="alert alert-error"><span class="alert-icon">✕</span>'
        . '<span>Your session expired. <a href="?step=' . htmlspecialchars($step, ENT_QUOTES) . '">Start this step again</a>.</span></div>');
    exit;
}

// -------------------------------------------------------------- AJAX actions

if ($isPost && isset($_POST['_action'])) {
    header('Content-Type: application/json');

    $action = (string) $_POST['_action'];

    if ($action === 'test_db') {
        echo json_encode($installer->testDatabase(
            trim((string) ($_POST['db_host'] ?? '127.0.0.1')),
            (int) ($_POST['db_port'] ?? 3306),
            trim((string) ($_POST['db_name'] ?? '')),
            trim((string) ($_POST['db_user'] ?? '')),
            (string) ($_POST['db_pass'] ?? ''),
            trim((string) ($_POST['db_prefix'] ?? ''))
        ));
        exit;
    }

    if ($action === 'test_mail') {
        // Configure just enough for the mailer; nothing is persisted yet.
        App\Core\Config::set('mail.host', trim((string) ($_POST['mail_host'] ?? '')));
        App\Core\Config::set('mail.port', (int) ($_POST['mail_port'] ?? 587));
        App\Core\Config::set('mail.user', trim((string) ($_POST['mail_user'] ?? '')));
        App\Core\Config::set('mail.pass', (string) ($_POST['mail_pass'] ?? ''));
        App\Core\Config::set('mail.security', (string) ($_POST['mail_security'] ?? 'tls'));
        App\Core\Config::set('mail.from', trim((string) ($_POST['mail_from'] ?? '')));
        App\Core\Config::set('brand.name', (string) ($answers['site_name'] ?? 'Panel'));
        App\Core\Config::set('app.domain', (string) parse_url((string) ($answers['app_url'] ?? ''), PHP_URL_HOST));

        $result = App\Core\Mailer::fromConfig()->sendTest(trim((string) ($_POST['test_to'] ?? '')));
        echo json_encode([
            'ok'      => $result['success'],
            'message' => $result['success']
                ? 'Test email sent. Check the inbox and the spam folder.'
                : $result['error'],
        ]);
        exit;
    }

    if ($action === 'test_github') {
        App\Core\Config::set('app.key', (string) ($answers['app_key'] ?? Crypto::generateAppKey()));
        $client = new App\Updater\GithubClient(
            trim((string) ($_POST['gh_owner'] ?? '')),
            trim((string) ($_POST['gh_repo'] ?? '')),
            trim((string) ($_POST['gh_token'] ?? ''))
        );
        echo json_encode($client->testConnection(trim((string) ($_POST['gh_branch'] ?? 'main')) ?: 'main'));
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
    exit;
}

// ------------------------------------------------------------- step handling

if ($isPost) {
    switch ($step) {
        case 'requirements':
            $check = $installer->checkRequirements();
            if (!$check['ok']) {
                $errors[] = 'Some required checks are still failing. Fix them and re-check.';
                break;
            }
            redirect('database');
            // no break — redirect() exits

        case 'database':
            $answers['db_host'] = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
            $answers['db_port'] = (int) ($_POST['db_port'] ?? 3306);
            $answers['db_name'] = trim((string) ($_POST['db_name'] ?? ''));
            $answers['db_user'] = trim((string) ($_POST['db_user'] ?? ''));
            $answers['db_pass'] = (string) ($_POST['db_pass'] ?? '');
            $answers['db_prefix'] = preg_replace('/[^a-z0-9_]/i', '', (string) ($_POST['db_prefix'] ?? '')) ?? '';

            if ($answers['db_name'] === '' || $answers['db_user'] === '') {
                $errors[] = 'Database name and username are required.';
                break;
            }

            $test = $installer->testDatabase(
                $answers['db_host'], $answers['db_port'], $answers['db_name'],
                $answers['db_user'], $answers['db_pass'], $answers['db_prefix']
            );

            if (!$test['ok']) {
                $errors[] = $test['message'];
                break;
            }

            $existing = (int) ($test['existing_tables'] ?? 0);
            $confirmation = trim((string) ($_POST['drop_confirm'] ?? ''));

            if ($existing > 0 && $confirmation !== 'DROP') {
                $errors[] = sprintf(
                    'This database already contains %d table(s). Choose an empty database, or type DROP in the '
                    . 'confirmation box to drop them and reinstall. Dropping cannot be undone.',
                    $existing
                );
                $answers['_existing_tables'] = $existing;
                break;
            }

            try {
                $pdo = App\Core\DB::connectWith(
                    $answers['db_host'], $answers['db_port'], $answers['db_name'],
                    $answers['db_user'], $answers['db_pass'], $answers['db_prefix']
                );
                App\Core\DB::setConnection($pdo, $answers['db_prefix']);

                $import = $installer->importSchema($pdo, $answers['db_prefix'], $existing > 0 && $confirmation === 'DROP');
                if (!$import['ok']) {
                    $errors[] = $import['message'];
                    break;
                }

                $answers['_tables'] = $import['tables'];
                $_SESSION['install'] = $answers;
                redirect('admin');
            } catch (Throwable $e) {
                $errors[] = 'Import failed: ' . $e->getMessage();
            }
            break;

        case 'admin':
            $answers['admin_name'] = trim((string) ($_POST['admin_name'] ?? ''));
            $answers['admin_email'] = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
            $password = (string) ($_POST['admin_password'] ?? '');
            $confirm = (string) ($_POST['admin_password_confirmation'] ?? '');
            $answers['enable_2fa'] = !empty($_POST['enable_2fa']);

            if ($answers['admin_name'] === '') {
                $errors[] = 'Your name is required.';
            }
            if (filter_var($answers['admin_email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'A valid email address is required — it is your sign-in name.';
            }
            if (strlen($password) < 10) {
                $errors[] = 'The password must be at least 10 characters.';
            }
            $classes = preg_match('/[a-z]/', $password) + preg_match('/[A-Z]/', $password)
                + preg_match('/\d/', $password) + preg_match('/[^a-zA-Z0-9]/', $password);
            if ($classes < 3) {
                $errors[] = 'The password must mix upper case, lower case, numbers and symbols.';
            }
            if ($password !== $confirm) {
                $errors[] = 'The two passwords do not match.';
            }

            if ($errors === []) {
                // Hashed immediately; the plaintext never reaches the session.
                $answers['admin_password_hash'] = Crypto::hashPassword($password);

                if ($answers['enable_2fa'] && empty($answers['twofa_secret'])) {
                    $answers['twofa_secret'] = Totp::generateSecret();
                }

                $_SESSION['install'] = $answers;
                redirect('configuration');
            }
            break;

        case 'configuration':
            $answers['site_name'] = trim((string) ($_POST['site_name'] ?? 'AK Connect'));
            $answers['org_name'] = trim((string) ($_POST['org_name'] ?? ''));
            $answers['app_url'] = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');
            $answers['timezone'] = (string) ($_POST['timezone'] ?? 'Asia/Kolkata');
            $answers['support_email'] = trim((string) ($_POST['support_email'] ?? ''));
            $answers['mail_host'] = trim((string) ($_POST['mail_host'] ?? ''));
            $answers['mail_port'] = (int) ($_POST['mail_port'] ?? 587);
            $answers['mail_user'] = trim((string) ($_POST['mail_user'] ?? ''));
            $answers['mail_pass'] = (string) ($_POST['mail_pass'] ?? '');
            $answers['mail_security'] = (string) ($_POST['mail_security'] ?? 'tls');
            $answers['mail_from'] = trim((string) ($_POST['mail_from'] ?? ''));
            $answers['coordinator_host'] = trim((string) ($_POST['coordinator_host'] ?? '127.0.0.1'));
            $answers['coordinator_port'] = (int) ($_POST['coordinator_port'] ?? 8443);
            $answers['gh_owner'] = trim((string) ($_POST['gh_owner'] ?? ''));
            $answers['gh_repo'] = trim((string) ($_POST['gh_repo'] ?? ''));
            $answers['gh_branch'] = trim((string) ($_POST['gh_branch'] ?? 'main')) ?: 'main';
            $answers['gh_token'] = (string) ($_POST['gh_token'] ?? '');

            if (filter_var($answers['app_url'], FILTER_VALIDATE_URL) === false) {
                $errors[] = 'The panel URL must be a full address, e.g. https://net.example.com';
            }
            if (!in_array($answers['timezone'], DateTimeZone::listIdentifiers(), true)) {
                $errors[] = 'Choose a valid timezone.';
            }
            if ($answers['support_email'] !== '' && filter_var($answers['support_email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'The support email address is not valid.';
            }

            if ($errors === []) {
                $_SESSION['install'] = $answers;
                redirect('finish');
            }
            break;

        case 'finish':
            try {
                finish_installation($installer, $answers);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
            break;
    }

    $_SESSION['install'] = $answers;
}

// A GET on `finish` means the install already completed and the lock is set;
// that is handled by the lock gate at the top, so anything reaching here is
// still mid-wizard.
render_step($installer, $step, $answers, $errors, $notice, $csrf);

// ---------------------------------------------------------------- functions

/**
 * Create the admin account, write the config, and lock the installer.
 *
 * Deliberately ordered so the riskiest steps happen while a failure is still
 * recoverable: the account is created first (the database is already proven to
 * work), config last, lock last of all.
 *
 * @param array<string,mixed> $answers
 */
function finish_installation(Installer $installer, array &$answers): void
{
    // Secrets are generated here, not earlier, so a half-finished wizard never
    // leaves a usable key lying in a session file.
    $answers['app_key'] = Crypto::generateAppKey();
    $answers['backup_key'] = Crypto::generateAppKey();
    $answers['health_token'] = Crypto::randomToken(16);
    $answers['coordinator_secret'] = Crypto::randomToken(32);

    if (function_exists('sodium_crypto_sign_keypair')) {
        $keypair = Crypto::generateSigningKeypair();
        $answers['controller_public_key'] = $keypair['public'];
        $answers['controller_secret_key'] = $keypair['secret'];
    } else {
        $answers['controller_public_key'] = '';
        $answers['controller_secret_key'] = '';
    }

    $pdo = App\Core\DB::connectWith(
        (string) $answers['db_host'], (int) $answers['db_port'], (string) $answers['db_name'],
        (string) $answers['db_user'], (string) $answers['db_pass'], (string) ($answers['db_prefix'] ?? '')
    );
    App\Core\DB::setConnection($pdo, (string) ($answers['db_prefix'] ?? ''));
    App\Core\Config::set('app.key', $answers['app_key']);

    $prefix = (string) ($answers['db_prefix'] ?? '');

    // Idempotent: re-running after a partial failure updates rather than
    // creating a second super admin.
    // @sql-identifier The table prefix is part of the identifier and cannot be
    // bound. It is sanitised to [A-Za-z0-9_] when the answer is accepted.
    $existing = App\Core\DB::selectOne(
        'SELECT id FROM ' . $prefix . 'users WHERE email = :email LIMIT 1',
        ['email' => $answers['admin_email']]
    );

    $twofaSecret = null;
    $recoveryCodes = null;
    if (!empty($answers['enable_2fa']) && !empty($answers['twofa_secret'])) {
        $twofaSecret = Crypto::encrypt((string) $answers['twofa_secret']);
        $recovery = Totp::generateRecoveryCodes(8);
        $recoveryCodes = $recovery;
    }

    if ($existing === null) {
        App\Core\DB::execute(
            'INSERT INTO ' . $prefix . 'users
                (tenant_id, name, email, password_hash, role, status, twofa_secret, twofa_enabled,
                 twofa_recovery_json, timezone, password_changed_at, created_at, updated_at)
             VALUES
                (NULL, :name, :email, :hash, \'super_admin\', \'active\', :secret, :enabled,
                 :recovery, :tz, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'name'     => $answers['admin_name'],
                'email'    => $answers['admin_email'],
                'hash'     => $answers['admin_password_hash'],
                'secret'   => $twofaSecret,
                'enabled'  => $twofaSecret !== null ? 1 : 0,
                'recovery' => $recoveryCodes !== null ? json_encode($recoveryCodes['hashes']) : null,
                'tz'       => $answers['timezone'],
            ]
        );
    } else {
        App\Core\DB::execute(
            'UPDATE ' . $prefix . 'users
             SET name = :name, password_hash = :hash, role = \'super_admin\', status = \'active\',
                 twofa_secret = :secret, twofa_enabled = :enabled, twofa_recovery_json = :recovery,
                 timezone = :tz, updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            [
                'id'       => $existing['id'],
                'name'     => $answers['admin_name'],
                'hash'     => $answers['admin_password_hash'],
                'secret'   => $twofaSecret,
                'enabled'  => $twofaSecret !== null ? 1 : 0,
                'recovery' => $recoveryCodes !== null ? json_encode($recoveryCodes['hashes']) : null,
                'tz'       => $answers['timezone'],
            ]
        );
    }

    // Optional GitHub updater settings.
    if (($answers['gh_owner'] ?? '') !== '' && ($answers['gh_repo'] ?? '') !== '') {
        App\Core\DB::execute(
            'INSERT INTO ' . $prefix . 'update_settings (id, repo_owner, repo_name, branch, token_encrypted, created_at, updated_at)
             VALUES (1, :owner, :repo, :branch, :token, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                repo_owner = VALUES(repo_owner), repo_name = VALUES(repo_name),
                branch = VALUES(branch), token_encrypted = VALUES(token_encrypted), updated_at = UTC_TIMESTAMP()',
            [
                'owner'  => $answers['gh_owner'],
                'repo'   => $answers['gh_repo'],
                'branch' => $answers['gh_branch'],
                'token'  => ($answers['gh_token'] ?? '') !== '' ? Crypto::encrypt((string) $answers['gh_token']) : null,
            ]
        );
    }

    $installer->writeConfig($answers);
    $installer->writeEnv($answers);
    $installer->lock();
    $installer->writeLog();

    // Keep only what the success screen needs; drop every secret.
    $_SESSION['install_complete'] = [
        'admin_email'   => $answers['admin_email'],
        'crontab'       => $installer->crontabLine(),
        'app_url'       => $answers['app_url'],
        'recovery'      => $recoveryCodes['plain'] ?? null,
        'twofa_uri'     => $twofaSecret !== null
            ? Totp::provisioningUri((string) $answers['twofa_secret'], (string) $answers['admin_email'], (string) $answers['site_name'])
            : null,
        'twofa_secret'  => $answers['twofa_secret'] ?? null,
        'signing_ready' => ($answers['controller_public_key'] ?? '') !== '',
    ];
    unset($_SESSION['install']);

    header('Location: ?step=finish&done=1', true, 302);
    exit;
}

function redirect(string $step): never
{
    header('Location: ?step=' . rawurlencode($step), true, 302);
    exit;
}

function render_shell(string $title, string $body): void
{
    require __DIR__ . '/views/shell.php';
}

/**
 * @param array<string,mixed> $answers
 * @param list<string> $errors
 */
function render_step(Installer $installer, string $step, array $answers, array $errors, ?string $notice, string $csrf): void
{
    $view = __DIR__ . '/views/' . $step . '.php';
    if (!is_file($view)) {
        $view = __DIR__ . '/views/welcome.php';
    }

    ob_start();
    require $view;
    $body = (string) ob_get_clean();

    $title = ucfirst($step);
    require __DIR__ . '/views/shell.php';
}

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function step_index(string $step): int
{
    $index = array_search($step, Installer::STEPS, true);

    return $index === false ? 0 : (int) $index;
}
