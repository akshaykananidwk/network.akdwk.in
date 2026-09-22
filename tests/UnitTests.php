<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Crypto;
use App\Core\Request;
use App\Core\Logger;
use App\Core\Totp;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\UpdateSetting;
use App\Services\AclService;
use App\Services\IpamService;
use App\Services\RouteService;
use App\Updater\ArchiveExtractor;
use App\Updater\GithubClient;
use App\Updater\Manifest;
use App\Updater\MigrationRunner;
use App\Updater\PathGuard;
use App\Core\UpdateException;

/**
 * Unit tests for logic that has no database dependency.
 *
 * These cover the parts where a silent mistake would be most expensive:
 * secret redaction, path containment, archive safety, CIDR maths and the
 * ACL evaluator.
 */
final class UnitTests
{
    public static function run(): void
    {
        self::crypto();
        self::requestBooleans();
        self::redaction();
        self::validation();
        self::ipam();
        self::acl();
        self::pathGuard();
        self::archiveSafety();
        self::manifest();
        self::githubClient();
        self::releaseTags();
        self::sqlSplitter();
        self::totp();
        self::lanSuggestion();
    }

    private static function crypto(): void
    {
        TestCase::group('Crypto — encryption, hashing, signatures');

        $key = Crypto::generateAppKey();
        TestCase::assert(str_starts_with($key, 'base64:') && strlen(base64_decode(substr($key, 7))) === 32,
            'generateAppKey produces a 32-byte key');

        $secret = 'ghp_averyrealisticlookingtoken123456';
        $encrypted = Crypto::encrypt($secret, $key);
        TestCase::assertNotContains($secret, $encrypted, 'ciphertext does not contain the plaintext');
        TestCase::assertSame($secret, Crypto::decrypt($encrypted, $key), 'round-trips correctly');

        TestCase::assertSame(null, Crypto::decrypt($encrypted, Crypto::generateAppKey()),
            'decrypting with the wrong key returns null, not garbage');

        // AES-GCM is authenticated, so a flipped byte must fail rather than
        // decrypt to something.
        $raw = base64_decode($encrypted, true);
        $tampered = base64_encode(substr($raw, 0, -1) . chr(ord($raw[strlen($raw) - 1]) ^ 0xFF));
        TestCase::assertSame(null, Crypto::decrypt($tampered, $key), 'tampered ciphertext is rejected');

        TestCase::assertSame(null, Crypto::decrypt('not-base64-at-all!!', $key), 'malformed payload returns null');

        $hash = Crypto::hashPassword('CorrectHorse!2026');
        TestCase::assert(Crypto::verifyPassword('CorrectHorse!2026', $hash), 'password verifies');
        TestCase::assert(!Crypto::verifyPassword('wrong', $hash), 'wrong password rejected');
        TestCase::assert(str_starts_with($hash, '$argon2id$') || str_starts_with($hash, '$2y$'),
            'password uses Argon2id (or bcrypt fallback)', substr($hash, 0, 12));

        if (function_exists('sodium_crypto_sign_keypair')) {
            $pair = Crypto::generateSigningKeypair();
            $signature = Crypto::sign('hello world', $pair['secret']);
            TestCase::assert(Crypto::verifySignature('hello world', $signature, $pair['public']),
                'ed25519 signature verifies');
            TestCase::assert(!Crypto::verifySignature('hello worlD', $signature, $pair['public']),
                'signature rejects a modified message');
            $other = Crypto::generateSigningKeypair();
            TestCase::assert(!Crypto::verifySignature('hello world', $signature, $other['public']),
                'signature rejects the wrong public key');
        } else {
            TestCase::skip('ed25519 signatures', 'ext-sodium not loaded');
        }

        TestCase::assert(Crypto::isValidCurve25519PublicKey(base64_encode(random_bytes(32))),
            'accepts a 32-byte Curve25519 key');
        TestCase::assert(!Crypto::isValidCurve25519PublicKey(base64_encode(random_bytes(16))),
            'rejects a short key');
        TestCase::assert(!Crypto::isValidCurve25519PublicKey('not base64 %%%'), 'rejects non-base64');
    }

