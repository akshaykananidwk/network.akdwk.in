<?php declare(strict_types=1); ?>
<div class="card">
    <h2>Before you start</h2>
    <div class="card-body">
        <p>This wizard sets up the panel end to end: it checks the server, creates the
           database tables, creates your administrator account, and writes the configuration.
           Nothing needs editing by hand afterwards.</p>

        <p><strong>Have these ready:</strong></p>
        <ul class="steps">
            <li>Database name, username and password (create an empty database first if your host requires it).</li>
            <li>The full URL this panel will be reached at, e.g. <code>https://net.example.com</code>.</li>
            <li>SMTP details, if you want password resets and alerts by email. You can add these later.</li>
        </ul>

        <div class="alert alert-warning">
            <span class="alert-icon">!</span>
            <span>Install over HTTPS if you can. You will be typing a database password and choosing
                  an administrator password on the next few screens.</span>
        </div>

        <p class="hint">Version <?= h(trim((string) @file_get_contents(APP_ROOT . '/VERSION'))) ?>
           · PHP <?= h(PHP_VERSION) ?></p>
    </div>
    <div class="card-foot">
        <span></span>
        <a class="btn btn-primary" href="?step=requirements">Check this server →</a>
    </div>
</div>
