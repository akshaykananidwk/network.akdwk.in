<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use App\Models\AclRule;
use App\Models\Device;
use App\Models\Network;
use App\Models\NetworkRoute;

/**
 * Compiles ACL rows into the per-device filter set the agent enforces.
 *
 * The UI never enforces access: it only authors rules. Enforcement happens on
 * both endpoints, because a peer that can be reached at all can be probed, and
 * a filter that only exists on one side is a filter an attacker can choose not
 * to run.
 *
 * Two artefacts come out of here:
 *   buildPeerSet()  — who a device may talk to, plus the allowed_ips that the
 *                     WireGuard layer uses to decide what it will even decrypt
 *   compileFilters() — the port/protocol rules the agent applies above that
 */
final class AclService
{
    /**
     * Peers visible to one device, already narrowed by ACL.
     *
     * @return list<array<string,mixed>>
     */
    public static function buildPeerSet(int $networkId, int $deviceId): array
    {
        $network = Network::findOrFail($networkId);
        $self = Device::findOrFail($deviceId);
        $rules = AclRule::forNetwork($networkId);
        $peers = Device::peersFor($networkId, $deviceId);

        $selfTags = self::tagsOf($self);
        $defaultAllow = $network['acl_default_action'] !== 'deny';

        $out = [];
        foreach ($peers as $peer) {
            $peerTags = self::tagsOf($peer);

            // Both directions, and this device enforces both.
            //
            // Forward is what this device may send: rules whose source is us
            // and whose destination is the peer. Reverse is what it may
            // accept: rules whose source is the peer and whose destination is
            // us. Compiling only the forward direction would put every rule on
            // exactly one of the two machines, which is the same as trusting
            // that machine's agent — and the agent runs on hardware the
            // customer owns.
            //
            // With both compiled, a device whose own agent has been modified
            // still cannot reach a service the far end refuses to deliver.
            $forward = self::evaluate($rules, $self, $selfTags, $peer, $defaultAllow);
            $reverse = self::evaluate($rules, $peer, $peerTags, $self, $defaultAllow);

            // A link needs both ends to permit it. A one-sided allow would
            // hand out an address the other end will refuse to answer, which
            // looks to a customer like a broken tunnel rather than a rule.
            if (!$forward['allowed'] || !$reverse['allowed']) {
                continue;
            }

            $decision = [
                'allowed' => true,
                'filters' => self::mergeFilters($forward['filters'], $reverse['filters']),
            ];

            $allowedIps = [];
            if (!empty($peer['virtual_ip'])) {
                $allowedIps[] = $peer['virtual_ip'] . '/32';
            }
            // A gateway device also carries the subnets it routes for.
            if ((int) $peer['is_gateway'] === 1) {
                foreach (NetworkRoute::cidrsViaDevice((int) $peer['id']) as $cidr) {
                    $allowedIps[] = $cidr;
                }
            }

            $out[] = [
                'uid'          => $peer['device_uid'],
                'name'         => $peer['name'],
                'public_key'   => $peer['public_key'],
                'virtual_ip'   => $peer['virtual_ip'],
                'endpoint'     => $peer['last_endpoint'],
                'lan_endpoint' => $peer['last_lan_endpoint'],
                'allowed_ips'  => $allowedIps,
                'is_gateway'   => (int) $peer['is_gateway'] === 1,
                'filters'      => $decision['filters'],
                'last_seen_at' => $peer['last_seen_at'],
            ];
        }

        return $out;
    }

    /**
     * Evaluate the rule list for one (source, destination) pair.
     *
     * First match wins, in priority order. When no rule matches, the network's
     * default action decides. Port/protocol restrictions on matching allow
     * rules are collected so the agent can enforce them above the peer link.
     *
     * @param list<array<string,mixed>> $rules
     * @param array<string,mixed> $self
     * @param list<string> $selfTags
     * @param array<string,mixed> $peer
     * @return array{allowed:bool,filters:list<array<string,mixed>>,matched_rule:int|null}
     */
    public static function evaluate(array $rules, array $self, array $selfTags, array $peer, bool $defaultAllow): array
    {
        $peerTags = self::tagsOf($peer);
        $filters = [];

        foreach ($rules as $rule) {
            if (!self::matches($rule['src_type'], $rule['src_value'], $self, $selfTags)) {
                continue;
            }
            if (!self::matches($rule['dst_type'], $rule['dst_value'], $peer, $peerTags)) {
                continue;
            }

            if ($rule['action'] === 'deny') {
                // A deny that names no ports blocks the peer outright; one that
                // names ports is a narrower rule the agent applies per packet.
                if ($rule['protocol'] === 'any' && $rule['port_from'] === null) {
                    return ['allowed' => false, 'filters' => [], 'matched_rule' => (int) $rule['id']];
                }
                $filters[] = self::toFilter($rule);
                continue;
            }

            if ($rule['protocol'] === 'any' && $rule['port_from'] === null) {
                return ['allowed' => true, 'filters' => $filters, 'matched_rule' => (int) $rule['id']];
            }
            $filters[] = self::toFilter($rule);
        }

        if ($filters !== []) {
            return ['allowed' => true, 'filters' => $filters, 'matched_rule' => null];
        }

        return ['allowed' => $defaultAllow, 'filters' => [], 'matched_rule' => null];
    }

