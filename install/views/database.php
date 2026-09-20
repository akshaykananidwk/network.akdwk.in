<?php declare(strict_types=1); ?>
<div class="card">
    <h2>Database</h2>
    <form method="post" action="?step=database">
        <input type="hidden" name="_token" value="<?= h($csrf) ?>">
        <div class="card-body">
            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><span class="alert-icon">✕</span><span><?= h($error) ?></span></div>
            <?php endforeach; ?>

            <p class="hint">
                Use <code>127.0.0.1</code> rather than <code>localhost</code> if you are unsure —
                it avoids socket-path problems on many hosts.
            </p>

            <div class="grid">
                <div class="field">
                    <label for="db_host">Host</label>
                    <input type="text" id="db_host" name="db_host"
                           value="<?= h($answers['db_host'] ?? '127.0.0.1') ?>" required>
                </div>
                <div class="field">
                    <label for="db_port">Port</label>
                    <input type="number" id="db_port" name="db_port"
                           value="<?= h($answers['db_port'] ?? 3306) ?>" required>
                </div>
            </div>

            <div class="field">
                <label for="db_name">Database name</label>
                <input type="text" id="db_name" name="db_name" value="<?= h($answers['db_name'] ?? '') ?>" required>
                <p class="hint">If it does not exist we will try to create it, which needs CREATE privilege.</p>
            </div>

            <div class="grid">
                <div class="field">
                    <label for="db_user">Username</label>
                    <input type="text" id="db_user" name="db_user" value="<?= h($answers['db_user'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label for="db_pass">Password</label>
                    <input type="password" id="db_pass" name="db_pass" value="<?= h($answers['db_pass'] ?? '') ?>"
                           autocomplete="off">
                </div>
            </div>

            <div class="field">
                <label for="db_prefix">Table prefix (optional)</label>
                <input type="text" id="db_prefix" name="db_prefix" value="<?= h($answers['db_prefix'] ?? '') ?>"
                       placeholder="ak_" pattern="[A-Za-z0-9_]*">
                <p class="hint">Only needed if this database is shared with another application.</p>
            </div>

            <?php if (!empty($answers['_existing_tables'])): ?>
                <div class="alert alert-warning">
                    <span class="alert-icon">!</span>
                    <div>
                        <strong>This database already contains <?= h($answers['_existing_tables']) ?> table(s).</strong>
                        <p style="margin:.4rem 0 0">Point at an empty database instead, or type
                           <code>DROP</code> below to delete those tables and install fresh.
                           <strong>Dropping cannot be undone.</strong></p>
                    </div>
                </div>
                <div class="field">
                    <label for="drop_confirm">Type DROP to confirm</label>
                    <input type="text" id="drop_confirm" name="drop_confirm" autocomplete="off" placeholder="DROP">
                </div>
            <?php endif; ?>

            <button type="button" class="btn" data-test="db">Test connection</button>
            <div id="db-result" class="result"></div>
        </div>

        <div class="card-foot">
            <a class="btn" href="?step=requirements">← Back</a>
            <button type="submit" class="btn btn-primary">Create the tables →</button>
        </div>
    </form>
</div>