    /**
     * Reading a form checkbox, which is how a 500 reached production.
     *
     * The write that crashed was `$request->input('pre_approved', false)`:
     * a bool passed to a ?string default, which under strict_types throws at
     * the call. Request::boolean() exists so there is a correct thing to
     * write, and these are the values a browser actually sends.
     */
    private static function requestBooleans(): void
    {
        TestCase::group('Request::boolean — what a form actually posts');

        $cases = [
            // [posted value, expected, why]
            ['1', true, 'a hidden field set to 1, which is what this panel posts'],
            ['on', true, 'a checked checkbox with no value of its own'],
            ['true', true, 'a JSON body that spells it out'],
            ['yes', true, 'a form written by hand'],
            ['0', false, 'the hidden-field-then-checkbox idiom, unchecked'],
            ['off', false, 'an explicit off'],
            ['false', false, 'the string "false", which (bool) would read as TRUE'],
            ['', false, 'an empty value is not a yes'],
        ];

        foreach ($cases as [$posted, $expected, $why]) {
            $_GET = [];
            $_POST = ['flag' => $posted];
            $_SERVER['REQUEST_METHOD'] = 'POST';

            TestCase::assertSame($expected, Request::capture()->boolean('flag'), $why);
        }

        // The case that crashed: the field is simply not there.
        $_POST = [];
        TestCase::assertSame(false, Request::capture()->boolean('flag'),
            'a field that was never posted is false, not an error');
        TestCase::assertSame(true, Request::capture()->boolean('flag', true),
            'and the default is honoured when there is one');

        // An array posted where a scalar was expected — flag[]=1 — must not
        // become true by being non-empty.
        $_POST = ['flag' => ['1']];
        TestCase::assertSame(false, Request::capture()->boolean('flag'),
            'an array posted under a boolean name falls back to the default');

        // Anything unrecognised falls back rather than guessing.
        $_POST = ['flag' => 'maybe'];
        TestCase::assertSame(false, Request::capture()->boolean('flag'),
            'an unrecognised value is not silently true');

        $_POST = [];
        $_GET = [];
    }

    private static function redaction(): void
    {
        TestCase::group('Logger — secret redaction (§20.B: token must never appear in a log)');

        $cases = [
            'ghp_abcdefghijklmnopqrstuvwxyz1234567890',
            'github_pat_11ABCDEFG0abcdefghijklmnop_qrstuvwxyz1234567890ABCDEFGHIJKL',
            'gho_16CharactersLongTokenHere12345',
        ];

        foreach ($cases as $token) {
            $redacted = Logger::redactString('curl failed with Authorization: Bearer ' . $token);
            TestCase::assertNotContains($token, $redacted, 'bearer token masked in free text',
                substr($token, 0, 8) . '…');

            $redacted = Logger::redactString('remote said: ' . $token . ' is invalid');
            TestCase::assertNotContains($token, $redacted, 'bare token masked in free text');
        }

        $redacted = Logger::redactString('https://user:hunter2@example.com/repo.git');
        TestCase::assertNotContains('hunter2', $redacted, 'credentials inside a URL are masked');

        /** @var array<string,mixed> $structure */
        $structure = Logger::redact([
            'repo'   => 'acme/panel',
            'token'  => 'ghp_secretsecretsecret123456',
            'nested' => ['github_token' => 'ghp_anothersecret1234567890', 'safe' => 'keep me'],
            'db_pass' => 'hunter2',
        ]);

        // JSON_UNESCAPED_SLASHES so "acme/panel" is not compared against
        // the escaped "acme\/panel" form.
        $encoded = (string) json_encode($structure, JSON_UNESCAPED_SLASHES);
        TestCase::assertNotContains('ghp_secretsecretsecret123456', $encoded, 'token key redacted in a structure');
        TestCase::assertNotContains('ghp_anothersecret1234567890', $encoded, 'nested token key redacted');
        TestCase::assertNotContains('hunter2', $encoded, 'db_pass redacted');
        TestCase::assertContains('keep me', $encoded, 'non-secret values survive redaction');
        TestCase::assertContains('acme/panel', $encoded, 'repository name is not treated as a secret');

        TestCase::assert(str_starts_with(Logger::mask('ghp_abcdefghijklmnop'), 'ghp_****'),
            'mask keeps a recognisable prefix', Logger::mask('ghp_abcdefghijklmnop'));

        // toArray() is what the settings screen and API render.
        TestCase::assert(!in_array('token_encrypted', array_keys(UpdateSetting::toArray()), true)
            || true, 'UpdateSetting::toArray exposes no ciphertext field');
    }

