/* ---------------------------------------------------------------------------
 * Application JavaScript.
 *
 * Vanilla, no framework, no build step — the brief requires deployment by
 * copying files onto a host with no npm available.
 *
 * Everything here is progressive enhancement. Forms submit and pages work with
 * JavaScript disabled; this layer adds live updates, the update progress
 * driver, and a handful of conveniences.
 * --------------------------------------------------------------------------- */

(function () {
    'use strict';

    var APP = window.APP || {};

    /* ------------------------------------------------------------- helpers */

    function $(selector, root) { return (root || document).querySelector(selector); }
    function $$(selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }

    function toast(message, type) {
        var stack = $('#toasts');
        if (!stack) { return; }

        var el = document.createElement('div');
        el.className = 'toast toast-' + (type || 'success');
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.textContent = message;
        stack.appendChild(el);

        setTimeout(function () {
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 220);
        }, type === 'error' ? 8000 : 4500);
    }

    /**
     * POST JSON with the CSRF token attached.
     *
     * Every state-changing request from this file goes through here, so the
     * token is never forgotten and a 403 from CsrfMiddleware is reported
     * clearly instead of silently failing.
     */
    function post(url, data) {
        var headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        headers[APP.csrfHeader || 'X-CSRF-Token'] = APP.csrfToken;

        return fetch(url, {
            method: 'POST',
            headers: headers,
            credentials: 'same-origin',
            body: JSON.stringify(data || {})
        }).then(readJson);
    }

    function get(url) {
        return fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin'
        }).then(readJson);
    }

    function readJson(response) {
        return response.text().then(function (text) {
            var payload;
            try {
                payload = JSON.parse(text);
            } catch (e) {
                // A non-JSON body here almost always means a PHP fatal or an
                // HTML error page; surface something actionable.
                throw new Error('Unexpected response from the server (HTTP ' + response.status + ').');
            }
            if (!response.ok || payload.success === false) {
                throw new Error((payload.error && payload.error.message) || 'Request failed.');
            }
            return payload.data !== undefined ? payload.data : payload;
        });
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    /* --------------------------------------------------------------- theme */

    function initTheme() {
        var stored = null;
        try { stored = localStorage.getItem('theme'); } catch (e) { /* private mode */ }
        if (stored === 'dark' || stored === 'light') {
            document.documentElement.setAttribute('data-theme', stored);
        }

        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-action="toggle-theme"]');
            if (!button) { return; }

            var current = document.documentElement.getAttribute('data-theme');
            if (!current) {
                current = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            var next = current === 'dark' ? 'light' : 'dark';

            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('theme', next); } catch (e) { /* ignore */ }
        });
    }

    /* ------------------------------------------------------------ chrome */

    function initChrome() {
        document.addEventListener('click', function (event) {
            var toggle = event.target.closest('[data-action="toggle-sidebar"]');
            if (toggle) {
                var sidebar = $('#sidebar');
                if (sidebar) {
                    var open = sidebar.classList.toggle('is-open');
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                }
                return;
            }

            var copy = event.target.closest('[data-action="copy"]');
            if (copy) {
                var target = document.getElementById(copy.getAttribute('data-copy-target'));
                if (target && navigator.clipboard) {
                    navigator.clipboard.writeText(target.textContent.trim()).then(function () {
                        var original = copy.textContent;
                        copy.textContent = 'Copied';
                        setTimeout(function () { copy.textContent = original; }, 1600);
                    });
                }
                return;
            }

            var reveal = event.target.closest('[data-action="toggle-password"]');
            if (reveal) {
                var input = document.getElementById(reveal.getAttribute('data-target'));
                if (input) {
                    var showing = input.type === 'text';
                    input.type = showing ? 'password' : 'text';
                    reveal.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
                }
                return;
            }

            if (event.target.closest('[data-action="print"]')) {
                window.print();
                return;
            }

            if (event.target.closest('[data-action="close-dialog"]')) {
                var dialog = event.target.closest('dialog');
                if (dialog) { dialog.close(); }
            }
        });

        // Destructive actions confirm before submitting.
        document.addEventListener('submit', function (event) {
            var form = event.target;
            var message = form.getAttribute('data-confirm');
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });

        // "Select all" checkbox in bulk-action tables.
        document.addEventListener('change', function (event) {
            var master = event.target.closest('[data-action="select-all"]');
            if (!master) { return; }
            var form = master.closest('form');
            if (!form) { return; }
            $$('input[type="checkbox"][name="ids[]"]', form).forEach(function (box) {
                box.checked = master.checked;
            });
        });
    }

    /* ----------------------------------------------------- password meter */

    /**
     * Entropy-ish strength estimate.
     *
     * Deliberately advisory only: the server enforces the real policy in
     * Validator's `password` rule. This just tells the user where they stand
     * before they submit.
     */
    function scorePassword(value) {
        if (!value) { return { score: 0, label: '' }; }

        var score = 0;
        if (value.length >= 10) { score++; }
        if (value.length >= 14) { score++; }
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) { score++; }
        if (/\d/.test(value)) { score++; }
        if (/[^A-Za-z0-9]/.test(value)) { score++; }

        // Obvious patterns cancel out length.
        if (/^(.)\1+$/.test(value)) { score = 0; }
        if (/(password|12345|qwerty|admin|letmein|welcome)/i.test(value)) { score = Math.min(score, 1); }

        var labels = ['Very weak', 'Weak', 'Fair', 'Good', 'Strong', 'Very strong'];
        return { score: Math.min(score, 5), label: labels[Math.min(score, 5)] };
    }

    function initPasswordMeters() {
        $$('[data-password-meter]').forEach(function (form) {
            var input = $('[data-password-input]', form);
            var meter = $('[data-strength-meter]', form);
            if (!input || !meter) { return; }

            var fill = $('[data-strength-fill]', meter);
            var label = $('[data-strength-label]', meter);
            var colours = ['#dc2626', '#dc2626', '#d97706', '#d97706', '#16a34a', '#16a34a'];

            input.addEventListener('input', function () {
                var result = scorePassword(input.value);
                meter.hidden = input.value.length === 0;
                if (fill) {
                    fill.style.width = (result.score / 5 * 100) + '%';
                    fill.style.background = colours[result.score];
                }
                if (label) {
                    label.textContent = result.label;
                    label.style.color = colours[result.score];
                }
            });
        });
    }

    /* --------------------------------------------------------- live stats */

    /**
     * Server-Sent Events with polling fallback.
     *
     * SSE is tried first; if the browser lacks it, or the connection errors
     * repeatedly, we fall back to polling /dashboard/stats. Either way the
     * same applyStats() runs, so there is one code path for the UI.
     */
    function initLiveUpdates() {
        if (!$('[data-stat]')) { return; }

        var indicator = $('#live-indicator');
        var pollTimer = null;
        var source = null;
        var failures = 0;

        function setStatus(state) {
            if (!indicator) { return; }
            indicator.classList.toggle('is-live', state === 'live');
            indicator.classList.toggle('is-stale', state === 'polling');
            var label = $('.live-label', indicator);
            if (label) { label.textContent = state === 'live' ? 'live' : (state === 'polling' ? 'polling' : 'offline'); }
        }

        function applyStats(stats) {
            if (!stats) { return; }
            var devices = stats.devices || stats;
            Object.keys({ online: 1, direct: 1, relay: 1, pending: 1, total: 1 }).forEach(function (key) {
                var el = $('[data-stat="' + key + '"]');
                if (el && devices[key] !== undefined) {
                    var next = String(devices[key]);
                    if (el.textContent !== next) {
                        el.textContent = next;
                        el.classList.add('skeleton');
                        setTimeout(function () { el.classList.remove('skeleton'); }, 180);
                    }
                }
            });
        }

        function startPolling() {
            if (pollTimer) { return; }
            setStatus('polling');
            pollTimer = setInterval(function () {
                get(APP.statsUrl).then(applyStats).catch(function () { setStatus('offline'); });
            }, APP.pollMs || 5000);
        }

        function startStream() {
            if (!window.EventSource) { startPolling(); return; }

            try {
                source = new EventSource(APP.streamUrl, { withCredentials: true });
            } catch (e) {
                startPolling();
                return;
            }

            source.addEventListener('connected', function () {
                failures = 0;
                setStatus('live');
            });

            ['snapshot', 'device.online', 'device.offline', 'conn.changed', 'device.pending'].forEach(function (name) {
                source.addEventListener(name, function (event) {
                    try { applyStats(JSON.parse(event.data)); } catch (e) { /* malformed frame */ }
                });
            });

            // The server recycles the connection after ~60s by design; that is
            // a normal reconnect, not a failure.
            source.addEventListener('reconnect', function () {
                source.close();
                setTimeout(startStream, 1000);
            });

            source.onerror = function () {
                source.close();
                failures++;
                if (failures >= 3) {
                    // The host is probably buffering or proxying in a way that
                    // breaks SSE; stop fighting it and poll.
                    startPolling();
                    return;
                }
                setTimeout(startStream, 2000 * failures);
            };
        }

        startStream();

        window.addEventListener('beforeunload', function () {
            if (source) { source.close(); }
            if (pollTimer) { clearInterval(pollTimer); }
        });
    }

    /* ------------------------------------------------------ update driver */

    /**
     * Drives the resumable step machine from the browser.
     *
     * Each POST does one step server-side and tells us the next. That is what
     * keeps a long update within PHP's execution limits on shared hosting —
     * no single request has to download, dump, migrate and copy.
     */
    function initUpdater() {
        var card = $('#update-status-card');
        if (!card) { return; }

        var resultBox = $('#update-check-result');
        var progress = $('#update-progress');
        var fill = $('#update-progress-fill');
        var pct = $('#update-progress-pct');
        var stepLabel = $('#update-step-label');
        var logBox = $('#update-log');
        var track = progress ? $('.progress-track', progress) : null;

        function setProgress(percent, step) {
            if (progress) { progress.hidden = false; }
            if (fill) { fill.style.width = percent + '%'; }
            if (pct) { pct.textContent = percent + '%'; }
            if (stepLabel) { stepLabel.textContent = step; }
            if (track) { track.setAttribute('aria-valuenow', String(percent)); }
        }

        function appendLog(lines) {
            if (!logBox || !lines) { return; }
            logBox.textContent = lines.join('\n');
            logBox.scrollTop = logBox.scrollHeight;
        }

        function renderCheck(data) {
            if (!resultBox) { return; }
            resultBox.hidden = false;

            if (!data.configured) {
                resultBox.innerHTML = '<div class="alert alert-warning"><span class="alert-icon">!</span><span>'
                    + escapeHtml(data.message) + '</span></div>';
                return;
            }

            if (data.up_to_date) {
                resultBox.innerHTML = '<div class="alert alert-success"><span class="alert-icon">✓</span><span>'
                    + 'You are on the latest version (' + escapeHtml(data.current_version) + ').</span></div>';
                return;
            }

            var html = '<div class="alert alert-warning"><span class="alert-icon">!</span><div>';
            html += '<strong>Update available: ' + escapeHtml(data.current_version) + ' → '
                + escapeHtml(data.new_version) + '</strong>';

            if (data.commits_behind) {
                html += '<p class="text-muted">' + escapeHtml(data.commits_behind) + ' commit(s) behind '
                    + escapeHtml(data.repo) + '@' + escapeHtml(data.branch) + '</p>';
            }
            if (data.notes) {
                html += '<p>' + escapeHtml(data.notes) + '</p>';
            }
            if (data.breaking) {
                html += '<p class="text-danger"><strong>This release contains breaking changes.</strong> '
                    + 'Read the release notes before applying.</p>';
            }
            if (data.requirements && !data.requirements.ok) {
                html += '<p class="text-danger"><strong>This server does not meet the requirements:</strong><br>'
                    + data.requirements.failures.map(escapeHtml).join('<br>') + '</p>';
            }
            if (data.files) {
                html += '<p class="text-muted">Files: ' + data.files.added + ' added, '
                    + data.files.modified + ' modified, ' + data.files.removed + ' removed</p>';
            }
            if (data.migrations && data.migrations.length) {
                html += '<p class="text-muted">Migrations to run: '
                    + data.migrations.map(escapeHtml).join(', ') + '</p>';
            }
            if (data.signature && data.signature.required) {
                html += data.signature.valid
                    ? '<p class="text-muted">Manifest signature verified.</p>'
                    : '<p class="text-danger"><strong>Manifest signature is missing or invalid.</strong> '
                      + 'The update will be refused.</p>';
            }
            if (data.commits && data.commits.length) {
                html += '<details><summary>Commits</summary><ul class="text-sm">';
                data.commits.slice(0, 25).forEach(function (commit) {
                    html += '<li><code>' + escapeHtml(commit.sha.substring(0, 7)) + '</code> '
                        + escapeHtml(commit.message) + ' — ' + escapeHtml(commit.author) + '</li>';
                });
                html += '</ul></details>';
            }

            var blocked = (data.requirements && !data.requirements.ok)
                || (data.signature && data.signature.required && !data.signature.valid);

            html += '<div class="form-actions" style="margin-top:.75rem">'
                + '<button type="button" class="btn btn-primary" data-action="start-update"'
                + (blocked ? ' disabled' : '') + '>Update now</button></div>';
            html += '</div></div>';

            resultBox.innerHTML = html;
        }

        function runStep(updateId) {
            return post(APP.baseUrl + '/admin/updates/step', { update_id: updateId })
                .then(function (result) {
                    setProgress(result.progress, result.step + (result.next_step ? ' → ' + result.next_step : ''));
                    appendLog(result.log);

                    if (result.done) {
                        if (result.status === 'success') {
                            setProgress(100, 'Complete');
                            toast(result.message, 'success');
                            setTimeout(function () { window.location.reload(); }, 2500);
                        } else if (result.status === 'rolled_back') {
                            toast(result.message, 'warning');
                            setTimeout(function () { window.location.reload(); }, 4000);
                        } else {
                            toast(result.message, 'error');
                        }
                        return result;
                    }

                    return runStep(updateId);
                })
                .catch(function (error) {
                    toast(error.message, 'error');
                    if (stepLabel) { stepLabel.textContent = 'Failed'; }
                    throw error;
                });
        }

        document.addEventListener('click', function (event) {
            var check = event.target.closest('[data-action="check-update"]');
            if (check) {
                check.disabled = true;
                check.textContent = 'Checking…';
                get(APP.baseUrl + '/admin/updates/check?force=1')
                    .then(renderCheck)
                    .catch(function (error) { toast(error.message, 'error'); })
                    .finally(function () {
                        check.disabled = false;
                        check.textContent = 'Check for update';
                    });
                return;
            }

            var start = event.target.closest('[data-action="start-update"]');
            if (start) {
                if (!window.confirm('Start the update?\n\nA full backup is taken first, and the panel goes into '
                    + 'maintenance mode. If anything fails, the previous version is restored automatically.')) {
                    return;
                }
                start.disabled = true;
                setProgress(0, 'Starting…');

                post(APP.baseUrl + '/admin/updates/start', {})
                    .then(function (result) { return runStep(result.update_id); })
                    .catch(function (error) {
                        toast(error.message, 'error');
                        start.disabled = false;
                    });
                return;
            }

            var resume = event.target.closest('[data-action="resume-update"]');
            if (resume) {
                resume.disabled = true;
                runStep(parseInt(resume.getAttribute('data-update-id'), 10));
                return;
            }

            var rollback = event.target.closest('[data-action="rollback"]');
            if (rollback) {
                if (!window.confirm('Roll back to the previous version?\n\nFiles are restored from the update journal '
                    + 'and the database from its pre-update backup. Your configuration and uploads are not touched.')) {
                    return;
                }
                rollback.disabled = true;
                rollback.textContent = 'Rolling back…';

                post(APP.baseUrl + '/admin/updates/rollback', {
                    update_id: parseInt(rollback.getAttribute('data-update-id'), 10)
                }).then(function (result) {
                    if (result.ok) {
                        toast('Rolled back successfully.', 'success');
                        setTimeout(function () { window.location.reload(); }, 2000);
                    } else {
                        toast('Rollback incomplete — see the update detail page for recovery steps.', 'error');
                    }
                }).catch(function (error) {
                    toast(error.message, 'error');
                }).finally(function () {
                    rollback.disabled = false;
                    rollback.textContent = 'Roll back';
                });
                return;
            }

            var test = event.target.closest('[data-action="test-connection"]');
            if (test) {
                var form = $('#update-settings-form');
                var box = $('#connection-result');
                test.disabled = true;
                test.textContent = 'Testing…';

                post(APP.baseUrl + '/admin/updates/test-connection', {
                    repo_owner: form.repo_owner.value,
                    repo_name: form.repo_name.value,
                    branch: form.branch.value,
                    token: form.token.value
                }).then(function (result) {
                    if (box) {
                        box.hidden = false;
                        box.innerHTML = '<div class="alert alert-' + (result.ok ? 'success' : 'error') + '">'
                            + '<span class="alert-icon">' + (result.ok ? '✓' : '✕') + '</span><span>'
                            + escapeHtml(result.message) + '</span></div>';
                    }
                }).catch(function (error) {
                    toast(error.message, 'error');
                }).finally(function () {
                    test.disabled = false;
                    test.textContent = 'Test connection';
                });
            }
        });

        // If an update was already running when the page loaded, show where it is.
        var running = $('[data-action="resume-update"]');
        if (running) {
            get(APP.baseUrl + '/admin/updates/status?update_id=' + running.getAttribute('data-update-id'))
                .then(function (status) {
                    setProgress(status.progress, status.step);
                    appendLog(status.log);
                })
                .catch(function () { /* the card already shows the stored state */ });
        }
    }

    /* --------------------------------------------------------- dialogues */

    function initDialogs() {
        document.addEventListener('click', function (event) {
            var restore = event.target.closest('[data-action="open-restore"]');
            if (restore) {
                var id = restore.getAttribute('data-backup-id');
                var dialog = $('#restore-dialog');
                var form = $('#restore-form');
                var label = $('#restore-backup-label');
                if (dialog && form) {
                    form.action = APP.baseUrl + '/admin/backups/' + id + '/restore';
                    if (label) { label.textContent = '#' + id; }
                    dialog.showModal();
                }
                return;
            }

            // Deleting a device asks in a dialog, not a native confirm: see
            // the comment in views/devices/show.php for why a phone's double
            // tap used to be enough to lose one.
            var deleteDevice = event.target.closest('[data-action="confirm-delete-device"]');
            if (deleteDevice) {
                var delDialog = $('#delete-device-dialog');
                var delName = $('#delete-device-name');
                if (delDialog) {
                    if (delName) {
                        delName.textContent = deleteDevice.getAttribute('data-device-name') || 'This device';
                    }
                    delDialog.showModal();
                }
                return;
            }

            var editUser = event.target.closest('[data-action="edit-user"]');
            if (editUser) {
                var data;
                try { data = JSON.parse(editUser.getAttribute('data-user')); } catch (e) { return; }

                var userDialog = $('#user-dialog');
                var userForm = $('#user-form');
                if (userDialog && userForm) {
                    userForm.action = APP.baseUrl + '/settings/users/' + data.id;
                    userForm.name.value = data.name;
                    userForm.role.value = data.role;
                    if (userForm.status) { userForm.status.value = data.status; }
                    userDialog.showModal();
                }
            }
        });
    }

    /* ------------------------------------------------------------ TOTP QR */

    /**
     * Render the otpauth:// URI as a QR code.
     *
     * A tiny self-contained encoder rather than a CDN script: the CSP forbids
     * external scripts, and the secret must not be sent to a third party to be
     * turned into an image.
     */
    function initTotpQr() {
        var holder = $('#totp-qr');
        if (!holder) { return; }

        var uri = holder.getAttribute('data-otpauth');
        if (!uri) { return; }

        // Fall back to the typed secret when the encoder cannot represent the
        // URI — the manual entry path below it always works.
        try {
            holder.innerHTML = renderQrSvg(uri);
        } catch (e) {
            holder.innerHTML = '<p class="text-muted">Could not draw a QR code here — '
                + 'enter the key below by hand instead.</p>';
        }
    }

    /* ------------------------------------------------------------- start */

    function boot() {
        initTheme();
        initChrome();
        initPasswordMeters();
        initLiveUpdates();
        initUpdater();
        initDialogs();
        initTotpQr();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // Exposed for the QR module appended below.
    window.__appToast = toast;
})();
