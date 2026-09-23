<?php
/**
 * One reachability answer, as a table cell.
 *
 * A partial rather than a closure returning HTML. The first version of this
 * built the markup in a function and echoed it unescaped, which the static
 * check refused — correctly, and for exactly the reason it exists: a helper
 * that returns HTML is a helper that will one day have a device name or an
 * error message from a customer's network concatenated into it.
 *
 * Here every value is escaped where it is printed, and the only unescaped
 * things are the literal tags.
 *
 * @var array<string,mixed>|null $probe
 */
$probe = $probe ?? null;
?>
<?php if ($probe === null): ?>
    <span class="text-muted">not tested</span>
<?php elseif ((string) $probe['state'] === 'pending'): ?>
    <span class="text-muted">testing…</span>
<?php elseif ((string) $probe['state'] === 'ok'): ?>
    <span class="badge badge-ok"><?= (int) $probe['latency_ms'] ?> ms</span>
    <?php
    $how = (string) ($probe['method'] ?? '');

    // The gateway for a shared range tests the REAL address, because the
    // mapping only applies to traffic arriving from the overlay — this
    // machine has no route for the mapped one. Saying so matters: a result
    // that surprises somebody gets argued with.
    $onLAN = str_ends_with($how, ' lan');
    $how = $onLAN ? substr($how, 0, -4) : $how;
    ?>
    <?php if ($how !== '' && $how !== 'icmp'): ?>
        <span class="text-muted">— no ping reply, but <?= e($how) ?> answered</span>
    <?php endif; ?>
    <?php if ($onLAN): ?>
        <span class="text-muted">— tested directly on the LAN, because this computer
            is the one sharing that range</span>
    <?php endif; ?>
    <?php if (($probe['answered_at'] ?? null) !== null): ?>
        <span class="text-muted">(<?= e(time_ago($probe['answered_at'])) ?>)</span>
    <?php endif; ?>
<?php else: ?>
    <span class="badge badge-danger">no answer</span>
    <span class="text-muted"><?= e((string) ($probe['error'] ?? '')) ?></span>
    <?php if (str_ends_with((string) ($probe['method'] ?? ''), ' lan')): ?>
        <span class="text-muted">(tested directly on the LAN)</span>
    <?php endif; ?>
    <?php if (($probe['answered_at'] ?? null) !== null): ?>
        <span class="text-muted">(<?= e(time_ago($probe['answered_at'])) ?>)</span>
    <?php endif; ?>
<?php endif; ?>