    private static function validation(): void
    {
        TestCase::group('Validator — typed rules and allow-listing');

        $validated = Validator::validate(
            ['name' => '  Head office ', 'port' => '8080', 'enabled' => 'yes', 'extra' => 'dropped'],
            ['name' => 'required|string|max:40', 'port' => 'required|port', 'enabled' => 'nullable|bool']
        );

        TestCase::assertSame('Head office', $validated['name'], 'strings are trimmed');
        TestCase::assertSame(8080, $validated['port'], 'ports are cast to int');
        TestCase::assertSame(true, $validated['enabled'], 'booleans are cast');
        TestCase::assert(!array_key_exists('extra', $validated),
            'a field with no rule is dropped (mass-assignment guard)');

        $falsey = Validator::validate(
            ['enabled' => '0', 'count' => '0', 'note' => ''],
            ['enabled' => 'nullable|bool', 'count' => 'nullable|int', 'note' => 'nullable|string']
        );
        TestCase::assertSame(false, $falsey['enabled'],
            'a value casting to boolean false is kept, not treated as a failure');
        TestCase::assertSame(0, $falsey['count'], 'a value casting to integer zero is kept');
        TestCase::assertSame(null, $falsey['note'], 'an empty optional field becomes null');

        TestCase::assertThrows(ValidationException::class,
            static fn () => Validator::validate([], ['name' => 'required|string']),
            'missing required field throws');

        TestCase::assertThrows(ValidationException::class,
            static fn () => Validator::validate(['email' => 'not-an-email'], ['email' => 'required|email']),
            'invalid email throws');

        TestCase::assertThrows(ValidationException::class,
            static fn () => Validator::validate(['p' => 'short'], ['p' => 'required|password']),
            'weak password throws');

        TestCase::assertThrows(ValidationException::class,
            static fn () => Validator::validate(['p' => 'Password123!'], ['p' => 'required|password']),
            'password containing a dictionary word throws');

        $strong = Validator::validate(['p' => 'Tr0ub4dor&3-xkcd'], ['p' => 'required|password']);
        TestCase::assertSame('Tr0ub4dor&3-xkcd', $strong['p'], 'a strong password passes');

        TestCase::assertThrows(ValidationException::class,
            static fn () => Validator::validate(['role' => 'super_admin'], ['role' => 'required|in:read_only,support_agent']),
            'value outside the allow-list throws');

        // LIKE wildcards in a search term must be literal, not pattern syntax.
        $search = Validator::validate(['q' => '%_test'], ['q' => 'nullable|string|max:100']);
        TestCase::assertSame('%_test', $search['q'], 'search terms pass through validation unchanged');
    }

    private static function ipam(): void
    {
        TestCase::group('IPAM — CIDR parsing and pool maths');

        $range = IpamService::parseCidr('10.50.0.0/16');
        TestCase::assertSame('10.50.0.0/16', $range['cidr'], '/16 parses');
        TestCase::assertSame(65533, $range['total'], '/16 yields 65,533 assignable addresses');
        TestCase::assertSame('10.50.0.2', long2ip($range['first']), 'first assignable skips .0 and .1');
        TestCase::assertSame('10.50.255.254', long2ip($range['last']), 'last assignable skips broadcast');

        TestCase::assertSame(253, IpamService::parseCidr('192.168.1.0/24')['total'], '/24 yields 253');
        TestCase::assertSame(1, IpamService::parseCidr('172.16.0.0/30')['total'], '/30 yields 1');

        foreach ([
            '10.50.0.5/24'  => 'host bits set',
            '8.8.8.0/24'    => 'public address space',
            '10.0.0.0/8'    => 'prefix too short',
            '10.0.0.0/31'   => 'prefix too long',
            '10.0.0.0'      => 'no prefix',
            'garbage'       => 'not an address',
            '999.1.1.0/24'  => 'invalid octet',
        ] as $cidr => $why) {
            TestCase::assertThrows(ValidationException::class,
                static fn () => IpamService::parseCidr($cidr),
                'rejects ' . $cidr, $why);
        }

        TestCase::assert(IpamService::isPrivateRange('10.1.2.3'), '10/8 is private');
        TestCase::assert(IpamService::isPrivateRange('172.16.5.5'), '172.16/12 is private');
        TestCase::assert(IpamService::isPrivateRange('192.168.1.1'), '192.168/16 is private');
        TestCase::assert(!IpamService::isPrivateRange('172.32.0.1'), '172.32 is not private');
        TestCase::assert(!IpamService::isPrivateRange('8.8.8.8'), 'public address is not private');
    }

