<?php
/**
 * Connection indicator: 🟢 direct / 🟡 relay / 🔴 offline.
 *
 * @var array<string,mixed> $device
 */
declare(strict_types=1);

$type = (string) ($device['connection_type'] ?? 'offline');
[$class, $dot, $label] = match ($type) {
    'direct' => ['conn-direct', '🟢', 'Direct'],
    'relay'  => ['conn-relay', '🟡', 'Relay'],
    default  => ['conn-offline', '🔴', 'Offline'],
};
?>
<span class="conn <?= e($class) ?>" title="<?= e($type === 'relay'
    ? 'Going through a relay. The agent keeps retrying a direct path in the background.'
    : ($type === 'direct' ? 'Peer-to-peer. No traffic passes through our servers.' : 'Not connected.')) ?>">
    <span aria-hidden="true"><?= e($dot) ?></span>
    <span><?= e($label) ?></span>
    <?php if ($type !== 'offline' && !empty($device['latency_ms'])): ?>
        <span class="text-muted"><?= e($device['latency_ms']) ?> ms</span>
    <?php endif; ?>
</span>
