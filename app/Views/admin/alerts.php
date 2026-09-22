<?php
/**
 * Platform → Alerts.
 *
 * @var array<string,mixed> $settings
 * @var bool $has_headers
 * @var bool $ready
 */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <h2>Alerts to a telephone</h2>
        <p class="text-muted">
            Email is where an alert goes to be read tomorrow. A relay that stopped responding,
            a backup that failed, an update that rolled back — those go here as well, now.
        </p>
    </header>

    <?php if ($ready): ?>
        <p class="alert alert-success">
            Configured. Critical alerts go to the numbers below as well as by email.
        </p>
    <?php else: ?>
        <p class="alert">
            Not configured. Critical alerts go by email only.
        </p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('admin/alerts')) ?>" class="form">
        <?= csrf_field() ?>

        <label class="checkbox">
            <input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>>
            <span>Send critical alerts to WhatsApp</span>
        </label>

        <div class="field">
            <label for="recipients">Numbers</label>
            <input type="text" id="recipients" name="recipients"
                   value="<?= e((string) $settings['recipients']) ?>"
                   placeholder="+919876543210, +919876543211">
            <p class="field-hint">
                In international form, separated by commas. Anything that is not digits and a
                leading plus is ignored rather than sent — a typing mistake would otherwise
                be a message to a stranger.
            </p>
        </div>

        <div class="field">
            <label for="endpoint">Endpoint</label>
            <input type="text" id="endpoint" name="endpoint"
                   value="<?= e((string) $settings['endpoint']) ?>"
                   placeholder="https://bulk.akdwk.in/api/send">
            <p class="field-hint">
                Your sending service's address. <code>{{to}}</code> and <code>{{text}}</code>
                work here too, for an API that takes them in the URL.
            </p>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="method">Method</label>
                <input type="text" id="method" name="method" value="<?= e((string) $settings['method']) ?>">
            </div>
            <div class="field">
                <label for="content_type">Content type</label>
                <input type="text" id="content_type" name="content_type"
                       value="<?= e((string) $settings['content_type']) ?>">
            </div>
        </div>

        <div class="field">
            <label for="body">Request body</label>
            <textarea id="body" name="body" rows="5"
                      placeholder='{"to": "{{to}}", "message": "{{text}}"}'><?= e((string) $settings['body']) ?></textarea>
            <p class="field-hint">
                Exactly what your service expects, with <code>{{to}}</code> and
                <code>{{text}}</code> where the number and the message go. Both are escaped
                for the content type above, so a message containing a quotation mark cannot
                break the request around it.
            </p>
        </div>

        <div class="field">
            <label for="headers">Extra headers</label>
            <textarea id="headers" name="headers" rows="3"
                      placeholder="Authorization: Bearer YOUR-TOKEN"><?php /* never echoed back */ ?></textarea>
            <p class="field-hint">
                One per line. This is where the token goes: it is encrypted at rest, never
                sent back to this page and never written to a log.
                <?= $has_headers
                    ? '<strong>A value is stored.</strong> Leave this blank to keep it.'
                    : 'Nothing is stored yet.' ?>
            </p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</section>

<?php if ($ready): ?>
<section class="card">
    <header class="card-header"><h2>Test it</h2></header>
    <div class="danger-row">
        <div>
            <strong>Send a test alert</strong>
            <p class="text-muted">
                So a wrong token is found today rather than during the outage this was set
                up for.
            </p>
        </div>
        <form method="post" action="<?= e(url('admin/alerts/test')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn">Send a test</button>
        </form>
    </div>
</section>
<?php endif; ?>