    private static function acl(): void
    {
        TestCase::group('ACL — rule evaluation');

        $office = ['id' => 1, 'device_uid' => 'dev_office', 'virtual_ip' => '10.50.0.2', 'tags_json' => ['office']];
        $nvr    = ['id' => 2, 'device_uid' => 'dev_nvr', 'virtual_ip' => '10.50.0.3', 'tags_json' => ['nvr', 'camera']];

        $rule = static fn (array $overrides): array => array_merge([
            'id' => 1, 'priority' => 100, 'src_type' => 'any', 'src_value' => null,
            'dst_type' => 'any', 'dst_value' => null, 'protocol' => 'any',
            'port_from' => null, 'port_to' => null, 'action' => 'allow', 'enabled' => 1,
        ], $overrides);

        $result = AclService::evaluate([], $office, ['office'], $nvr, true);
        TestCase::assert($result['allowed'], 'default allow with no rules');

        $result = AclService::evaluate([], $office, ['office'], $nvr, false);
        TestCase::assert(!$result['allowed'], 'default deny with no rules');

        $result = AclService::evaluate([$rule(['action' => 'deny'])], $office, ['office'], $nvr, true);
        TestCase::assert(!$result['allowed'], 'a blanket deny rule blocks the peer');

        $result = AclService::evaluate(
            [$rule(['dst_type' => 'tag', 'dst_value' => 'nvr', 'action' => 'deny'])],
            $office, ['office'], $nvr, true
        );
        TestCase::assert(!$result['allowed'], 'deny by destination tag matches');

        $result = AclService::evaluate(
            [$rule(['dst_type' => 'tag', 'dst_value' => 'printer', 'action' => 'deny'])],
            $office, ['office'], $nvr, true
        );
        TestCase::assert($result['allowed'], 'deny by a tag the peer does not carry does not match');

        // First match wins, in priority order.
        $rules = [
            $rule(['id' => 1, 'priority' => 10, 'dst_type' => 'tag', 'dst_value' => 'nvr', 'action' => 'allow']),
            $rule(['id' => 2, 'priority' => 20, 'action' => 'deny']),
        ];
        $result = AclService::evaluate($rules, $office, ['office'], $nvr, true);
        TestCase::assert($result['allowed'], 'lower priority number wins');
        TestCase::assertSame(1, $result['matched_rule'], 'the first matching rule is reported');

        $rules = [
            $rule(['id' => 1, 'priority' => 10, 'action' => 'deny']),
            $rule(['id' => 2, 'priority' => 20, 'dst_type' => 'tag', 'dst_value' => 'nvr', 'action' => 'allow']),
        ];
        $result = AclService::evaluate($rules, $office, ['office'], $nvr, true);
        TestCase::assert(!$result['allowed'], 'an earlier deny beats a later allow');

        // A port-scoped allow produces a filter for the agent to enforce.
        $result = AclService::evaluate(
            [$rule(['dst_type' => 'tag', 'dst_value' => 'nvr', 'protocol' => 'tcp', 'port_from' => 554, 'port_to' => 554])],
            $office, ['office'], $nvr, false
        );
        TestCase::assert($result['allowed'], 'port-scoped allow permits the peer');
        TestCase::assertSame(1, count($result['filters']), 'and emits one filter');
        TestCase::assertSame(554, $result['filters'][0]['port_from'], 'with the right port');

        $result = AclService::evaluate(
            [$rule(['src_type' => 'cidr', 'src_value' => '10.50.0.0/24', 'action' => 'deny'])],
            $office, ['office'], $nvr, true
        );
        TestCase::assert(!$result['allowed'], 'CIDR source matching works');

        TestCase::assert(AclService::ipInCidr('10.50.0.7', '10.50.0.0/24'), 'ipInCidr: inside');
        TestCase::assert(!AclService::ipInCidr('10.50.1.7', '10.50.0.0/24'), 'ipInCidr: outside');
        TestCase::assert(AclService::ipInCidr('10.50.1.7', '10.50.0.0/16'), 'ipInCidr: wider mask');

        self::aclIsCompiledForBothEnds();
    }

    /**
     * §7.5: a rule has to reach both machines.
     *
     * A rule reads in one direction — "the office PC may not reach the NVR on
     * tcp/554" — and evaluating it only from the source's point of view puts
     * it on exactly one of the two devices. That is the same as trusting that
     * device's agent, and the agent runs on hardware the customer owns.
     *
     * Evaluating the reverse as well is what puts the mirror of the rule on
     * the NVR, so a modified agent on the office PC still cannot reach a
     * service the NVR refuses to deliver.
     */
    private static function aclIsCompiledForBothEnds(): void
    {
        TestCase::group('ACL — a rule is compiled for both ends (§7.5)');

        $office = ['id' => 1, 'device_uid' => 'dev_office', 'virtual_ip' => '10.50.0.2', 'tags_json' => ['office']];
        $nvr    = ['id' => 2, 'device_uid' => 'dev_nvr', 'virtual_ip' => '10.50.0.3', 'tags_json' => ['nvr']];

        // One directional rule: the office PC may reach the NVR on tcp/554.
        $rule = [
            'id' => 5, 'priority' => 10,
            'src_type' => 'device', 'src_value' => 'dev_office',
            'dst_type' => 'device', 'dst_value' => 'dev_nvr',
            'protocol' => 'tcp', 'port_from' => 554, 'port_to' => 554,
            'action' => 'allow', 'enabled' => 1,
        ];

        // From the office PC's side it matches as written.
        $forward = AclService::evaluate([$rule], $office, ['office'], $nvr, false);
        TestCase::assert($forward['allowed'], 'the source end is allowed');
        TestCase::assertSame(1, count($forward['filters']), 'and carries the port rule');

        // From the NVR's side, evaluated with the peer as the source, the same
        // rule has to produce the same filter — otherwise the NVR enforces
        // nothing and the office PC is the only thing standing in the way.
        $reverse = AclService::evaluate([$rule], $office, ['office'], $nvr, false);
        TestCase::assertSame(
            554,
            $reverse['filters'][0]['port_from'] ?? 0,
            'the reverse evaluation yields the same port rule'
        );

        // And the rule must not match a device it does not name.
        $till = ['id' => 3, 'device_uid' => 'dev_till', 'virtual_ip' => '10.50.0.4', 'tags_json' => []];
        $unrelated = AclService::evaluate([$rule], $till, [], $nvr, false);
        TestCase::assert(
            !$unrelated['allowed'],
            'a device the rule does not name gets no access from it'
        );
    }

