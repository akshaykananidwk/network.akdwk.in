<?php
/**
 * Installer chrome. Self-contained styling — the application stylesheet may be
 * unreachable if the web server is misconfigured, which is exactly the
 * situation the installer has to diagnose.
 *
 * @var string $title
 * @var string $body
 */
declare(strict_types=1);

$currentStep = $step ?? 'welcome';
$steps = [
    'welcome'       => 'Welcome',
    'requirements'  => 'Requirements',
    'database'      => 'Database',
    'admin'         => 'Administrator',
    'configuration' => 'Configuration',
    'finish'        => 'Finish',
];
$currentIndex = function_exists('step_index') ? step_index($currentStep) : 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Installation</title>
<style>
    :root {
        --bg: #f6f7f9; --surface: #fff; --sunken: #eef1f5; --text: #0f172a; --muted: #64748b;
        --border: #e2e8f0; --primary: #2563eb; --success: #16a34a; --warning: #d97706; --danger: #dc2626;
        --radius: 10px;
    }
    @media (prefers-color-scheme: dark) {
        :root { --bg:#0b1120; --surface:#111827; --sunken:#0f172a; --text:#e2e8f0; --muted:#94a3b8; --border:#1f2937; }
    }
    * { box-sizing: border-box; }
    body { margin:0; font-family: system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; font-size:15px;
           line-height:1.55; background:var(--bg); color:var(--text); }
    .wrap { max-width: 760px; margin: 0 auto; padding: 2rem 1rem 4rem; }
    header.masthead { text-align:center; padding: 1.5rem 0 1rem; }
    header.masthead h1 { margin:.4rem 0 .2rem; font-size:1.35rem; }
    header.masthead p { margin:0; color:var(--muted); font-size:.88rem; }
    .logo { width:44px; height:44px; margin:0 auto; border-radius:12px; background:var(--primary);
            color:#fff; display:grid; place-items:center; font-weight:700; font-size:1.3rem; }

    .progress { display:flex; gap:.25rem; margin: 1.5rem 0 2rem; list-style:none; padding:0;
                overflow-x:auto; }
    .progress li { flex:1; min-width:84px; text-align:center; font-size:.72rem; color:var(--muted); }
    .progress li span.bar { display:block; height:4px; border-radius:999px; background:var(--border); margin-bottom:.4rem; }
    .progress li.done span.bar, .progress li.current span.bar { background:var(--primary); }
    .progress li.current { color:var(--primary); font-weight:600; }

    .card { background:var(--surface); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
    .card > h2 { margin:0; padding:1.1rem 1.4rem; border-bottom:1px solid var(--border); font-size:1.05rem; }
    .card-body { padding:1.4rem; }
    .card-foot { padding:1rem 1.4rem; border-top:1px solid var(--border); background:var(--sunken);
                 display:flex; gap:.6rem; justify-content:space-between; flex-wrap:wrap; }

    label { display:block; font-size:.85rem; font-weight:500; margin-bottom:.35rem; }
    input,select { width:100%; padding:.5rem .7rem; font:inherit; font-size:.88rem; color:var(--text);
                   background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); }
    .field { margin-bottom:1rem; }
    .hint { font-size:.78rem; color:var(--muted); margin:.3rem 0 0; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    @media (max-width:640px){ .grid { grid-template-columns:1fr; } }

    .btn { display:inline-flex; align-items:center; gap:.4rem; padding:.5rem 1rem; font:inherit;
           font-size:.88rem; font-weight:500; border:1px solid var(--border); border-radius:var(--radius);
           background:var(--surface); color:var(--text); cursor:pointer; text-decoration:none; }
    .btn:hover { background:var(--sunken); }
    .btn-primary { background:var(--primary); border-color:var(--primary); color:#fff; }
    .btn-primary:hover { filter:brightness(.92); }
    .btn:disabled { opacity:.5; cursor:not-allowed; }

    .alert { display:flex; gap:.7rem; padding:.8rem 1rem; border-radius:var(--radius);
             border:1px solid var(--border); margin-bottom:1rem; font-size:.88rem; align-items:flex-start; }
    .alert-icon { width:20px;height:20px;flex-shrink:0;border-radius:50%;display:grid;place-items:center;
                  font-size:.72rem;font-weight:700;color:#fff; }
    .alert-error { border-color:var(--danger); background:color-mix(in srgb,var(--danger) 8%,var(--surface)); }
    .alert-error .alert-icon { background:var(--danger); }
    .alert-success { border-color:var(--success); background:color-mix(in srgb,var(--success) 8%,var(--surface)); }
    .alert-success .alert-icon { background:var(--success); }
    .alert-warning { border-color:var(--warning); background:color-mix(in srgb,var(--warning) 8%,var(--surface)); }
    .alert-warning .alert-icon { background:var(--warning); }

    table.checks { width:100%; border-collapse:collapse; font-size:.86rem; }
    table.checks th { text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em;
                      color:var(--muted); padding:.5rem .6rem; background:var(--sunken); }
    table.checks td { padding:.5rem .6rem; border-bottom:1px solid var(--border); vertical-align:top; }
    .pill { display:inline-block; padding:.05rem .45rem; border-radius:999px; font-size:.72rem; font-weight:600; }
    .pill-ok { background:color-mix(in srgb,var(--success) 16%,transparent); color:var(--success); }
    .pill-fail { background:color-mix(in srgb,var(--danger) 14%,transparent); color:var(--danger); }
    .pill-warn { background:color-mix(in srgb,var(--warning) 16%,transparent); color:var(--warning); }
    .fix { color:var(--muted); font-size:.78rem; margin:.25rem 0 0; }

    code,pre { font-family: ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.82rem; }
    code { background:var(--sunken); padding:.1em .35em; border-radius:4px; }
    pre { background:var(--sunken); padding:.8rem; border-radius:var(--radius); overflow-x:auto; margin:.5rem 0; }
    .steps { padding-left:1.2rem; font-size:.88rem; }
    .steps li { margin-bottom:.4rem; }
    fieldset { border:1px solid var(--border); border-radius:var(--radius); padding:1rem; margin:0 0 1.2rem; }
    legend { padding:0 .4rem; font-size:.85rem; font-weight:600; }
    .checkbox { display:flex; gap:.6rem; align-items:flex-start; font-size:.86rem; font-weight:400; }
    .checkbox input { width:auto; margin-top:.2rem; }
    .result { margin-top:.8rem; }
    .lang { text-align:right; font-size:.8rem; margin-bottom:-1rem; }
    .recovery { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:.4rem; margin:.8rem 0; }
    .recovery code { text-align:center; padding:.35rem; }
    .qr { margin:.8rem 0; }
    .qr svg { background:#fff; padding:8px; border-radius:var(--radius); }
</style>
</head>
<body>
<div class="wrap">
    <header class="masthead">
        <div class="logo" aria-hidden="true">A</div>
        <h1>Installation</h1>
        <p><?= h($answers['site_name'] ?? 'Private overlay network platform') ?></p>
    </header>

    <ol class="progress">
        <?php foreach ($steps as $key => $label): ?>
            <?php
            $index = array_search($key, array_keys($steps), true);
            $class = $index < $currentIndex ? 'done' : ($key === $currentStep ? 'current' : '');
            ?>
            <li class="<?= h($class) ?>"><span class="bar"></span><?= h($label) ?></li>
        <?php endforeach; ?>
    </ol>

    <?= $body ?>
</div>

<script>
/* The installer's own JavaScript: test buttons only, no framework. */
(function () {
    function post(data) {
        var body = new URLSearchParams(data);
        return fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.json(); });
    }

    function show(boxId, ok, message) {
        var box = document.getElementById(boxId);
        if (!box) { return; }
        box.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'error') + '">'
            + '<span class="alert-icon">' + (ok ? '✓' : '✕') + '</span><span></span></div>';
        box.querySelector('span:last-child').textContent = message;
    }

    function collect(form, names) {
        var data = { _token: form._token.value };
        names.forEach(function (n) { if (form[n]) { data[n] = form[n].value; } });
        return data;
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-test]');
        if (!button) { return; }

        var kind = button.getAttribute('data-test');
        var form = button.closest('form');
        var original = button.textContent;
        button.disabled = true;
        button.textContent = 'Testing…';

        var data, box;
        if (kind === 'db') {
            data = collect(form, ['db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'db_prefix']);
            data._action = 'test_db';
            box = 'db-result';
        } else if (kind === 'mail') {
            data = collect(form, ['mail_host', 'mail_port', 'mail_user', 'mail_pass', 'mail_security', 'mail_from']);
            data._action = 'test_mail';
            data.test_to = (document.getElementById('test_to') || {}).value || '';
            box = 'mail-result';
        } else {
            data = collect(form, ['gh_owner', 'gh_repo', 'gh_branch', 'gh_token']);
            data._action = 'test_github';
            box = 'gh-result';
        }

        post(data).then(function (result) {
            show(box, result.ok, result.message || 'Done.');
        }).catch(function () {
            show(box, false, 'The test request failed. Check the browser console and the server error log.');
        }).finally(function () {
            button.disabled = false;
            button.textContent = original;
        });
    });
})();
</script>
<?php if (!empty($_SESSION['install_complete']['twofa_uri'] ?? null)): ?>
<script src="../assets/js/qr.js"></script>
<script>
(function () {
    var holder = document.getElementById('install-qr');
    if (holder && window.renderQrSvg) {
        try { holder.innerHTML = window.renderQrSvg(holder.getAttribute('data-uri')); }
        catch (e) { holder.textContent = 'Enter the key below manually.'; }
    }
})();
</script>
<?php endif; ?>
</body>
</html>
