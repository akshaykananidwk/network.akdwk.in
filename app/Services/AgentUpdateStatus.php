<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentRelease;
use App\Models\Device;
use App\Models\UpdateSetting;

/**
 * Why a device is, or is not, being offered a newer agent.
 *
 * DESKTOP-EKH1Q30 sat on 1.9.5 through 1.9.6 and six development builds with
 * the self-update code installed and working. Nothing on the panel could say
 * why, because the panel only ever showed what the DEVICE reported about
 * itself — and the device had nothing to report: it asked, it was told there
 * was nothing for it, and it went back to sleep. "Up to date as of two
 * minutes ago" was true and useless.
 *
 * The answer is on the panel's side, and every one of these is silent today:
 *
 *   - no release row for this platform at all, because nothing published one.
 *     The agent binary is published by deploy/upgrade-edge.sh on the edge
 *     server, not by the panel's own update — so pressing Update Now upgrades
 *     the panel and leaves every agent on whatever it was running.
 *   - a release published UNSIGNED, which every agent correctly refuses. The
 *     publisher logs a warning nobody reads and carries on.
 *   - a rollout percentage that excludes this device's cohort.
 *   - a release whose version is not actually newer.
 *
 * This turns each of them into a sentence on the device page.
 */
final class AgentUpdateStatus
{
    /**
     * What the panel would offer this device, and why.
     *
     * @param array<string,mixed> $device
     * @return array{offered:bool,version:string,reason:string,detail:string}
     */
    public static function forDevice(array $device): array
    {
        $platform = (string) ($device['os'] ?? 'windows');
        $arch = (string) ($device['arch'] ?? 'amd64');
        $current = (string) ($device['agent_version'] ?? '');

        // The panel's channel, not a hard-coded 'stable'. A panel running
        // development builds was telling every device it managed that there
        // was nothing newer, because the agent sends no channel and the
        // default was stable.
        $channel = UpdateSetting::agentChannel();

        $release = AgentRelease::latestFor($channel, $platform, $arch, (string) ($device['device_uid'] ?? ''));

        if ($release === null) {
            // Is there anything at all for this platform, or is the cohort
            // the thing excluding it? The two need different actions.
            $any = AgentRelease::latestFor($channel, $platform, $arch);

            if ($any === null) {
                return [
                    'offered' => false,
                    'version' => '',
                    'reason'  => 'nothing published on the ' . $channel . ' channel',
                    'detail'  => 'No agent has been published for ' . $platform . '/' . $arch
                        . ' on the ' . $channel . ' channel. The agent binary is published by '
                        . 'deploy/upgrade-edge.sh on the edge server — updating the panel does not '
                        . 'publish one.',
                ];
            }

            return [
                'offered' => false,
                'version' => (string) $any['version'],
                'reason'  => 'held back by rollout',
                'detail'  => $any['version'] . ' is published at ' . (int) $any['rollout_percent']
                    . '% rollout and this device is not in that group yet. Raise it to 100% to '
                    . 'reach every device.',
            ];
        }

        if ((string) $release['signature'] === '') {
            return [
                'offered' => false,
                'version' => (string) $release['version'],
                'reason'  => 'published unsigned',
                'detail'  => $release['version'] . ' was published without a signature, and every '
                    . 'agent refuses an unsigned binary. The coordinator signing key was missing '
                    . 'when it was published — set it under Platform → Coordinator and publish again.',
            ];
        }

        if ($current !== '' && version_compare($current, (string) $release['version'], '>=')) {
            return [
                'offered' => false,
                'version' => (string) $release['version'],
                'reason'  => 'already current',
                'detail'  => 'This device is on ' . $current . ', and the newest agent on the '
                    . $channel . ' channel is ' . $release['version'] . '. Channel ' . $channel
                    . ': no release newer than ' . $current . '.',
            ];
        }

        return [
            'offered' => true,
            'version' => (string) $release['version'],
            'reason'  => 'offered',
            'detail'  => 'This device will be offered ' . $release['version']
                . ' on its next check, and checks every six hours or immediately when asked.',
        ];
    }

    /**
     * One line for the whole fleet: how many are on the newest agent.
     *
     * @return array{total:int,current:int,version:string,behind:list<string>}
     */
    public static function fleet(): array
    {
        $devices = Device::where(['deleted_at' => null]);
        $newest = '';
        $current = 0;
        $behind = [];

        foreach ($devices as $device) {
            $status = self::forDevice($device);
            if ($status['version'] !== '' && version_compare($status['version'], $newest, '>')) {
                $newest = $status['version'];
            }
        }

        foreach ($devices as $device) {
            $version = (string) ($device['agent_version'] ?? '');
            if ($newest !== '' && $version !== '' && version_compare($version, $newest, '>=')) {
                $current++;

                continue;
            }

            $behind[] = (string) $device['name'] . ' (' . ($version !== '' ? $version : 'unknown') . ')';
        }

        return [
            'total'   => count($devices),
            'current' => $current,
            'version' => $newest,
            'behind'  => $behind,
        ];
    }
}