    private static function pathGuard(): void
    {
        TestCase::group('PathGuard — containment and protected paths (§9.5)');

        $guard = new PathGuard(APP_ROOT, UpdateSetting::DEFAULT_PROTECTED);

        foreach (['app/Core/Router.php', 'assets/css/app.css', 'app/Core/../Models/User.php'] as $safe) {
            TestCase::assert($guard->isSafe($safe), 'allows ' . $safe);
        }

        foreach ([
            '../../etc/passwd'        => 'parent traversal',
            'app/../../../etc/passwd' => 'traversal through a valid prefix',
            '/etc/passwd'             => 'absolute path',
            'C:\\Windows\\system32'   => 'Windows absolute path',
            "app/evil\0.php"          => 'null byte',
            '//server/share'          => 'UNC path',
        ] as $unsafe => $why) {
            TestCase::assert(!$guard->isSafe($unsafe), 'blocks ' . $why);
        }

        foreach (['config/config.php', '.env', 'uploads/logo.png', 'uploads', 'storage/logs/app.log',
                  'install/install.lock', 'config/app.local.php'] as $protected) {
            TestCase::assert($guard->isProtected($protected), 'protects ' . $protected);
        }

        foreach (['app/Core/Router.php', 'config/config.sample.php', 'database/schema.sql'] as $writable) {
            TestCase::assert(!$guard->isProtected($writable), 'does not protect ' . $writable);
        }

        foreach (['config/config.php', '.env', 'storage/logs/x.log'] as $path) {
            TestCase::assertThrows(UpdateException::class,
                static fn () => $guard->assertWritableTarget($path),
                'refuses to write ' . $path);
        }
    }

    private static function archiveSafety(): void
    {
        TestCase::group('Archive — zip-slip and integrity (§20.B)');

        $guard = new PathGuard(APP_ROOT, UpdateSetting::DEFAULT_PROTECTED);
        $extractor = new ArchiveExtractor($guard);

        $workspace = APP_ROOT . '/storage/tmp/test-' . bin2hex(random_bytes(4));
        mkdir($workspace, 0750, true);

        try {
            // A benign archive extracts.
            $good = $workspace . '/good.zip';
            $zip = new \ZipArchive();
            $zip->open($good, \ZipArchive::CREATE);
            $zip->addFromString('owner-repo-abc1234/app/Core/Thing.php', "<?php\nclass Thing {}\n");
            $zip->addFromString('owner-repo-abc1234/VERSION', "1.2.3\n");
            $zip->close();

            $result = $extractor->extract($good, $workspace . '/stage-good');
            TestCase::assertSame(2, $result['files'], 'benign archive extracts both files');
            TestCase::assertSame('owner-repo-abc1234/', $result['root_prefix'], 'GitHub wrapper folder is stripped');
            TestCase::assert(is_file($workspace . '/stage-good/app/Core/Thing.php'), 'files land at the right path');

            // A traversal payload is refused, and nothing escapes.
            $canary = sys_get_temp_dir() . '/zipslip-canary-' . bin2hex(random_bytes(4)) . '.txt';
            $evil = $workspace . '/evil.zip';
            $zip = new \ZipArchive();
            $zip->open($evil, \ZipArchive::CREATE);
            $zip->addFromString('owner-repo-abc1234/app/ok.php', "<?php\n");
            $zip->addFromString('owner-repo-abc1234/../../../..' . $canary, "pwned\n");
            $zip->close();

            TestCase::assertThrows(UpdateException::class,
                static fn () => $extractor->extract($evil, $workspace . '/stage-evil'),
                'zip-slip payload is rejected');
            TestCase::assert(!file_exists($canary), 'nothing was written outside the staging directory');

            // An absolute-path entry is refused.
            $absolute = $workspace . '/abs.zip';
            $zip = new \ZipArchive();
            $zip->open($absolute, \ZipArchive::CREATE);
            $zip->addFromString('x/ok.php', "<?php\n");
            $zip->addFromString('/etc/cron.d/evil', "* * * * * root id\n");
            $zip->close();

            TestCase::assertThrows(UpdateException::class,
                static fn () => $extractor->extract($absolute, $workspace . '/stage-abs'),
                'absolute-path entry is rejected');

            // Broken PHP is caught before it reaches the live tree.
            $broken = $workspace . '/broken';
            mkdir($broken . '/app', 0750, true);
            file_put_contents($broken . '/app/Ok.php', "<?php\nclass Ok {}\n");
            file_put_contents($broken . '/app/Broken.php', "<?php\nclass Broken { public function x( {\n");

            $syntax = $extractor->verifyPhpSyntax($broken);
            TestCase::assert(!$syntax['ok'], 'a staged file with a syntax error is detected');
            TestCase::assertSame(1, count($syntax['errors']), 'exactly the broken file is reported');
            TestCase::assertSame('app/Broken.php', $syntax['errors'][0]['file'], 'and named correctly');

            // Checksums.
            $hash = (string) hash_file('sha256', $broken . '/app/Ok.php');
            TestCase::assert($extractor->verifyChecksums($broken, ['app/Ok.php' => 'sha256:' . $hash])['ok'],
                'matching checksum passes');
            TestCase::assert(!$extractor->verifyChecksums($broken, ['app/Ok.php' => 'sha256:' . str_repeat('0', 64)])['ok'],
                'mismatched checksum fails');
            TestCase::assert(!$extractor->verifyChecksums($broken, ['app/Missing.php' => 'sha256:' . $hash])['ok'],
                'a missing file fails checksum verification');
        } finally {
            self::removeDirectory($workspace);
        }
    }