    /**
     * @param array<string,mixed> $device
     * @param list<string> $tags
     */
    private static function matches(string $type, ?string $value, array $device, array $tags): bool
    {
        return match ($type) {
            'any'    => true,
            'device' => (string) $device['device_uid'] === (string) $value
                        || (string) $device['id'] === (string) $value,
            'tag'    => in_array(strtolower((string) $value), $tags, true),
            'cidr'   => $device['virtual_ip'] !== null
                        && self::ipInCidr((string) $device['virtual_ip'], (string) $value),
            default  => false,
        };
    }

    /**
     * Combine the two directions' filters, keeping each rule once.
     *
     * A rule that names this device on both sides — "any to any on tcp/22" —
     * is compiled by both passes and must not be applied twice: a duplicate
     * deny is harmless, but a duplicate allow inflates the count that decides
     * whether *any* allow rules exist, which changes the default.
     *
     * @param list<array<string,mixed>> $forward
     * @param list<array<string,mixed>> $reverse
     * @return list<array<string,mixed>>
     */
    private static function mergeFilters(array $forward, array $reverse): array
    {
        $byRule = [];
        foreach ([...$forward, ...$reverse] as $filter) {
            $byRule[(int) $filter['rule_id']] = $filter;
        }

        ksort($byRule);

        return array_values($byRule);
    }

    /** @param array<string,mixed> $rule @return array<string,mixed> */
    private static function toFilter(array $rule): array
    {
        return [
            'action'    => $rule['action'],
            'protocol'  => $rule['protocol'],
            'port_from' => $rule['port_from'] !== null ? (int) $rule['port_from'] : null,
            'port_to'   => $rule['port_to'] !== null ? (int) $rule['port_to'] : ($rule['port_from'] !== null ? (int) $rule['port_from'] : null),
            'rule_id'   => (int) $rule['id'],
        ];
    }

    /** @param array<string,mixed> $device @return list<string> */
    private static function tagsOf(array $device): array
    {
        $tags = $device['tags_json'] ?? null;
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            $tags = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($tags)) {
            return [];
        }

