<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\UpdateException;
use App\Services\EdgeRelease;

/**
 * The edge servers' side of an upgrade.
 *
 * Authenticated with the coordinator's shared secret — the same HMAC the
 * coordinator already signs every call with — because the edge is the only
 * thing that has a legitimate reason to ask these questions, and it already
 * holds that secret. No new credential, and nothing here is public.
 *
 * The direction matters. The edge asks the panel what to build and then tells
 * it what it built; the panel never reaches into the edge. Giving the panel
 * the ability to run commands on the machine that holds the coordinator's
 * private key would mean one compromise of a public PHP application taking the
 * data plane with it, and keeping those apart is the point of R6.
 */
final class EdgeController
{
    /**
     * What the edge should be running.
     *
     * The commit, not just the version: an edge that builds this ref is
     * building the same code the panel is running rather than whatever is
     * newest on the branch.
     */
    public function release(Request $request): Response
    {
        return Response::api(EdgeRelease::target());
    }

    /** What the edge is running, as reported by the upgrade script. */
    public function report(Request $request): Response
    {
        EdgeRelease::record($request->all());

        return Response::api(EdgeRelease::status());
    }

    /**
     * One chunk of an installer built on the edge.
     *
     * Chunked and base64: a 14 MB body does not reliably fit inside the
     * `post_max_size` a shared host allows, and the coordinator's signature
     * covers the raw body, which the framework only captures for JSON.
     */
    public function artifact(Request $request): Response
    {
        $input = $request->all();

        $encoded = (string) ($input['data'] ?? '');
        $bytes = base64_decode($encoded, true);

        if ($bytes === false) {
            return Response::apiError('The chunk is not base64.', 400, 'bad_chunk');
        }

        try {
            $result = EdgeRelease::receiveChunk(
                (string) ($input['kind'] ?? ''),
                (string) ($input['version'] ?? ''),
                strtolower((string) ($input['sha256'] ?? '')),
                (int) ($input['offset'] ?? -1),
                (int) ($input['total'] ?? 0),
                $bytes,
                (string) ($input['edge_key'] ?? ''),
                (string) ($input['edge_signature'] ?? '')
            );
        } catch (UpdateException $e) {
            Logger::warning('update', 'Edge artefact chunk refused', ['error' => $e->getMessage()]);

            return Response::apiError($e->getMessage(), 422, 'bad_artifact');
        }

        return Response::api($result);
    }
}