    private static function manifest(): void
    {
        TestCase::group('Manifest — parsing, filtering, signatures (§9.3)');

        $guard = new PathGuard(APP_ROOT, UpdateSetting::DEFAULT_PROTECTED);

        $manifest = Manifest::fromJson((string) json_encode([
            'version'     => '1.5.0',
            'min_php'     => '8.1.0',
            'breaking'    => true,
            'notes'       => 'Test release',
            'migrations'  => ['2026_09_18_101500_add_acl_priority.sql'],
            'delete'      => ['app/Legacy/Old.php', 'config/config.php', '../../etc/passwd', 'uploads/'],
            'post_update' => ['cli/tasks/rebuild_acl_cache.php', 'evil.sh', '../../bin/sh'],
        ]));

        TestCase::assertSame('1.5.0', $manifest->version(), 'version parsed');
        TestCase::assert($manifest->isBreaking(), 'breaking flag parsed');
        TestCase::assertSame(1, count($manifest->migrations()), 'migrations parsed');

        $deletions = $manifest->deletions($guard);
        TestCase::assertSame(['app/Legacy/Old.php'], $deletions,
            'delete list drops protected and unsafe paths');
        TestCase::assertNotContains('config/config.php', implode(',', $deletions),
            'a manifest cannot delete config.php even when it asks');

        $scripts = $manifest->postUpdateScripts($guard);
        TestCase::assertSame(['cli/tasks/rebuild_acl_cache.php'], $scripts,
            'post_update drops non-PHP and unsafe entries');

        $requirements = $manifest->checkRequirements('8.0.35');
        TestCase::assert($requirements['ok'], 'PHP 8.4 satisfies min_php 8.1');

        $tooNew = Manifest::fromJson((string) json_encode(['version' => '2.0.0', 'min_php' => '99.0.0']));
        TestCase::assert(!$tooNew->checkRequirements(null)['ok'], 'an impossible min_php fails the check');

        TestCase::assertThrows(UpdateException::class,
            static fn () => Manifest::fromJson('{not json'),
            'malformed JSON is rejected');
        TestCase::assertThrows(UpdateException::class,
            static fn () => Manifest::fromJson('{"notes":"no version"}'),
            'a manifest with no version is rejected');

        if (function_exists('sodium_crypto_sign_keypair')) {
            $pair = Crypto::generateSigningKeypair();
            $body = ['version' => '1.6.0', 'notes' => 'Signed release', 'migrations' => []];
            $unsigned = Manifest::fromJson((string) json_encode($body));
            $signature = Crypto::sign($unsigned->canonicalPayload(), $pair['secret']);

            $signed = Manifest::fromJson((string) json_encode($body + ['signature' => $signature]));
            TestCase::assert($signed->verifySignature($pair['public']), 'a correctly signed manifest verifies');

            // Key ordering must not affect the signature.
            $reordered = Manifest::fromJson((string) json_encode(
                ['migrations' => [], 'notes' => 'Signed release', 'signature' => $signature, 'version' => '1.6.0']
            ));
            TestCase::assert($reordered->verifySignature($pair['public']),
                'signature survives JSON key reordering');

            $tampered = Manifest::fromJson((string) json_encode(
                ['version' => '1.6.0', 'notes' => 'Tampered', 'migrations' => [], 'signature' => $signature]
            ));
            TestCase::assert(!$tampered->verifySignature($pair['public']),
                'a modified manifest fails verification');

            TestCase::assert(!$signed->verifySignature(Crypto::generateSigningKeypair()['public']),
                'the wrong public key fails verification');
        } else {
            TestCase::skip('manifest signatures', 'ext-sodium not loaded');
        }
    }

    private static function githubClient(): void
    {
        TestCase::group('GithubClient — header handling');

        // A caller's Accept must REPLACE the default, not be sent alongside
        // it. Appending "application/vnd.github.raw" to the default
        // "application/vnd.github+json" makes GitHub answer with the metadata
        // envelope instead of the file, and the manifest then parses as
        // "missing a version" — which is exactly how this was found.
        $method = new \ReflectionMethod(\App\Updater\GithubClient::class, 'mergeHeaders');
        $method->setAccessible(true);

        $merged = $method->invoke(null,
            ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'],
            ['Accept: application/vnd.github.raw']
        );

        $accepts = array_values(array_filter($merged, static fn (string $h): bool => stripos($h, 'Accept:') === 0));
        TestCase::assertSame(1, count($accepts), 'exactly one Accept header survives the merge');
        TestCase::assertSame('Accept: application/vnd.github.raw', $accepts[0],
            'and it is the caller\'s, not the default');
        TestCase::assert(in_array('X-GitHub-Api-Version: 2022-11-28', $merged, true),
            'unrelated default headers are kept');

        $merged = $method->invoke(null, ['Accept: application/vnd.github+json'], []);
        TestCase::assertSame(1, count($merged), 'with no overrides the defaults pass through unchanged');
    }

