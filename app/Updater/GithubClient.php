<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Config;
use App\Core\Logger;
use App\Core\UpdateException;

/**
 * Minimal GitHub REST v3 client for the updater.
 *
 * Only the four calls §9.2 needs are implemented, against the documented
 * endpoints:
 *   GET /repos/{o}/{r}                      — repository + default branch
 *   GET /repos/{o}/{r}/commits?sha=&per_page — latest commit on a branch
 *   GET /repos/{o}/{r}/compare/{base}...{head} — ahead_by, commits, files
 *   GET /repos/{o}/{r}/contents/{path}?ref=  — raw update.json at a commit
 *   GET /repos/{o}/{r}/zipball/{ref}         — source archive
 *
 * The token is held only in memory and only ever leaves in an Authorization
 * header. Errors are passed through Logger::redactString() before they are
 * stored or shown, so a token echoed back by a proxy cannot reach a log.
 */
final class GithubClient
{
    private string $apiBase;
    private int $timeout;
    private string $userAgent;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly ?string $token = null,
    ) {
        $this->apiBase = rtrim((string) Config::get('update.github_api', 'https://api.github.com'), '/');
        $this->timeout = (int) Config::get('update.timeout_seconds', 120);
        $this->userAgent = (string) Config::get('update.user_agent', 'AK-Connect-Updater');
    }

    /**
     * Validate credentials, repository and branch in one go.
     *
     * @return array{ok:bool,message:string,repo?:array<string,mixed>,latest_commit?:array<string,mixed>}
     */
    public function testConnection(string $branch): array
    {
        try {
            $repo = $this->request('GET', sprintf('/repos/%s/%s', $this->owner, $this->repo));
        } catch (UpdateException $e) {
            $message = $e->getMessage();

            // GitHub answers 404 rather than 403 for a private repository the
            // caller cannot see, so "not found" usually means "no token".
            if (str_contains($message, 'HTTP 404') && ($this->token === null || $this->token === '')) {
                $message .= ' — if this repository is private, add an access token with the "repo" scope.';
            }
            if (str_contains($message, 'HTTP 401')) {
                $message .= ' — the access token was rejected. Check it has not expired or been revoked.';
            }

            return ['ok' => false, 'message' => $message];
        }

        try {
            $commit = $this->latestCommit($branch);
        } catch (UpdateException $e) {
            return [
                'ok'      => false,
                'message' => sprintf('Repository reached, but branch "%s" could not be read: %s', $branch, $e->getMessage()),
                'repo'    => $this->summariseRepo($repo),
            ];
        }

        return [
            'ok'      => true,
            'message' => sprintf(
                'Connected to %s/%s (%s). Latest commit on %s: %s — %s',
                $this->owner,
                $this->repo,
                $repo['private'] ?? false ? 'private' : 'public',
                $branch,
                substr((string) $commit['sha'], 0, 7),
                $commit['message']
            ),
            'repo'          => $this->summariseRepo($repo),
            'latest_commit' => $commit,
        ];
    }

    /**
     * @return array{sha:string,message:string,author:string,date:string,url:string}
     */
    public function latestCommit(string $branch): array
    {
        $commits = $this->request('GET', sprintf(
            '/repos/%s/%s/commits?sha=%s&per_page=1',
            $this->owner,
            $this->repo,
            rawurlencode($branch)
        ));

        if (!is_array($commits) || $commits === [] || !isset($commits[0]['sha'])) {
            throw new UpdateException('No commits found on branch "' . $branch . '".');
        }

        return $this->normaliseCommit($commits[0]);
    }

    /**
     * Compare two commits.
     *
     * @return array{ahead_by:int,behind_by:int,status:string,commits:list<array<string,mixed>>,files:array{added:int,modified:int,removed:int,list:list<array<string,mixed>>},total_size:int}
     */
    public function compare(string $base, string $head): array
    {
        $result = $this->request('GET', sprintf(
            '/repos/%s/%s/compare/%s...%s',
            $this->owner,
            $this->repo,
            rawurlencode($base),
            rawurlencode($head)
        ));

        $files = ['added' => 0, 'modified' => 0, 'removed' => 0, 'list' => []];
        $totalSize = 0;

        foreach ((array) ($result['files'] ?? []) as $file) {
            $status = (string) ($file['status'] ?? 'modified');
            $key = match ($status) {
                'added'   => 'added',
                'removed' => 'removed',
                default   => 'modified',
            };
            $files[$key]++;
            $totalSize += (int) ($file['changes'] ?? 0);
            $files['list'][] = [
                'filename'  => (string) ($file['filename'] ?? ''),
                'status'    => $status,
                'additions' => (int) ($file['additions'] ?? 0),
                'deletions' => (int) ($file['deletions'] ?? 0),
            ];
        }

        return [
            'ahead_by'   => (int) ($result['ahead_by'] ?? 0),
            'behind_by'  => (int) ($result['behind_by'] ?? 0),
            'status'     => (string) ($result['status'] ?? 'unknown'),
            'commits'    => array_map($this->normaliseCommit(...), (array) ($result['commits'] ?? [])),
            'files'      => $files,
            'total_size' => $totalSize,
        ];
    }

    /**
     * Fetch a file's contents at a specific ref.
     *
     * Uses the contents API with a raw Accept header rather than raw.github-
     * usercontent.com, so a private repository works with the same token and
     * no second host needs to be reachable.
     *
     * @return string|null null when the file does not exist at that ref
     */
    public function fileAtRef(string $path, string $ref): ?string
    {
        try {
            $body = $this->requestRaw('GET', sprintf(
                '/repos/%s/%s/contents/%s?ref=%s',
                $this->owner,
                $this->repo,
                implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/')))),
                rawurlencode($ref)
            ), ['Accept: application/vnd.github.raw']);
        } catch (UpdateException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }

        // Belt and braces: if something along the way rewrote Accept and we
        // got the metadata envelope, decode it rather than handing the caller
        // JSON it will fail to parse as the file.
        $decoded = json_decode($body, true);
        if (is_array($decoded) && ($decoded['encoding'] ?? '') === 'base64' && isset($decoded['content'])) {
            $raw = base64_decode((string) $decoded['content'], true);

            return $raw === false ? $body : $raw;
        }

        return $body;
    }

    /**
     * Download the repository archive at a ref.
     *
     * Streamed straight to disk: a repository is easily tens of megabytes and
     * buffering it in memory would break on a modest memory_limit.
     *
     * @return int bytes written
     */
    public function downloadZipball(string $ref, string $destination): int
    {
        $url = sprintf('%s/repos/%s/%s/zipball/%s', $this->apiBase, $this->owner, $this->repo, rawurlencode($ref));

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new UpdateException('Cannot create download directory: ' . $directory, 'DOWNLOAD');
        }

        $handle = fopen($destination, 'wb');
        if ($handle === false) {
            throw new UpdateException('Cannot open download target for writing: ' . $destination, 'DOWNLOAD');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);
            throw new UpdateException('Could not initialise the HTTP client.', 'DOWNLOAD');
        }

        curl_setopt_array($curl, [
            CURLOPT_FILE           => $handle,
            CURLOPT_FOLLOWLOCATION => true,   // the zipball endpoint 302s to a CDN
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => max(300, $this->timeout),
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $this->headers(),
            CURLOPT_USERAGENT      => $this->userAgent,
        ]);

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($ok === false || $status >= 400) {
            @unlink($destination);
            throw new UpdateException(
                sprintf('Archive download failed (HTTP %d) %s', $status, Logger::redactString($error)),
                'DOWNLOAD'
            );
        }

        $size = (int) filesize($destination);
        if ($size < 1024) {
            @unlink($destination);
            throw new UpdateException('Downloaded archive is implausibly small (' . $size . ' bytes).', 'DOWNLOAD');
        }

        Logger::info('update', 'Archive downloaded', ['ref' => $ref, 'bytes' => $size]);

        return $size;
    }

    /** Remaining API quota, so the UI can explain a sudden 403. */
    public function rateLimit(): array
    {
        try {
            $data = $this->request('GET', '/rate_limit');
            $core = $data['resources']['core'] ?? [];

            return [
                'limit'     => (int) ($core['limit'] ?? 0),
                'remaining' => (int) ($core['remaining'] ?? 0),
                'reset_at'  => isset($core['reset']) ? gmdate('c', (int) $core['reset']) : null,
            ];
        } catch (UpdateException) {
            return ['limit' => 0, 'remaining' => 0, 'reset_at' => null];
        }
    }

    // ------------------------------------------------------------- internals

    /**
     * @param list<string> $extraHeaders
     * @return array<mixed>
     */
    private function request(string $method, string $path, array $extraHeaders = []): array
    {
        $body = $this->requestRaw($method, $path, $extraHeaders);
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new UpdateException('GitHub returned a response that could not be parsed as JSON.');
        }

        return $decoded;
    }

    /** @param list<string> $extraHeaders */
    private function requestRaw(string $method, string $path, array $extraHeaders = []): string
    {
        $url = $this->apiBase . $path;

        $curl = curl_init($url);
        if ($curl === false) {
            throw new UpdateException('Could not initialise the HTTP client.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => self::mergeHeaders($this->headers(), $extraHeaders),
            CURLOPT_USERAGENT      => $this->userAgent,
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new UpdateException('GitHub request failed: ' . Logger::redactString($error));
        }

        $body = (string) $response;

        if ($status >= 400) {
            $decoded = json_decode($body, true);
            $message = is_array($decoded) && isset($decoded['message']) ? (string) $decoded['message'] : substr($body, 0, 200);

            throw new UpdateException(sprintf('HTTP %d from GitHub: %s', $status, Logger::redactString($message)));
        }

        return $body;
    }

    /**
     * Merge header lists so a caller's header replaces the default of the
     * same name rather than being sent alongside it.
     *
     * This matters: fetching a file's raw contents needs
     * "Accept: application/vnd.github.raw". Appending it to the default
     * "Accept: application/vnd.github+json" makes GitHub answer with the
     * metadata envelope — name, path, base64 content — instead of the file,
     * and the manifest then parses as "missing a version".
     *
     * @param list<string> $defaults
     * @param list<string> $overrides
     * @return list<string>
     */
    private static function mergeHeaders(array $defaults, array $overrides): array
    {
        $byName = [];

        foreach ([...$defaults, ...$overrides] as $header) {
            $name = strtolower(trim(strtok($header, ':') ?: $header));
            $byName[$name] = $header;
        }

        return array_values($byName);
    }

    /** @return list<string> */
    private function headers(): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        if ($this->token !== null && $this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        return $headers;
    }

    /**
     * @param array<string,mixed> $commit
     * @return array{sha:string,message:string,author:string,date:string,url:string}
     */
    private function normaliseCommit(array $commit): array
    {
        $detail = $commit['commit'] ?? [];
        $message = (string) ($detail['message'] ?? '');

        return [
            'sha'     => (string) ($commit['sha'] ?? ''),
            'message' => trim(strtok($message, "\n") ?: $message),
            'author'  => (string) ($detail['author']['name'] ?? ($commit['author']['login'] ?? 'unknown')),
            'date'    => (string) ($detail['author']['date'] ?? ''),
            'url'     => (string) ($commit['html_url'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $repo @return array<string,mixed> */
    private function summariseRepo(array $repo): array
    {
        return [
            'full_name'      => (string) ($repo['full_name'] ?? ''),
            'private'        => (bool) ($repo['private'] ?? false),
            'default_branch' => (string) ($repo['default_branch'] ?? 'main'),
            'size_kb'        => (int) ($repo['size'] ?? 0),
            'pushed_at'      => (string) ($repo['pushed_at'] ?? ''),
        ];
    }
}
