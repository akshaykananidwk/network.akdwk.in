<?php
/**
 * Two separate facts, shown separately.
 *
 * Whether the device is **online** is whether it is still heartbeating, and
 * nothing else. How its peers are **reached** is direct, through a relay, or
 * not yet at all.
 *
 * They used to be one indicator, and the result was two machines that were
 * both running, both heartbeating every ten seconds and both reporting their
 * endpoints, shown as red dots labelled Offline — because they had not found
 * each other yet. An administrator cannot act on that: "switched off" and
 * "these two have not connected" need completely different responses.
 *
 * @var array<string,mixed> $device
 */
declare(strict_types=1);

$type = (string) ($device['connection_type'] ?? 'offline');

// The heartbeat is the authority on liveness. `offline` in the column is
// written only by the staleness sweep, so it already means "stopped
// heartbeating" — but a device whose row says otherwise and has not been seen
// for a while is offline too, and the sweep may not have run yet.
$lastSeen = isset($device['last_seen_at']) ? strtotime((string) $device['last_seen_at'] . ' UTC') : false;
$staleAfter = \App\Models\Device::OFFLINE_AFTER_SECONDS;
$online = $type !== 'offline' && $lastSeen !== false && (time() - $lastSeen) < $staleAfter;

[$class, $dot, $label, $title] = $online
    ? match ($type) {
        'direct' => ['conn-direct', '🟢', 'Online', 'Online, and reaching its peers directly. No traffic passes through our servers.'],
        'relay'  => ['conn-relay', '🟡', 'Online', 'Online, reaching its peers through a relay. The agent keeps retrying a direct path in the background.'],
        default  => ['conn-connecting', '🔵', 'Online', 'Online and heartbeating. It has not established a path to a peer yet.'],
    }
    : ['conn-offline', '🔴', 'Offline', 'Not heartbeating. The agent is not running, or this machine cannot reach the panel.'];

$path = $online
    ? match ($type) {
        'direct' => 'direct',
        'relay'  => 'relay',
        default  => 'connecting',
    }
    : '';

// "Connecting" is a hopeful word, and on one office Wi-Fi it was wrong for an
// entire afternoon: the laptop's announcements reached the coordinator every
// few seconds and not one reply ever came back, while this indicator said it
// was still getting there. The agent now says so itself — if it has reported
// that nothing answers it, the dot says that instead of implying patience
// will fix it.
if ($online && $path === 'connecting') {
    $reported = json_decode((string) ($device['problems_json'] ?? ''), true);
    foreach (is_array($reported) ? $reported : [] as $problem) {
        if ((string) ($problem['code'] ?? '') === 'discovery.no_reply') {
            $class = 'conn-blocked';
            $dot   = "\u{1F7E0}";
            $path  = 'blocked';
            $title = 'Online and heartbeating, but nothing answers its announcements — '
                . 'something on that network is dropping the replies. See the problem below.';
            break;
        }
    }
}
?>
<span class="conn <?= e($class) ?>" title="<?= e($title) ?>">
    <span aria-hidden="true"><?= e($dot) ?></span>
    <span><?= e($label) ?></span>
    <?php if ($path !== ''): ?>
        <span class="conn-path text-muted"><?= e($path) ?></span>
    <?php endif; ?>
    <?php if ($online && !empty($device['latency_ms'])): ?>
        <span class="text-muted"><?= e($device['latency_ms']) ?> ms</span>
    <?php endif; ?>
</span>
