<?php

declare(strict_types=1);

/**
 * English strings.
 *
 * Flat keys, "section.key". A missing key renders as the key itself, so a gap
 * is visible in the UI rather than silently blank.
 */

return [
    // Navigation
    'nav.dashboard'   => 'Dashboard',
    'nav.networks'    => 'Networks',
    'nav.devices'     => 'Devices',
    'nav.users'       => 'Users & roles',
    'nav.api_keys'    => 'API keys',
    'nav.audit'       => 'Audit log',
    'nav.customers'   => 'Customers',
    'nav.relays'      => 'Relays',
    'nav.updates'     => 'Updates',
    'nav.backups'     => 'Backups',
    'nav.account'     => 'My account',
    'nav.sign_out'    => 'Sign out',
    'nav.platform'    => 'Platform',

    // Authentication
    'auth.sign_in'          => 'Sign in',
    'auth.email'            => 'Email address',
    'auth.password'         => 'Password',
    'auth.forgot'           => 'Forgot?',
    'auth.invalid'          => 'Those credentials do not match our records.',
    'auth.signed_out'       => 'You have been signed out.',
    'auth.2fa_title'        => 'Two-factor authentication',
    'auth.2fa_prompt'       => 'Enter the six-digit code from your authenticator app.',
    'auth.2fa_invalid'      => 'That code was not correct.',
    'auth.reset_sent'       => 'If that address has an account, a reset link is on its way.',
    'auth.reset_expired'    => 'That reset link is invalid or has expired.',
    'auth.password_changed' => 'Your password has been changed.',

    // Common actions
    'action.save'    => 'Save',
    'action.cancel'  => 'Cancel',
    'action.delete'  => 'Delete',
    'action.create'  => 'Create',
    'action.edit'    => 'Edit',
    'action.search'  => 'Search',
    'action.filter'  => 'Filter',
    'action.export'  => 'Export',
    'action.copy'    => 'Copy',
    'action.copied'  => 'Copied',
    'action.approve' => 'Approve',
    'action.revoke'  => 'Revoke',
    'action.disable' => 'Disable',

    // Networks
    'network.title'        => 'Networks',
    'network.new'          => 'New network',
    'network.name'         => 'Name',
    'network.cidr'         => 'Address range',
    'network.cidr_hint'    => 'Private space only (10/8, 172.16/12 or 192.168/16), between /16 and /30.',
    'network.created'      => 'Network created.',
    'network.updated'      => 'Network updated.',
    'network.archived'     => 'Network archived.',
    'network.join_code'    => 'Join code',
    'network.empty_title'  => 'No networks yet',
    'network.empty_body'   => 'A network is a private address range shared by your devices.',

    // Devices
    'device.title'        => 'Devices',
    'device.pending'      => 'Awaiting approval',
    'device.approved'     => 'Device approved.',
    'device.revoked'      => 'Device revoked.',
    'device.direct'       => 'Direct',
    'device.relay'        => 'Relay',
    'device.offline'      => 'Offline',
    'device.direct_hint'  => 'Peer-to-peer. No traffic passes through our servers.',
    'device.relay_hint'   => 'Going through a relay. The agent keeps retrying a direct path.',
    'device.empty_title'  => 'No devices yet',
    'device.empty_body'   => 'Run the install command on a machine to add the first one.',

    // Updates
    'update.title'          => 'System updates',
    'update.check'          => 'Check for update',
    'update.available'      => 'Update available',
    'update.up_to_date'     => 'You are on the latest version.',
    'update.apply'          => 'Update now',
    'update.rollback'       => 'Roll back',
    'update.history'        => 'Update history',
    'update.breaking'       => 'This release contains breaking changes.',
    'update.confirm'        => 'A full backup is taken first. If anything fails, the previous version is restored automatically.',
    'update.token_hint'     => 'Stored encrypted. Never displayed, logged or sent back to this page.',

    // Errors
    'error.403'   => 'You do not have permission to do that.',
    'error.404'   => 'The requested resource was not found.',
    'error.429'   => 'Too many requests. Please slow down.',
    'error.500'   => 'Something went wrong on our side. The error has been logged.',
    'error.csrf'  => 'Your session token is missing or expired. Reload the page and try again.',

    // Plan limits
    'limit.devices'  => 'Your plan allows :limit devices and :used are already in use.',
    'limit.networks' => 'Your plan allows :limit network(s).',
    'limit.users'    => 'Your plan allows :limit user(s).',
    'limit.upgrade'  => 'Upgrade to raise the limit.',
];