        return array_values(array_map(static fn ($t): string => strtolower((string) $t), $tags));
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || !ctype_digit($bits)) {
            return false;
        }
        $prefix = (int) $bits;
        if ($prefix < 0 || $prefix > 32) {
            return false;
        }
        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF;

        return ((int) $ipLong & $mask) === ((int) $subnetLong & $mask);
    }

    // ------------------------------------------------------------- authoring

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> the created rule
     */
    public static function createRule(int $networkId, array $input): array
    {
        $network = Network::findOrFail($networkId);
        $data = self::validateRule($input);

        $data['tenant_id'] = (int) $network['tenant_id'];
        $data['network_id'] = $networkId;
        $data['priority'] = isset($input['priority']) && $input['priority'] !== ''
            ? (int) $input['priority']
            : AclRule::nextPriority($networkId);

        $id = AclRule::create($data);
        Network::bumpRevision($networkId);

        AuditService::log('acl.create', 'acl_rule', $id, null, $data);

        return AclRule::findOrFail($id);
    }

    /** @param array<string,mixed> $input */
    public static function updateRule(int $ruleId, array $input): array
    {
        $before = AclRule::findOrFail($ruleId);
        $data = self::validateRule(array_merge($before, $input));

        if (isset($input['priority']) && $input['priority'] !== '') {
            $data['priority'] = (int) $input['priority'];
        }
        if (array_key_exists('enabled', $input)) {
            $data['enabled'] = (int) (bool) $input['enabled'];
        }

        AclRule::update($ruleId, $data);
        Network::bumpRevision((int) $before['network_id']);

        $after = AclRule::findOrFail($ruleId);
        AuditService::logChange('acl.update', 'acl_rule', $ruleId, $before, $after);

        return $after;
    }

    public static function deleteRule(int $ruleId): void
    {
        $rule = AclRule::findOrFail($ruleId);
        AclRule::delete($ruleId);
        Network::bumpRevision((int) $rule['network_id']);

        AuditService::log('acl.delete', 'acl_rule', $ruleId, $rule, null);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws ValidationException
     */
    private static function validateRule(array $input): array
    {
        $errors = [];

        $srcType = (string) ($input['src_type'] ?? 'any');
        $dstType = (string) ($input['dst_type'] ?? 'any');
        $protocol = (string) ($input['protocol'] ?? 'any');
        $action = (string) ($input['action'] ?? 'allow');

        if (!in_array($srcType, ['device', 'tag', 'cidr', 'any'], true)) {
            $errors['src_type'] = 'Invalid source type.';
        }
        if (!in_array($dstType, ['device', 'tag', 'cidr', 'any'], true)) {
            $errors['dst_type'] = 'Invalid destination type.';
        }
        if (!in_array($protocol, ['tcp', 'udp', 'icmp', 'any'], true)) {
            $errors['protocol'] = 'Protocol must be tcp, udp, icmp or any.';
        }
        if (!in_array($action, ['allow', 'deny'], true)) {
            $errors['action'] = 'Action must be allow or deny.';
        }

        $srcValue = $srcType === 'any' ? null : trim((string) ($input['src_value'] ?? ''));
        $dstValue = $dstType === 'any' ? null : trim((string) ($input['dst_value'] ?? ''));

        if ($srcType !== 'any' && ($srcValue === null || $srcValue === '')) {
            $errors['src_value'] = 'A source value is required for this source type.';
        }
        if ($dstType !== 'any' && ($dstValue === null || $dstValue === '')) {
            $errors['dst_value'] = 'A destination value is required for this destination type.';
        }
        if ($srcType === 'cidr' && $srcValue !== null && $srcValue !== '' && !self::looksLikeCidr($srcValue)) {
            $errors['src_value'] = 'Source must be a CIDR such as 10.50.1.0/24.';
        }
        if ($dstType === 'cidr' && $dstValue !== null && $dstValue !== '' && !self::looksLikeCidr($dstValue)) {
            $errors['dst_value'] = 'Destination must be a CIDR such as 10.50.1.0/24.';
        }

        $portFrom = ($input['port_from'] ?? null) === '' ? null : $input['port_from'];
        $portTo = ($input['port_to'] ?? null) === '' ? null : $input['port_to'];
        $portFrom = $portFrom === null ? null : (int) $portFrom;
        $portTo = $portTo === null ? null : (int) $portTo;

        if ($portFrom !== null && ($portFrom < 1 || $portFrom > 65535)) {
            $errors['port_from'] = 'Port must be between 1 and 65535.';
        }
        if ($portTo !== null && ($portTo < 1 || $portTo > 65535)) {
            $errors['port_to'] = 'Port must be between 1 and 65535.';
        }
        if ($portFrom !== null && $portTo !== null && $portTo < $portFrom) {
            $errors['port_to'] = 'The end of the port range must not be below its start.';
        }
        if ($portFrom !== null && $protocol === 'icmp') {
            $errors['protocol'] = 'ICMP has no ports; leave the port range empty.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'description' => isset($input['description']) ? substr(trim((string) $input['description']), 0, 190) : null,
            'src_type'    => $srcType,
            'src_value'   => $srcValue,
            'dst_type'    => $dstType,
            'dst_value'   => $dstValue,
            'protocol'    => $protocol,
            'port_from'   => $portFrom,
            'port_to'     => $portTo ?? $portFrom,
            'action'      => $action,
            'enabled'     => (int) (bool) ($input['enabled'] ?? 1),
        ];
    }

    private static function looksLikeCidr(string $value): bool
    {
        if (!str_contains($value, '/')) {
            return false;
        }
        [$ip, $bits] = explode('/', $value, 2);

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && ctype_digit($bits)
            && (int) $bits >= 0
            && (int) $bits <= 32;
    }
}