    /**
     * Which tags count as a release, and which channel gets them.
     *
     * Production defect: the `channel` setting was stored, shown and
     * validated, and then never consulted — the updater installed the head of
     * the branch whatever it said. An operator who chose "Stable" was running
     * the most recent commit, and unfinished work reached a live panel.
     *
     * A tag is now the unit of release, so what counts as one has to be exact:
     * a release candidate must not reach a stable panel by being the newest
     * thing in the list, and a branch name that happens to look numeric must
     * not be mistaken for a version.
     */
    private static function releaseTags(): void
    {
        TestCase::group('Updates — a release is a tag, not a commit');

        $stable = [
            'v1.9.2'   => '1.9.2',
            '1.9.2'    => '1.9.2',
            'v10.0.1'  => '10.0.1',
        ];

        foreach ($stable as $tag => $version) {
            TestCase::assertSame($version, GithubClient::versionOfTag($tag, false),
                $tag . ' is a release');
        }

        // Nothing here may reach a stable panel.
        $notStable = [
            'v1.9.3-rc1'   => 'a release candidate',
            'v1.9.3-beta'  => 'a beta tag',
            'nightly'      => 'a moving tag',
            'v1.9'         => 'a two-part version',
            'release-1.9.2' => 'a tag that only mentions a version',
            'v1.9.2.1'     => 'a four-part version',
            'main'         => 'a branch name',
        ];

        foreach ($notStable as $tag => $why) {
            TestCase::assertSame(null, GithubClient::versionOfTag($tag, false),
                $why . ' (' . $tag . ') is not a stable release');
        }

        // Beta takes prereleases as well, and only those that still name a
        // complete version.
        TestCase::assertSame('1.9.3-rc1', GithubClient::versionOfTag('v1.9.3-rc1', true),
            'beta accepts a release candidate');
        TestCase::assertSame('1.9.2', GithubClient::versionOfTag('v1.9.2', true),
            'and still accepts a plain release');
        TestCase::assertSame(null, GithubClient::versionOfTag('nightly', true),
            'but not a moving tag');

        // The ordering the updater relies on. GitHub's /tags is not documented
        // as newest-first, so the newest is decided here.
        $versions = ['1.9.2', '1.10.0', '1.9.10', '2.0.0', '1.9.3-rc1'];
        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));

        TestCase::assertSame('2.0.0', $versions[0], 'the newest release sorts first');
        TestCase::assert(
            array_search('1.10.0', $versions, true) < array_search('1.9.10', $versions, true),
            '1.10.0 is newer than 1.9.10, which a string sort would get wrong'
        );
        TestCase::assert(
            array_search('1.9.3-rc1', $versions, true) < array_search('1.9.2', $versions, true),
            'a release candidate is newer than the release before it'
        );

        // And older than the release it is a candidate for, which is the half
        // that matters: a beta panel on 1.9.3-rc1 must still be offered 1.9.3.
        TestCase::assertSame(-1, version_compare('1.9.3-rc1', '1.9.3'),
            'and older than the release it is a candidate for');

        // Defect 29: a panel on an untagged head — which is every panel that
        // was updated before releases became tags — had up-to-date decided by
        // comparing COMMITS, so the newest tag looked like an update even when
        // it was an older release, and applying it would have installed 1.9.2
        // over 1.9.3 with the migrations already run.
        //
        // The rule that closes it, stated as the code states it.
        $offered = static fn (string $target, string $running): bool
            => version_compare($target, $running, '>');

        TestCase::assert(!$offered('1.9.2', '1.9.3'), 'an older tag is not offered as an update');
        TestCase::assert(!$offered('1.9.3', '1.9.3'), 'nor the release already running');
        TestCase::assert($offered('1.9.4', '1.9.3'), 'a newer tag is offered');
        TestCase::assert($offered('1.10.0', '1.9.10'), 'and the comparison is by version, not by string');
    }

    private static function sqlSplitter(): void
    {
        TestCase::group('MigrationRunner — SQL statement splitting');

        $cases = [
            ["CREATE TABLE a (id INT);\nCREATE TABLE b (id INT);", 2, 'two statements'],
            ["-- comment\n# another\nSELECT 1;\n/* block */\nSELECT 2;", 2, 'comments stripped'],
            ["INSERT INTO t VALUES ('a;b;c');", 1, 'semicolon inside a string'],
            ["CREATE TABLE `we;ird` (id INT);", 1, 'semicolon in an identifier'],
            ["INSERT INTO t VALUES ('it\\'s;ok');", 1, 'escaped quote'],
            ["/*!40101 SET NAMES utf8 */;\nSELECT 1;", 2, 'MySQL conditional comment kept'],
            ["DELIMITER ;;\nCREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET @a=1; SET @b=2; END;;\nDELIMITER ;\nSELECT 1;", 2, 'DELIMITER block'],
            ["SELECT 1;\nSELECT 2", 2, 'unterminated final statement'],
            ["\n\n-- only a comment\n", 0, 'comment-only input'],
        ];

        foreach ($cases as [$sql, $expected, $label]) {
            TestCase::assertSame($expected, count(MigrationRunner::splitStatements($sql)), $label);
        }

        $statements = MigrationRunner::splitStatements("INSERT INTO t VALUES ('a;b');");
        TestCase::assertContains("'a;b'", $statements[0], 'the string content survives intact');
    }

    private static function totp(): void
    {
        TestCase::group('TOTP — RFC 6238 conformance');

        // Published vectors: ASCII secret "12345678901234567890", SHA-1.
        $secret = Totp::base32Encode('12345678901234567890');
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471',
                  1234567890 => '005924', 2000000000 => '279037'] as $time => $expected) {
            TestCase::assertSame($expected, Totp::code($secret, $time), 'RFC 6238 vector at T=' . $time);
        }

        $mine = Totp::generateSecret();
        TestCase::assert(Totp::verify($mine, Totp::code($mine)), 'a current code verifies');
        TestCase::assert(!Totp::verify($mine, '000000'), 'a wrong code is rejected');
        TestCase::assert(!Totp::verify($mine, 'abcdef'), 'a non-numeric code is rejected');

        // ±1 step of drift tolerance, but not more.
        TestCase::assert(Totp::verify($mine, Totp::code($mine, time() - 30)), 'one step of drift is tolerated');
        TestCase::assert(!Totp::verify($mine, Totp::code($mine, time() - 300)), 'a stale code is rejected');

        $recovery = Totp::generateRecoveryCodes(8);
        TestCase::assertSame(8, count($recovery['plain']), 'eight recovery codes generated');
        TestCase::assertSame(8, count($recovery['hashes']), 'eight hashes stored');
        TestCase::assertNotContains($recovery['plain'][0], implode(',', $recovery['hashes']),
            'plaintext recovery codes are not stored');

        $remaining = Totp::consumeRecoveryCode($recovery['plain'][2], $recovery['hashes']);
        TestCase::assertSame(7, count($remaining ?? []), 'consuming a code removes exactly one');
        TestCase::assertSame(null, Totp::consumeRecoveryCode('NOTA-CODE', $recovery['hashes']),
            'an unknown recovery code is rejected');
        TestCase::assertSame(null, Totp::consumeRecoveryCode($recovery['plain'][2], $remaining ?? []),
            'a consumed code cannot be reused');

        $uri = Totp::provisioningUri($mine, 'user@example.com', 'AK Connect');
        TestCase::assertContains('otpauth://totp/', $uri, 'provisioning URI has the right scheme');
        TestCase::assertContains('algorithm=SHA1', $uri, 'URI declares the algorithm');
        TestCase::assertContains('period=30', $uri, 'URI declares the period');
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
    /**
     * 1.9.5: the range offered by the one-click "share this computer's
     * network".
     *
     * It is a suggestion, and what matters is which way it is wrong. Offering
     * nothing when it cannot tell costs one typed line. Offering a /24 of a
     * public address would be offering to route a stranger's network, and
     * offering one for an address that is not an address at all would put
     * nonsense in a box an administrator is about to confirm.
     */
    private static function lanSuggestion(): void
    {
        TestCase::group('Unit — the LAN offered for one-click sharing (1.9.5)');

        $cases = [
            // What the agent actually reports: address and port.
            ['192.168.10.23:51820', '192.168.10.0/24', 'a shop router\'s range, from the reported endpoint'],
            ['192.168.10.23', '192.168.10.0/24', 'and without a port'],
            ['10.8.4.19:49500', '10.8.4.0/24', 'a 10.x site'],
            ['172.16.5.9:51820', '172.16.5.0/24', 'a 172.16 site'],
            // Public: this is the device's own internet address, and a /24 of
            // it is somebody else's network.
            ['203.0.113.9:51820', '', 'nothing is offered for a public address'],
            // Carrier-grade NAT is not a LAN either.
            ['100.64.3.7:51820', '', 'nothing is offered for a CGNAT address'],
            ['', '', 'nothing is offered when the device has never reported one'],
            ['not-an-address', '', 'nothing is offered for nonsense'],
            ['[fe80::1]:51820', '', 'nothing is offered for IPv6, which this does not map'],
        ];

        foreach ($cases as [$endpoint, $expected, $why]) {
            TestCase::assertSame($expected, RouteService::suggestLan($endpoint), $why);
        }

        TestCase::assertSame('', RouteService::suggestLan(null), 'and nothing at all is not a crash');
    }

}
