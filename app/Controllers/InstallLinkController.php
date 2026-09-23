<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\EdgeRelease;

/**
 * One link a supplier can send, with the join code already in it.
 *
 * The alternative is what this replaces: a WhatsApp message with a file, a
 * separate message with a code, and a telephone call when the customer types
 * the code with a lowercase L in it. This is one address —
 *
 *     https://network.akdwk.in/join/ABCD-1234
 *
 * — that shows a download button, the code in large type next to it, and three
 * sentences. Nothing to sign in to, nothing to configure.
 *
 * Unauthenticated, like the download it wraps, and for the same reason. The
 * code in the link is the credential, and it is short-lived, limited and
 * revocable; putting a sign-in page in front of the customer's install would
 * cost far more than it protects. The page says nothing about the network the
 * code belongs to — not its name, not its customer, not how many devices are
 * on it — so a link that reaches the wrong person discloses a code they were
 * already being sent anyway.
 */
final class InstallLinkController extends Controller
{
    /**
     * The install page for a join code.
     *
     * The code is not checked against the database here, deliberately. A
     * customer who was sent an expired code must be told that by the
     * installer, in the installer's own words, at the moment they use it —
     * not by a web page that has to explain what a join code is first. And a
     * page that answered "that code is not valid" would answer it for anyone
     * who asked, which is a way to find valid codes.
     */
    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        return $this->view('install/join', [
            'title'     => 'Install',
            'code'      => $this->tidy((string) ($params['code'] ?? '')),
            'available' => EdgeRelease::current('windows-setup') !== null,
        ]);
    }

    /**
     * Accept what a person can actually type.
     *
     * Codes get read aloud down a telephone, retyped from a photograph of a
     * screen and pasted with a trailing space from WhatsApp. Spaces and case
     * are not the customer's mistake to pay for.
     */
    private function tidy(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = (string) preg_replace('/[^A-Z0-9-]/', '', $code);

        return substr($code, 0, 64);
    }
}
