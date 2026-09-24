<?php
/**
 * Two separate facts, shown separately.
 *
 * Whether the device is **online** is whether it is still heartbeating, and
 * nothing else: a green Online or a red Offline.
 *
 * How it reaches its peers is a fact about each PAIR, not about the device,
 * and is shown beside it as a small label — "direct" or "via server" — only
 * when the page has it (`$paths`, from DeviceLink::summaries()).
 *
 * The indicator used to carry a third state, "connecting", in blue, for a
 * device that was running and heartbeating and simply had no live WireGuard
 * session with any peer. It was a pair fact dressed up as the device's status,
 * and it was what an operator saw on two PCs that were working perfectly well.
 * A device with a live session to the server is Online, full stop.
 *
 * @var array<string,mixed> $device
 * @var array{direct:int,server:int}|null $paths
 */
declare(strict_types=1);

$online = \App\Models\Device::isOnline($device);

[$class, $dot, $label, $title] = $online
    ? ['conn-online', '🟢', 'Online', 'Online: the agent is running and in touch with the server.']
    : ['conn-offline', '🔴', 'Offline', 'Not heartbeating. The agent is not running, or this machine cannot reach the server.'];

$paths = isset($paths) && is_array($paths) ? $paths : null;
$pathLabel = '';
$pathTitle = '';
if ($online && $paths !== null) {
    $direct = (int) ($paths['direct'] ?? 0);
    $server = (int) ($paths['server'] ?? 0);

    if ($direct > 0 && $server === 0) {
        $pathLabel = 'direct';
        $pathTitle = 'Its peers are reached directly. No traffic passes through our server.';
    } elseif ($server > 0 && $direct === 0) {
        $pathLabel = 'via server';
        $pathTitle = 'Its peers are reached through our server. A direct path is tried in the background '
            . 'and taken over silently when it works.';
    } elseif ($direct > 0 && $server > 0) {
        $pathLabel = $direct . ' direct · ' . $server . ' via server';
        $pathTitle = 'Some peers are reached directly and some through our server.';
    }
}
?>
<span class="conn <?= e($class) ?>" title="<?= e($title) ?>">
    <span aria-hidden="true"><?= e($dot) ?></span>
    <span><?= e($label) ?></span>
    <?php if ($pathLabel !== ''): ?>
        <span class="conn-path text-muted" title="<?= e($pathTitle) ?>"><?= e($pathLabel) ?></span>
    <?php endif; ?>
</span>
